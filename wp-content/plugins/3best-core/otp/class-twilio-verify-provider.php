<?php
/**
 * Twilio Verify provider.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Otp;

use ThreeBest\Core\Logger;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phone verification through Twilio Verify (confirmed decision 5).
 *
 * Twilio generates, stores, delivers and checks the code. We never see it,
 * never store it, and never log it — the strongest form of "do not store the
 * plaintext OTP" available, because there is nothing to store.
 *
 * Two endpoints, both under the Verify Service SID:
 *
 *   POST /v2/Services/{ServiceSid}/Verifications       To, Channel=sms
 *   POST /v2/Services/{ServiceSid}/VerificationCheck   To, Code
 *
 * Credentials come from wp-config.php constants via Settings and are used only
 * as the HTTP Basic header. They never appear in a response, a log line, a
 * localised script, or an exception message.
 *
 * LIVE DELIVERY IS UNVERIFIED. No Twilio account is configured in this
 * environment, so no SMS has been sent and no real code has been checked. What
 * IS verified is everything this class does around the network call: request
 * construction, authentication header, response mapping, and the handling of
 * every documented failure — tested by intercepting WordPress's HTTP layer with
 * the pre_http_request filter, which exercises the real wp_remote_post path.
 */
final class Twilio_Verify_Provider implements Otp_Provider {

	private const API_BASE = 'https://verify.twilio.com/v2/Services/';
	private const TIMEOUT  = 10;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'twilio_verify';
	}

	/**
	 * {@inheritDoc}
	 *
	 * All three credentials must be present. A partially configured account
	 * would fail on every request, so it is better not to be selected at all.
	 */
	public function is_available(): bool {
		return Settings::is_configured( 'twilio_account_sid' )
			&& Settings::is_configured( 'twilio_auth_token' )
			&& Settings::is_configured( 'twilio_verify_service_sid' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sends_sms(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $phone_e164 Number in E.164 form.
	 * @param string $code       Ignored: Twilio generates its own code.
	 */
	public function request( string $phone_e164, string $code = '' ): Otp_Result {
		unset( $code );

		$response = $this->post(
			'Verifications',
			array(
				'To'      => $phone_e164,
				'Channel' => 'sms',
			)
		);

		if ( null === $response ) {
			return Otp_Result::failure(
				$this->name(),
				Otp_Result::RATE_LIMITED === $this->last_status ? Otp_Result::RATE_LIMITED : Otp_Result::FAILED,
				$this->last_context
			);
		}

		$status = isset( $response['status'] ) && is_string( $response['status'] ) ? $response['status'] : '';
		$sid    = isset( $response['sid'] ) && is_string( $response['sid'] ) ? $response['sid'] : '';

		// Twilio reports a newly created verification as "pending".
		if ( 'pending' === $status ) {
			return Otp_Result::sent( $this->name(), $sid );
		}

		return Otp_Result::failure(
			$this->name(),
			Otp_Result::FAILED,
			array( 'twilio_status' => $status )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $phone_e164 Number in E.164 form.
	 * @param string $code       The code the user entered.
	 */
	public function verify( string $phone_e164, string $code ): Otp_Result {
		$response = $this->post(
			'VerificationCheck',
			array(
				'To'   => $phone_e164,
				// Sent to Twilio, never stored or logged by us.
				'Code' => $code,
			)
		);

		if ( null === $response ) {
			/*
			 * Twilio answers 404 when no verification is pending for the number:
			 * it has expired, already been approved, or never existed. That is
			 * "expired" from the user's point of view, not a provider fault.
			 */
			if ( 404 === $this->last_http_status ) {
				return Otp_Result::failure( $this->name(), Otp_Result::EXPIRED, $this->last_context );
			}

			if ( 429 === $this->last_http_status ) {
				return Otp_Result::failure( $this->name(), Otp_Result::RATE_LIMITED, $this->last_context );
			}

			return Otp_Result::failure( $this->name(), Otp_Result::FAILED, $this->last_context );
		}

		$status = isset( $response['status'] ) && is_string( $response['status'] ) ? $response['status'] : '';
		$valid  = isset( $response['valid'] ) ? (bool) $response['valid'] : false;
		$sid    = isset( $response['sid'] ) && is_string( $response['sid'] ) ? $response['sid'] : '';

		if ( 'approved' === $status && $valid ) {
			return Otp_Result::verified( $this->name(), $sid );
		}

		return Otp_Result::failure(
			$this->name(),
			Otp_Result::FAILED,
			array( 'twilio_status' => $status )
		);
	}

	/**
	 * HTTP status of the last call, for mapping failures.
	 */
	private int $last_http_status = 0;

	/**
	 * Status hint derived from the last call.
	 */
	private string $last_status = '';

	/**
	 * Diagnostic detail from the last call. Never returned to a caller.
	 *
	 * @var array<string,mixed>
	 */
	private array $last_context = array();

	/**
	 * POSTs a form-encoded request to a Verify endpoint.
	 *
	 * Returns the decoded body on success, or null on any failure, with the
	 * detail recorded on $last_context for the caller to attach to a log line.
	 *
	 * @param string                $endpoint 'Verifications' or 'VerificationCheck'.
	 * @param array<string,string>  $body     Form fields.
	 * @return array<string,mixed>|null
	 */
	private function post( string $endpoint, array $body ): ?array {
		$this->last_http_status = 0;
		$this->last_status      = '';
		$this->last_context     = array();

		$service_sid = (string) Settings::get( 'twilio_verify_service_sid', '' );
		$account_sid = (string) Settings::get( 'twilio_account_sid', '' );
		$auth_token  = (string) Settings::get( 'twilio_auth_token', '' );

		if ( '' === $service_sid || '' === $account_sid || '' === $auth_token ) {
			$this->last_context = array(
				'provider' => $this->name(),
				'stage'    => 'configuration',
			);

			return null;
		}

		$url = self::API_BASE . rawurlencode( $service_sid ) . '/' . $endpoint;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					// Basic auth. Built per request and never stored anywhere.
					'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_context = array(
				'provider' => $this->name(),
				'stage'    => 'transport',
				'detail'   => $response->get_error_message(),
			);

			return null;
		}

		$this->last_http_status = (int) wp_remote_retrieve_response_code( $response );
		$decoded                = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $this->last_http_status < 200 || $this->last_http_status >= 300 ) {
			if ( 429 === $this->last_http_status ) {
				$this->last_status = Otp_Result::RATE_LIMITED;
			}

			/*
			 * Twilio's own error code is useful for support and reveals nothing
			 * about our credentials. The message is kept too, but only ever in
			 * the log — it can name the account or the service.
			 */
			$this->last_context = array(
				'provider'     => $this->name(),
				'stage'        => 'http',
				'status'       => $this->last_http_status,
				'twilio_code'  => is_array( $decoded ) && isset( $decoded['code'] ) ? $decoded['code'] : null,
			);

			return null;
		}

		if ( ! is_array( $decoded ) ) {
			$this->last_context = array(
				'provider' => $this->name(),
				'stage'    => 'payload',
			);

			return null;
		}

		return $decoded;
	}
}
