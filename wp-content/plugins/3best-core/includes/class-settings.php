<?php
/**
 * Configuration reader.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Single typed entry point for every piece of plugin configuration.
 *
 * Resolution order is deliberately constant-first:
 *
 *     wp-config.php constant  ->  threebest_settings option  ->  coded default
 *
 * A constant always wins. That means a production credential placed in
 * wp-config.php cannot be read back out of, or overwritten through, the admin
 * UI, and it never appears in a database dump. Secrets have no coded default
 * and no admin write path; the only thing the UI will ever report about one is
 * whether it is configured.
 *
 * No secret value is stored in this file. Nothing here is printed anywhere.
 */
final class Settings {

	public const OPTION = 'threebest_settings';

	/**
	 * Settings keys that may be supplied as a wp-config.php constant.
	 *
	 * @var array<string,string>
	 */
	private const CONSTANT_MAP = array(
		'nominatim_base_url'        => 'THREEBEST_NOMINATIM_BASE_URL',
		'nominatim_user_agent'      => 'THREEBEST_NOMINATIM_USER_AGENT',
		'google_maps_api_key'       => 'THREEBEST_GOOGLE_MAPS_API_KEY',
		'twilio_account_sid'        => 'THREEBEST_TWILIO_ACCOUNT_SID',
		'twilio_auth_token'         => 'THREEBEST_TWILIO_AUTH_TOKEN',
		'twilio_verify_service_sid' => 'THREEBEST_TWILIO_VERIFY_SERVICE_SID',
		'zapier_webhook_url'        => 'THREEBEST_ZAPIER_WEBHOOK_URL',
	);

	/**
	 * Keys whose values must never be rendered, logged or returned to a client.
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = array(
		'google_maps_api_key',
		'twilio_account_sid',
		'twilio_auth_token',
		'twilio_verify_service_sid',
		'zapier_webhook_url',
	);

	/**
	 * Non-secret defaults.
	 *
	 * The OTP values are the confirmed decision 5 parameters, and the consent
	 * wording is the confirmed decision 4 text. They are recorded here so the
	 * agreed behaviour is versioned in code rather than left in a ticket, but
	 * nothing in Phase 1 reads them yet.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Geocoding. Public Nominatim is development-only (decision 9).
			'location_provider'       => 'nominatim',
			'nominatim_base_url'      => 'https://nominatim.openstreetmap.org/search',
			'nominatim_user_agent'    => '3Best/1.0 (+set a contact address before production use)',
			'location_cache_ttl'      => 300,
			'location_min_query'      => 2,
			'location_max_results'    => 5,

			// Phone verification (decision 5).
			'otp_provider'            => 'twilio_verify',
			'otp_length'              => 6,
			'otp_ttl_seconds'         => 600,
			'otp_resend_cooldown'     => 30,
			'otp_max_attempts'        => 5,
			'otp_max_sends_per_day'   => 5,
			'otp_token_ttl_seconds'   => 900,

			// Consent wording and version are NOT settings: they live in
			// Leads\Consent, which binds the text to the version and keeps the
			// archive. A second copy here could drift from what was shown.

			// Lead delivery (decision 6).
			'delivery_enabled'        => false,
			'delivery_max_attempts'   => 5,
			'delivery_batch_size'     => 10,

			// Housekeeping.
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Returned when the key is unknown and unset.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		if ( self::is_from_constant( $key ) ) {
			return constant( self::CONSTANT_MAP[ $key ] );
		}

		$stored = get_option( self::OPTION, array() );

		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}

		$defaults = self::defaults();

		if ( array_key_exists( $key, $defaults ) ) {
			return $defaults[ $key ];
		}

		return $fallback;
	}

	/**
	 * Whether this key is supplied by a wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 */
	public static function is_from_constant( string $key ): bool {
		return isset( self::CONSTANT_MAP[ $key ] ) && defined( self::CONSTANT_MAP[ $key ] );
	}

	/**
	 * Whether this key holds a secret.
	 *
	 * @param string $key Setting key.
	 */
	public static function is_secret( string $key ): bool {
		return in_array( $key, self::SECRET_KEYS, true );
	}

	/**
	 * Whether a value is configured, without revealing what it is.
	 *
	 * This is the only thing the admin UI may ever show about a secret.
	 *
	 * @param string $key Setting key.
	 */
	public static function is_configured( string $key ): bool {
		$value = self::get( $key );

		return is_string( $value ) ? '' !== trim( $value ) : ! empty( $value );
	}

	/**
	 * The constant name a key may be defined with, for documentation purposes.
	 *
	 * Returns the NAME only, never a value.
	 *
	 * @param string $key Setting key.
	 */
	public static function constant_name( string $key ): string {
		return self::CONSTANT_MAP[ $key ] ?? '';
	}

	/**
	 * Keys that may be supplied as constants.
	 *
	 * @return string[]
	 */
	public static function constant_keys(): array {
		return array_keys( self::CONSTANT_MAP );
	}

	/**
	 * Persists non-secret settings.
	 *
	 * Secrets are rejected outright: they belong in wp-config.php, not in the
	 * options table. Keys that are not known defaults are ignored rather than
	 * stored, so an unexpected form field cannot pollute the option.
	 *
	 * @param array<string,mixed> $values Sanitised values.
	 */
	public static function update( array $values ): bool {
		$allowed = self::defaults();
		$stored  = get_option( self::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();

		foreach ( $values as $key => $value ) {
			if ( self::is_secret( $key ) || ! array_key_exists( $key, $allowed ) ) {
				continue;
			}

			$stored[ $key ] = $value;
		}

		return update_option( self::OPTION, $stored, true );
	}
}
