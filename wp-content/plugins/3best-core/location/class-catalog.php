<?php
/**
 * The curated location catalogue.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * The areas this application treats as canonical.
 *
 * Ported from src/lib/location/catalog.ts. It does three jobs:
 *
 *  1. Resolves a URL slug to a Location, so routing has an authority.
 *  2. Answers search FIRST, ahead of the geocoder. This is not an optimisation
 *     — a geocoder matches whole words and cannot handle partial input. Typing
 *     "new jer" returns a plumbing business from Nominatim; the catalogue
 *     matches it instantly. Supported areas also rank above everything else.
 *  3. Overrides the geocoder for ZIPs it cannot place in a city.
 *
 * Curated ZIPs are search entries only. Each carries its city's slug, so a ZIP
 * never becomes a page.
 */
final class Catalog {

	/**
	 * Minimum characters before search runs. Matches the React client.
	 */
	public const MIN_QUERY_LENGTH = 2;

	/**
	 * Maximum catalogue rows returned for one query.
	 */
	private const MAX_RESULTS = 5;

	/**
	 * Routable areas, keyed by slug.
	 *
	 * @var array<string,Location>|null
	 */
	private static ?array $areas = null;

	/**
	 * Curated ZIP entries.
	 *
	 * @var Location[]|null
	 */
	private static ?array $zips = null;

	/**
	 * Every routable area.
	 *
	 * @return Location[]
	 */
	public static function areas(): array {
		self::load();

		return array_values( self::$areas );
	}

	/**
	 * Resolves a URL slug to a Location.
	 *
	 * Curated records win. Anything else falls back to the narrow slug parser,
	 * so a city found through search ("toledo-oh") still renders a page instead
	 * of 404ing. Indexability is unaffected — that is decided separately.
	 *
	 * The result's slug is canonical and may differ from the one requested
	 * ("newark-new-jersey" -> "newark-nj"); Phase 7 redirects when it does.
	 *
	 * @param string $slug URL slug.
	 */
	public static function by_slug( string $slug ): ?Location {
		self::load();

		$key = strtolower( trim( $slug ) );

		return self::$areas[ $key ] ?? Normalizer::from_slug( $key );
	}

	/**
	 * The curated record for a ZIP, if we have one. Routes to its city.
	 *
	 * @param string $zip Five-digit ZIP.
	 */
	public static function by_zip( string $zip ): ?Location {
		if ( '' === $zip ) {
			return null;
		}

		self::load();

		foreach ( self::$zips as $location ) {
			if ( $location->zip === $zip ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Prefix/substring search over the curated catalogue.
	 *
	 * Synchronous and instant. This is what makes partial queries such as
	 * "new jer" or "hobo" work at all.
	 *
	 * @param string $query Sanitised query.
	 * @return Location[]
	 */
	public static function search( string $query ): array {
		$trimmed = strtolower( trim( $query ) );

		if ( strlen( $trimmed ) < self::MIN_QUERY_LENGTH ) {
			return array();
		}

		self::load();

		$scored = array();

		foreach ( array_merge( array_values( self::$areas ), self::$zips ) as $location ) {
			$rank = self::score( $location, $trimmed );

			if ( $rank < 0 ) {
				continue;
			}

			$scored[] = array(
				'rank'     => $rank,
				'label'    => $location->display_name,
				'location' => $location,
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return ( $a['rank'] <=> $b['rank'] ) ?: strcmp( $a['label'], $b['label'] );
			}
		);

		return array_map(
			static fn( array $row ): Location => $row['location'],
			array_slice( $scored, 0, self::MAX_RESULTS )
		);
	}

	/**
	 * Ranks a catalogue entry against a query. -1 means no match; lower is better.
	 *
	 * @param Location $location Candidate.
	 * @param string   $query    Lowercased query.
	 */
	private static function score( Location $location, string $query ): float {
		$terms = array_filter(
			array(
				$location->display_name,
				$location->city,
				$location->state,
				$location->state_code,
				$location->zip,
				'' !== $location->city && '' !== $location->state_code
					? $location->city . ' ' . $location->state_code
					: '',
			),
			static fn( string $term ): bool => '' !== $term
		);

		$best = -1.0;

		foreach ( $terms as $term ) {
			$term = strtolower( $term );

			if ( $term === $query ) {
				$candidate = 0.0;
			} elseif ( 0 === strpos( $term, $query ) ) {
				$candidate = 1.0;
			} elseif ( false !== strpos( $term, $query ) ) {
				$candidate = 3.0;
			} else {
				continue;
			}

			$best = $best < 0 ? $candidate : min( $best, $candidate );
		}

		if ( $best < 0 ) {
			return -1.0;
		}

		// Tie-break: states first, then cities, then ZIP entries.
		if ( 'postcode' === $location->source_type ) {
			$weight = 0.2;
		} elseif ( 'state' === $location->location_type ) {
			$weight = 0.0;
		} else {
			$weight = 0.1;
		}

		return $best + $weight;
	}

	/**
	 * Builds the catalogue once per request.
	 */
	private static function load(): void {
		if ( null !== self::$areas ) {
			return;
		}

		$data   = require THREEBEST_CORE_DIR . 'data/locations.php';
		$areas  = array();
		$zips   = array();

		foreach ( $data['states'] as $state_name ) {
			$location = Normalizer::create( array( 'state' => $state_name ) );

			if ( null !== $location ) {
				$areas[ $location->slug ] = $location;
			}
		}

		foreach ( $data['cities'] as $city ) {
			$location = Normalizer::create(
				array(
					'city'       => $city[0],
					'state_code' => $city[1],
				)
			);

			if ( null !== $location ) {
				$areas[ $location->slug ] = $location;
			}
		}

		foreach ( $data['zips'] as $zip ) {
			$location = Normalizer::create(
				array(
					'zip'         => $zip[0],
					'city'        => $zip[1],
					'state_code'  => $zip[2],
					'source_type' => 'postcode',
					'id'          => 'zip-' . $zip[0],
				)
			);

			if ( null !== $location ) {
				$zips[] = $location;
			}
		}

		self::$areas = $areas;
		self::$zips  = $zips;
	}

	/**
	 * Clears the memoised catalogue. Test seam.
	 */
	public static function flush(): void {
		self::$areas = null;
		self::$zips  = null;
	}
}
