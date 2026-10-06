<?php
/**
 * Zapier catch-hook delivery.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a lead to a Zapier catch hook.
 *
 * THE WEBHOOK URL IS A BEARER SECRET. It contains the hook id, and anyone
 * holding it can push arbitrary leads into the customer's CRM. So it is read
 * from a wp-config.php constant, never hard-coded, never localised to the
 * browser, never returned by a REST endpoint, and never written to a log —
 * not even partially. Where an error needs to mention the destination, this
 * class says "the configured endpoint" and nothing more.
 *
 * Failure classification is this class's job, because only it knows what a
 * given response means. The queue never sees a status code:
 *
 *   2xx                      delivered
 *   408, 429, 5xx            retryable — the endpoint is unwell, not wrong
 *   other 4xx                permanent — the request itself is unacceptable,
 *                            so repeating it unchanged cannot help
 *   transport failure        retryable — DNS, TLS, timeout, connection reset
 *
 * A timeout is deliberately retryable even though Zapier may already have
 * received the lead. That choice favours delivery over uniqueness; the
 * duplicate risk is documented on Delivery_Queue.
 */
final class Zapier_Provider implements Delivery_Provider {

	/**
	 * Long enough for a slow hook, short enough that one bad lead cannot stall
	 * a cron batch. Five leads at the limit still fit inside a normal
	 * max_execution_time.
	 */
	private const TIMEOUT = 15;

	/**
	 * HTTP statuses worth trying again.
	 */
	private const RETRYABLE_STATUSES = array( 408, 429, 500, 502, 503, 504 );

	/**
	 * Cap on stored failure text, well inside delivery_last_error's 255 chars.
	 */
	private const REASON_LIMIT = 180;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'zapier';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured(): bool {
		return '' === $this->configuration_error();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns a reason, never the value being judged.
	 */
	public function configuration_error(): string {
		$url = $this->url();

		if ( '' === $url ) {
			return 'webhook_url_not_configured';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'webhook_url_malformed';
		}

		// HTTPS only: the payload carries a customer's name, email, phone and
		// street address, and the URL itself is a secret.
		if ( ! isset( $parts['scheme'] ) || 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return 'webhook_url_not_https';
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string,mixed> $payload Finished payload.
	 */
	public function send( array $payload ): Delivery_Result {
		$misconfigured = $this->configuration_error();

		if ( '' !== $misconfigured ) {
			// No request is attempted. The lead stays recoverable, and the
			// reason is actionable without revealing anything.
			return Delivery_Result::permanent(
				$misconfigured,
				0,
				array( 'stage' => 'configuration' )
			);
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return Delivery_Result::permanent( 'payload_not_encodable', 0, array( 'stage' => 'encode' ) );
		}

		$response = wp_remote_post(
			$this->url(),
			array(
				'timeout'     => self::TIMEOUT,
				// Never follow a redirect: a redirected webhook would send a
				// customer's details to a host nobody configured.
				'redirection' => 0,
				'blocking'    => true,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
					'User-Agent'   => '3Best/' . THREEBEST_CORE_VERSION . ' (lead delivery)',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Transport failures are temporary by nature: DNS, TLS, timeout,
			// connection reset. The message is safe — it describes our side.
			return Delivery_Result::retryable(
				'transport: ' . $this->trim( $response->get_error_message() ),
				0,
				array(
					'provider' => $this->name(),
					'stage'    => 'transport',
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return Delivery_Result::delivered( $status );
		}

		/*
		 * The response body is deliberately NOT stored or logged. It is written
		 * by a third party, can be arbitrarily large, and on a validation error
		 * commonly echoes back the fields it rejected — which here would be the
		 * customer's own details.
		 */
		if ( in_array( $status, self::RETRYABLE_STATUSES, true ) || $status >= 500 ) {
			return Delivery_Result::retryable(
				'http_' . $status,
				$status,
				array(
					'provider' => $this->name(),
					'stage'    => 'http',
					'status'   => $status,
				)
			);
		}

		return Delivery_Result::permanent(
			'http_' . $status,
			$status,
			array(
				'provider' => $this->name(),
				'stage'    => 'http',
				'status'   => $status,
			)
		);
	}

	/**
	 * The configured destination. Never logged, never returned.
	 */
	private function url(): string {
		$url = Settings::get( 'zapier_webhook_url', '' );

		return is_string( $url ) ? trim( $url ) : '';
	}

	/**
	 * Shortens a message for storage in delivery_last_error.
	 *
	 * @param string $message Raw message.
	 */
	private function trim( string $message ): string {
		$clean = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $message ) ) ?? '';

		return substr( trim( $clean ), 0, self::REASON_LIMIT );
	}
}
