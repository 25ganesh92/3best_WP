<?php
/**
 * Location search orchestration.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a location search is assembled.
 *
 * The REST controller handles HTTP; this handles meaning. It is also the only
 * class that knows a provider and a catalogue both exist.
 *
 *   sanitised query
 *     -> cache hit?                      return immediately
 *     -> curated catalogue FIRST         instant, handles partial words
 *     -> provider                        whole-word geocoding
 *     -> canonicalise each result        ZIP/landmark collapse to their city
 *     -> merge, dedupe, cap
 *     -> cache (successes only)
 *
 * Catalogue-first is not an optimisation. A geocoder matches whole words, so
 * "new jer" returns a plumbing business rather than New Jersey; the catalogue
 * matches prefixes instantly and keeps supported areas at the top.
 *
 * Provider failure is survivable: if the catalogue produced anything, those
 * results are returned and the failure is logged but not surfaced. Only when
 * there is nothing at all to offer does the caller see an upstream error. That
 * is the same behaviour as the React route, and it means a geocoder outage
 * degrades the experience instead of breaking it.
 */
final class Location_Service {

	/**
	 * Searches for locations matching a query.
	 *
	 * @param string $query Sanitised query.
	 * @return Location[]
	 *
	 * @throws Api_Exception When nothing can be offered and the provider failed.
	 */
	public static function search( string $query ): array {
		$query = trim( $query );
		$limit = max( 1, (int) Settings::get( 'location_max_results', 5 ) );

		if ( strlen( $query ) < self::min_query_length() ) {
			return array();
		}

		$cached = Location_Cache::get( $query );

		if ( null !== $cached ) {
			return $cached;
		}

		$merged = array();
		$seen   = array();

		// 1. Curated areas first.
		foreach ( Catalog::search( $query ) as $location ) {
			$key = $location->dedupe_key();

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ]  = count( $merged );
			$merged[]      = $location;
		}

		// 2. Provider.
		$provider_failed = false;

		try {
			$results = Provider_Factory::get()->search( $query, $limit );

			foreach ( $results as $result ) {
				$canonical = Canonicalizer::resolve( $result );

				if ( null === $canonical ) {
					continue;
				}

				$key = $canonical->dedupe_key();

				if ( isset( $seen[ $key ] ) ) {
					/*
					 * Same place as a curated row. Keep the curated record — it
					 * is the authority on slug and display — but borrow the
					 * geocoder's coordinates, which the catalogue has none of.
					 */
					$index = $seen[ $key ];

					if ( '' === $merged[ $index ]->latitude && '' !== $canonical->latitude ) {
						$merged[ $index ] = $merged[ $index ]->with_coordinates(
							$canonical->latitude,
							$canonical->longitude
						);
					}

					continue;
				}

				$seen[ $key ] = count( $merged );
				$merged[]     = $canonical;
			}
		} catch ( Api_Exception $exception ) {
			$provider_failed = true;

			Logger::warning(
				'Location provider failed.',
				array_merge(
					$exception->log_context(),
					array( 'query_length' => strlen( $query ) )
				)
			);
		}

		/*
		 * Only surface an error when we have nothing at all. A provider outage
		 * with curated results available is invisible to the user, by design.
		 */
		if ( $provider_failed && array() === $merged ) {
			throw Api_Exception::upstream(
				__( 'Unable to find locations right now. Please try again.', '3best-core' ),
				array( 'stage' => 'no_results_after_provider_failure' )
			);
		}

		$results = array_slice( $merged, 0, $limit );

		// Never cache a degraded result set: the next request should try the
		// provider again rather than inherit the outage.
		if ( ! $provider_failed ) {
			Location_Cache::set( $query, $results );
		}

		return $results;
	}

	/**
	 * Minimum query length, from settings, never below the catalogue's floor.
	 */
	public static function min_query_length(): int {
		$configured = (int) Settings::get( 'location_min_query', Catalog::MIN_QUERY_LENGTH );

		return max( 1, $configured );
	}
}
