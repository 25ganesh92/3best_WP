<?php
/**
 * REST response envelope.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Field_Errors;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the one response shape every 3Best endpoint returns.
 *
 * Success:
 *     { "success": true, "message": "...", ...payload }
 *
 * Failure:
 *     { "success": false, "message": "...", "code": "...", "fieldErrors": {} }
 *
 * This matches the contract the React application already speaks, so the
 * browser code ports across rather than being rewritten:
 *
 *   - POST /api/leads returns { success, leadId, message } on success and
 *     { success: false, message, fieldErrors } on a 400. Both are produced
 *     verbatim by the two methods below.
 *   - GET /api/location-search returns { results: [...] }. Passing
 *     [ 'results' => [...] ] as the payload yields
 *     { success: true, results: [...] } — a superset, so the existing client
 *     reading payload.results keeps working unchanged.
 *
 * fieldErrors is always a JSON object on a failure, never an array and never
 * absent, so a client can index into it without checking its type first.
 */
final class Response {

	/**
	 * A successful response.
	 *
	 * @param array<string,mixed> $payload Endpoint data, merged into the envelope.
	 * @param string              $message Optional human-readable message.
	 * @param int                 $status  HTTP status.
	 */
	public static function success( array $payload = array(), string $message = '', int $status = 200 ): WP_REST_Response {
		$body = array( 'success' => true );

		if ( '' !== $message ) {
			$body['message'] = $message;
		}

		// Reserved keys cannot be overwritten by a payload, so an endpoint
		// cannot accidentally return success => false inside a success call.
		unset( $payload['success'] );

		return new WP_REST_Response( array_merge( $body, $payload ), $status );
	}

	/**
	 * A failure response.
	 *
	 * @param string            $message      Safe, consumer-facing message.
	 * @param int               $status       HTTP status.
	 * @param string            $code         Machine-readable error code.
	 * @param Field_Errors|null $field_errors Per-field messages, if any.
	 * @param array<string,mixed> $extra      Additional safe keys, e.g. retryAfter.
	 */
	public static function error(
		string $message,
		int $status = 400,
		string $code = 'error',
		?Field_Errors $field_errors = null,
		array $extra = array()
	): WP_REST_Response {
		$body = array(
			'success'     => false,
			'message'     => $message,
			'code'        => $code,
			'fieldErrors' => ( $field_errors ?? Field_Errors::from() )->jsonSerialize(),
		);

		foreach ( array( 'success', 'message', 'code', 'fieldErrors' ) as $reserved ) {
			unset( $extra[ $reserved ] );
		}

		return new WP_REST_Response( array_merge( $body, $extra ), $status );
	}

	/**
	 * Maps an Api_Exception onto a failure response.
	 *
	 * Only the exception's public message reaches the body. Its log context is
	 * never read here — that is the controller's job, and it goes to the logger.
	 *
	 * @param Api_Exception $exception Failure to render.
	 */
	public static function from_exception( Api_Exception $exception ): WP_REST_Response {
		$extra   = array();
		$context = $exception->log_context();

		// retry_after is the one diagnostic value that is genuinely useful to a
		// caller and reveals nothing, so it is promoted into the body and set
		// as a header.
		if ( isset( $context['retry_after'] ) && (int) $context['retry_after'] > 0 ) {
			$extra['retryAfter'] = (int) $context['retry_after'];
		}

		$response = self::error(
			$exception->getMessage(),
			$exception->status(),
			$exception->error_code(),
			$exception->field_errors(),
			$extra
		);

		if ( isset( $extra['retryAfter'] ) ) {
			$response->header( 'Retry-After', (string) $extra['retryAfter'] );
		}

		return $response;
	}
}
