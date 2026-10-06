<?php
/**
 * Phone verification endpoints.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Field_Errors;
use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Leads\Lead_Validator;
use ThreeBest\Core\Otp\Otp_Service;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Security\Request_Guard;
use ThreeBest\Core\Security\Throttle;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST /threebest/v1/otp/request  — ask for a code
 * POST /threebest/v1/otp/verify   — check a code
 *
 * Public consumer endpoints: an anonymous visitor has no capabilities, so these
 * use ACCESS_PUBLIC_NONCE. The nonce is CSRF protection, not authentication —
 * the real limits are the throttle buckets here and the per-number caps inside
 * Otp_Service.
 *
 * Two layers of throttling, deliberately. The bucket here is per IP and stops
 * one client hammering the endpoint. The caps in Otp_Service are per PHONE
 * NUMBER and stop a distributed client sending someone fifty texts. Either
 * alone leaves a hole.
 */
final class Otp_Controller extends Controller {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'     => '/otp/request',
				'methods'  => 'POST',
				'callback' => array( $this, 'request_code' ),
				'access'   => Request_Guard::ACCESS_PUBLIC_NONCE,
				'throttle' => 'otp_request',
			),
			array(
				'path'     => '/otp/verify',
				'methods'  => 'POST',
				'callback' => array( $this, 'verify_code' ),
				'access'   => Request_Guard::ACCESS_PUBLIC_NONCE,
				'throttle' => 'otp_verify',
			),
		);
	}

	/**
	 * Requests a verification code.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 *
	 * @throws Api_Exception On consent, validation, throttling or provider failure.
	 */
	public function request_code( WP_REST_Request $request ): array {
		$consent = Sanitizer::boolean( $request->get_param( 'consent' ) );
		$phone   = Sanitizer::phone( $request->get_param( 'phone' ) );

		/*
		 * CONSENT FIRST. Checked here and again inside Otp_Service, so no code
		 * path reaches a provider call without it. Nothing is sent, nothing is
		 * stored, and no number is even looked at when the box is unticked.
		 */
		if ( ! $consent ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'consent' => Consent::error_message() ) )
			);
		}

		/*
		 * The whole form is validated before an SMS is sent. There is no point
		 * paying for a message to accompany a lead that will be rejected a
		 * moment later, and it keeps the failure where the user can fix it.
		 */
		$errors = Lead_Validator::validate_fields(
			array(
				'subcategory_id' => Sanitizer::identifier( $request->get_param( 'subcategoryId' ) ),
				'full_name'      => Sanitizer::text( $request->get_param( 'fullName' ), 150 ),
				'email'          => Sanitizer::email( $request->get_param( 'email' ) ),
				'phone'          => $phone,
				'address'        => Sanitizer::address( $request->get_param( 'address' ) ),
			)
		);

		if ( $errors->any() ) {
			throw Api_Exception::validation( $errors );
		}

		// Per-number limits live in the service; this bucket is per IP.
		$result = Otp_Service::request( $phone, true );

		return array(
			'otpId'       => $result['otp_uid'],
			'maskedPhone' => $result['masked'],
			'expiresIn'   => $result['expires_in'],
			'resendAfter' => $result['resend_after'],
			// False in development, so the UI never claims an SMS was sent when
			// none was.
			'smsSent'     => $result['sms_sent'],
		);
	}

	/**
	 * Verifies a code and issues a single-use token.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 *
	 * @throws Api_Exception On validation, expiry, lockout or provider failure.
	 */
	public function verify_code( WP_REST_Request $request ): array {
		$otp_id = Sanitizer::text( $request->get_param( 'otpId' ), 64 );
		$code   = Sanitizer::text( $request->get_param( 'code' ), 16 );

		if ( '' === $otp_id ) {
			throw Api_Exception::validation(
				Field_Errors::from(
					array( 'code' => __( 'That verification has expired. Please request a new code.', '3best-core' ) )
				)
			);
		}

		/*
		 * A second throttle keyed on the verification itself, not the IP, so
		 * rotating addresses cannot multiply the guesses against one code. Its
		 * own bucket, sized to the same 5 attempts Otp_Service enforces on the
		 * row, rather than sharing the per-IP allowance: one caller guessing
		 * against ten codes and ten callers guessing against one are different
		 * problems and need separate budgets.
		 */
		$guess = Throttle::check( 'otp_verify_code', 'otp:' . $otp_id );

		if ( ! $guess->allowed ) {
			throw Api_Exception::throttled( $guess->retry_after );
		}

		$result = Otp_Service::verify( $otp_id, $code );

		return array(
			'verified'         => true,
			// The client sends this back with the lead. Single-use, hashed at
			// rest, bound to the verified number, short-lived.
			'verificationToken' => $result['token'],
			'expiresIn'        => $result['expires_in'],
		);
	}
}
