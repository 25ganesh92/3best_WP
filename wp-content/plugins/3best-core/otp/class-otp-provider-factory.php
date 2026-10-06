<?php
/**
 * OTP provider selection.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Otp;

use ThreeBest\Core\Logger;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which service sends the verification code.
 *
 * Same shape as the location Provider_Factory, deliberately: injected (tests)
 * -> filter -> configured -> fallback. A configured provider that reports
 * itself unavailable is NOT used, and the fallback is logged once so a
 * production misconfiguration is visible rather than silent.
 *
 * There is no safe default here. If Twilio is unconfigured and the development
 * provider has not been explicitly enabled, get() returns the Twilio provider
 * anyway — which then fails loudly on the first call. Falling back to something
 * that accepts codes nobody received would be far worse than an outage.
 */
final class Otp_Provider_Factory {

	/**
	 * Known providers: setting value => class name.
	 *
	 * @var array<string,class-string<Otp_Provider>>
	 */
	private const PROVIDERS = array(
		'twilio_verify' => Twilio_Verify_Provider::class,
		'dev'           => Dev_Otp_Provider::class,
	);

	private static ?Otp_Provider $provider = null;

	private static bool $warned = false;

	/**
	 * The active provider.
	 */
	public static function get(): Otp_Provider {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		/**
		 * Filters the OTP provider.
		 *
		 * @param Otp_Provider|null $provider Null to use the configured one.
		 */
		$filtered = apply_filters( 'threebest_core_otp_provider', null );

		if ( $filtered instanceof Otp_Provider ) {
			self::$provider = $filtered;

			return self::$provider;
		}

		$configured = (string) Settings::get( 'otp_provider', 'twilio_verify' );
		$class      = self::PROVIDERS[ $configured ] ?? null;

		if ( null !== $class ) {
			$candidate = new $class();

			if ( $candidate instanceof Otp_Provider && $candidate->is_available() ) {
				self::$provider = $candidate;

				return self::$provider;
			}

			if ( ! self::$warned ) {
				self::$warned = true;

				Logger::warning(
					'Configured OTP provider is unavailable.',
					array( 'configured' => $configured )
				);
			}
		}

		// Deliberately the real provider, not the development one: an
		// unconfigured site must fail, never silently accept codes.
		self::$provider = new Twilio_Verify_Provider();

		return self::$provider;
	}

	/**
	 * Replaces the provider. Test seam.
	 *
	 * @param Otp_Provider|null $provider Provider, or null to reset.
	 */
	public static function set_provider( ?Otp_Provider $provider ): void {
		self::$provider = $provider;
		self::$warned   = false;
	}

	/**
	 * Availability of every known provider, for the admin screen.
	 *
	 * Reports capability only — never a credential value.
	 *
	 * @return array<string,array{available:bool,sends_sms:bool}>
	 */
	public static function availability(): array {
		$status = array();

		foreach ( self::PROVIDERS as $key => $class ) {
			$instance       = new $class();
			$status[ $key ] = array(
				'available' => $instance->is_available(),
				'sends_sms' => $instance->sends_sms(),
			);
		}

		return $status;
	}
}
