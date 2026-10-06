<?php
/**
 * Geocoding provider selection.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

use ThreeBest\Core\Logger;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which geocoder answers, and is the only place that decision is made.
 *
 * Selection order:
 *
 *   1. A provider injected for tests via set_provider().
 *   2. The threebest_core_location_provider filter, so a site or a future
 *      plugin can supply its own implementation without touching this file.
 *   3. The configured provider from settings, if it reports itself available.
 *   4. The development provider.
 *
 * A configured provider that is NOT available — Google Places with no key, for
 * instance — falls back rather than failing every search, and says so in the
 * log once per request. Silently degrading without a trace would make a
 * production misconfiguration invisible.
 */
final class Provider_Factory {

	/**
	 * Known providers: setting value => class name.
	 *
	 * @var array<string,class-string<Location_Provider>>
	 */
	private const PROVIDERS = array(
		'nominatim'     => Nominatim_Provider::class,
		'google_places' => Google_Places_Provider::class,
	);

	/**
	 * The provider used when nothing else resolves.
	 */
	private const FALLBACK = 'nominatim';

	private static ?Location_Provider $provider = null;

	private static bool $warned = false;

	/**
	 * The active provider.
	 */
	public static function get(): Location_Provider {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		/**
		 * Filters the geocoding provider.
		 *
		 * Return a Location_Provider to take over entirely.
		 *
		 * @param Location_Provider|null $provider Null to use the configured one.
		 */
		$filtered = apply_filters( 'threebest_core_location_provider', null );

		if ( $filtered instanceof Location_Provider ) {
			self::$provider = $filtered;

			return self::$provider;
		}

		$configured = (string) Settings::get( 'location_provider', self::FALLBACK );
		$class      = self::PROVIDERS[ $configured ] ?? null;

		if ( null !== $class ) {
			$candidate = new $class();

			if ( $candidate instanceof Location_Provider && $candidate->is_available() ) {
				self::$provider = $candidate;

				return self::$provider;
			}

			if ( ! self::$warned ) {
				self::$warned = true;

				Logger::warning(
					'Configured location provider is unavailable; falling back.',
					array(
						'configured' => $configured,
						'fallback'   => self::FALLBACK,
					)
				);
			}
		}

		$fallback_class = self::PROVIDERS[ self::FALLBACK ];
		self::$provider = new $fallback_class();

		return self::$provider;
	}

	/**
	 * Replaces the provider. Test seam.
	 *
	 * @param Location_Provider|null $provider Provider, or null to reset.
	 */
	public static function set_provider( ?Location_Provider $provider ): void {
		self::$provider = $provider;
		self::$warned   = false;
	}

	/**
	 * Provider names this build knows about.
	 *
	 * @return string[]
	 */
	public static function known(): array {
		return array_keys( self::PROVIDERS );
	}

	/**
	 * Availability of every known provider, for the admin status screen.
	 *
	 * Reports only whether each could run — never a credential value.
	 *
	 * @return array<string,bool>
	 */
	public static function availability(): array {
		$status = array();

		foreach ( self::PROVIDERS as $key => $class ) {
			$instance       = new $class();
			$status[ $key ] = $instance instanceof Location_Provider && $instance->is_available();
		}

		return $status;
	}
}
