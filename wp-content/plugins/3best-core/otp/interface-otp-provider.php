<?php
/**
 * Phone verification provider contract.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the lead flow and whoever sends the SMS.
 *
 * An implementation owns exactly two operations and translates its own
 * vocabulary into Otp_Result. Nothing above this line knows that Twilio exists,
 * which is what lets the provider change without touching the lead flow, the
 * endpoints, the throttling or the stored lifecycle.
 *
 * SERVER-SIDE ONLY. Implementations hold credentials, which is why the browser
 * calls our REST routes and never a provider directly.
 *
 * On who owns the code: with a verification service such as Twilio Verify, the
 * PROVIDER generates, stores and checks the code — we never see it, which is
 * the strongest possible version of "do not store the plaintext OTP". A
 * provider that generates codes locally instead must hash them; Otp_Service
 * handles that case and never writes a plaintext code either way.
 */
interface Otp_Provider {

	/**
	 * Machine-readable provider name, e.g. 'twilio_verify'.
	 */
	public function name(): string;

	/**
	 * Whether this provider is configured and permitted to run.
	 */
	public function is_available(): bool;

	/**
	 * Whether this provider actually sends an SMS.
	 *
	 * False for development stand-ins. The admin screen and the logs use this
	 * so "verified" in a development environment can never be mistaken for a
	 * real delivery.
	 */
	public function sends_sms(): bool;

	/**
	 * Asks the provider to send a verification code.
	 *
	 * @param string $phone_e164 Number in E.164 form, e.g. +12015551234.
	 * @param string $code       A locally generated code, for providers that do
	 *                           not generate their own. Ignored otherwise.
	 */
	public function request( string $phone_e164, string $code = '' ): Otp_Result;

	/**
	 * Checks a code against the provider.
	 *
	 * @param string $phone_e164 Number in E.164 form.
	 * @param string $code       The code the user entered.
	 */
	public function verify( string $phone_e164, string $code ): Otp_Result;
}
