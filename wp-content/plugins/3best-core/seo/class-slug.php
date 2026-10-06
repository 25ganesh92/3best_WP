<?php
/**
 * Service/location slug generation and parsing.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The canonical URL identity of a service/location page.
 *
 * Ported from src/lib/slug.ts. Canonical format:
 *
 *   /{service-slug}-in-{location-slug}/
 *
 *   /plumber-in-newark-nj/
 *   /electrician-in-houston-tx/
 *   /hvac-in-new-york-ny/
 *
 * Two rules make the URL space finite and predictable, and both are enforced
 * here rather than trusted to callers:
 *
 *  1. A slug is built only from CANONICAL RECORDS — a Service from the
 *     repository and a Location from the normaliser. Never from raw user text,
 *     so the set of reachable URLs is bounded by the catalogues.
 *  2. There is exactly one slug algorithm. The service half is the curated
 *     service slug; the location half is whatever Normalizer::create_slug()
 *     produced. Neither is recomputed here, which is what guarantees that two
 *     different inputs resolving to the same city produce the same URL.
 */
final class Slug {

	/**
	 * The joiner between the two halves.
	 */
	public const JOINER = '-in-';

	/**
	 * Builds the canonical slug for a service/location pair.
	 *
	 *   Plumbing + New Jersey -> plumber-in-new-jersey
	 *   HVAC + Dallas, TX     -> hvac-in-dallas-tx
	 *   Plumbing + 07102      -> plumber-in-newark-nj  (the ZIP is not the slug)
	 *
	 * @param array<string,mixed> $service  Canonical service record.
	 * @param Location            $location Canonical location.
	 */
	public static function build( array $service, Location $location ): string {
		return $service['slug'] . self::JOINER . $location->slug;
	}

	/**
	 * Site-relative path for a service/location page, with trailing slash.
	 *
	 * @param array<string,mixed> $service  Canonical service record.
	 * @param Location            $location Canonical location.
	 */
	public static function path( array $service, Location $location ): string {
		return '/' . self::build( $service, $location ) . '/';
	}

	/**
	 * Resolves a URL slug back to canonical records.
	 *
	 * Matching is driven by the service catalogue rather than by splitting on
	 * the first joiner, so a service slug that itself contained "-in-" would
	 * keep working. Every occurrence is tried until both halves resolve.
	 *
	 * @param string $slug URL slug.
	 * @return array{service:array<string,mixed>,location:Location,slug:string}|null
	 */
	public static function parse( string $slug ): ?array {
		$normalised = strtolower( trim( rawurldecode( $slug ) ) );

		if ( '' === $normalised || false === strpos( $normalised, self::JOINER ) ) {
			return null;
		}

		$cursor = strpos( $normalised, self::JOINER );

		while ( false !== $cursor ) {
			$service_slug  = substr( $normalised, 0, $cursor );
			$location_slug = substr( $normalised, $cursor + strlen( self::JOINER ) );

			$service  = Services_Repository::by_slug( $service_slug );
			$location = '' !== $location_slug ? Catalog::by_slug( $location_slug ) : null;

			if ( null !== $service && null !== $location ) {
				return array(
					'service'  => $service,
					'location' => $location,
					'slug'     => self::build( $service, $location ),
				);
			}

			$cursor = strpos( $normalised, self::JOINER, $cursor + 1 );
		}

		return null;
	}

	/**
	 * Recognises the legacy ZIP form, "plumber-in-07102".
	 *
	 * Those URLs are no longer generated, but links to them may exist. Phase 7
	 * resolves the ZIP and permanently redirects to the city/state URL.
	 *
	 * @param string $slug URL slug.
	 * @return array{service:array<string,mixed>,zip:string}|null
	 */
	public static function parse_legacy_zip( string $slug ): ?array {
		$normalised = strtolower( trim( $slug ) );
		$cursor     = strrpos( $normalised, self::JOINER );

		if ( false === $cursor ) {
			return null;
		}

		$zip = substr( $normalised, $cursor + strlen( self::JOINER ) );

		if ( 1 !== preg_match( '/^\d{5}$/', $zip ) ) {
			return null;
		}

		$service = Services_Repository::by_slug( substr( $normalised, 0, $cursor ) );

		return null !== $service ? array(
			'service' => $service,
			'zip'     => $zip,
		) : null;
	}
}
