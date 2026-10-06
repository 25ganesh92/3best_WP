<?php
/**
 * Short-term cache for location search results.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Caches successful location searches for a few minutes.
 *
 * Deliberately small. It exists for one reason: to be kind to the geocoder.
 * A debounced autocomplete asks the same few questions constantly, and the
 * development provider's public endpoint has a strict usage policy.
 *
 * Three rules, each of which matters:
 *
 *  - Bounded lifetime. Five minutes by default, matching the React route.
 *    Location data barely changes, but a cache with no expiry becomes a second
 *    source of truth that nobody can correct.
 *  - Normalised key. Lowercased and whitespace-collapsed, so "New  Jersey" and
 *    "new jersey" share one entry rather than two.
 *  - FAILURES ARE NEVER CACHED. A provider outage cached for five minutes turns
 *    a thirty-second blip into a five-minute one, and the user cannot retry
 *    their way out of it. Only successful lookups are stored.
 *
 * Nothing personal is stored: a query is a place name, and results contain no
 * user data. Keys are hashed anyway, so raw queries do not sit in wp_options in
 * readable form.
 */
final class Location_Cache {

	private const PREFIX = 'tb_loc_';

	/**
	 * Reads cached results for a query.
	 *
	 * @param string $query Sanitised query.
	 * @return Location[]|null Null on a miss.
	 */
	public static function get( string $query ): ?array {
		$entry = get_transient( self::key( $query ) );

		if ( ! is_array( $entry ) ) {
			return null;
		}

		$locations = array();

		foreach ( $entry as $row ) {
			$location = Location::from_array( $row );

			// A single unreadable row invalidates the whole entry rather than
			// returning a short list: a cache that silently drops suggestions
			// is worse than a miss.
			if ( null === $location ) {
				return null;
			}

			$locations[] = $location;
		}

		return $locations;
	}

	/**
	 * Stores results for a query.
	 *
	 * @param string     $query     Sanitised query.
	 * @param Location[] $locations Results to cache.
	 */
	public static function set( string $query, array $locations ): void {
		$ttl = (int) Settings::get( 'location_cache_ttl', 300 );

		if ( $ttl <= 0 ) {
			return;
		}

		set_transient(
			self::key( $query ),
			array_map( static fn( Location $location ): array => $location->to_array(), $locations ),
			$ttl
		);
	}

	/**
	 * Drops a cached query.
	 *
	 * @param string $query Sanitised query.
	 */
	public static function forget( string $query ): void {
		delete_transient( self::key( $query ) );
	}

	/**
	 * Hashed transient name for a normalised query.
	 *
	 * @param string $query Sanitised query.
	 */
	private static function key( string $query ): string {
		$normalised = strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) );

		return self::PREFIX . hash( 'sha256', $normalised );
	}
}
