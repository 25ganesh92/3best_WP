<?php
/**
 * Location canonicalisation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw location components from any source into a canonical Location.
 *
 * Every location in the system comes through here — a geocoder result, a
 * curated catalogue entry, a parsed URL slug — which is what guarantees one
 * place always ends up with one slug. Ported from src/lib/location/normalize.ts.
 *
 * PROVIDER-AGNOSTIC BY DESIGN. This class knows nothing about Nominatim or
 * Google Places. A provider maps its own response onto the neutral input array
 * documented on create(), including classifying the result as one of
 * Location::TYPES, and this class does the rest. That split is what lets the
 * provider change without the canonical identity of any place changing with it.
 *
 * On ext-intl: not used, and not required. Accent folding goes through
 * WordPress core's remove_accents(), which is pure PHP and deterministic on
 * every host. iconv's //TRANSLIT was evaluated in Phase 0 and rejected — it
 * produced "Ca~non City" on this stack and is locale-dependent, so it could
 * mint different slugs on different servers.
 */
final class Normalizer {

	/**
	 * US settlement hierarchy, most preferred first.
	 *
	 * "suburb" is deliberately NOT here. A suburb or neighbourhood is classified
	 * as a neighborhood and canonicalises to its CONTAINING city, which is what
	 * makes "Times Square" route to New York rather than minting a slug of its
	 * own. Promoting suburb to a settlement would break that rule.
	 */
	private const SETTLEMENT_TIERS = array( 'city', 'town', 'village', 'municipality', 'locality' );

	/**
	 * Source types that describe a whole area rather than a point inside one.
	 *
	 * A ZIP attached to one of these is wherever the geocoder put the centroid,
	 * not the user's, so it is dropped rather than passed off as theirs.
	 */
	private const AREA_SOURCE_TYPES = array( 'state', 'city', 'town', 'village' );

	/**
	 * Lowercase, ASCII-folded, hyphen-separated.
	 *
	 * The ONLY string-to-slug function in the plugin.
	 *
	 * @param string $value Raw text.
	 */
	public static function kebab( string $value ): string {
		$value = remove_accents( $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';

		return trim( $value, '-' );
	}

	/**
	 * The settlement a location belongs to, by the hierarchy above.
	 *
	 * @param array<string,mixed> $input Raw components.
	 */
	public static function resolve_settlement( array $input ): string {
		foreach ( self::SETTLEMENT_TIERS as $tier ) {
			$value = isset( $input[ $tier ] ) && is_string( $input[ $tier ] ) ? trim( $input[ $tier ] ) : '';

			// A name that folds to nothing (punctuation only) is not a settlement.
			if ( '' !== $value && '' !== self::kebab( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * USPS code from an explicit code, a full state name, or a code passed as
	 * the state. Always via the state table — never guessed from the name.
	 *
	 * @param array<string,mixed> $input Raw components.
	 */
	public static function resolve_state_code( array $input ): string {
		$explicit = isset( $input['state_code'] ) && is_string( $input['state_code'] )
			? strtoupper( trim( $input['state_code'] ) )
			: '';

		if ( States::is_code( $explicit ) ) {
			return $explicit;
		}

		$state = isset( $input['state'] ) && is_string( $input['state'] ) ? trim( $input['state'] ) : '';
		$code  = States::code_from_name( $state );

		if ( '' !== $code ) {
			return $code;
		}

		return States::is_code( $state ) ? strtoupper( $state ) : '';
	}

	/**
	 * "07102", "07102-1234", "07102;07103" -> "07102". Anything else -> "".
	 *
	 * @param string $value Raw postal code.
	 */
	public static function normalize_zip( string $value ): string {
		return 1 === preg_match( '/^\s*(\d{5})(?!\d)/', $value, $matches ) ? $matches[1] : '';
	}

	/**
	 * THE location slug. Nothing else in the plugin builds one.
	 *
	 *   Newark + NJ (+ 07102)     -> newark-nj
	 *   Beverly Hills (town) + CA -> beverly-hills-ca
	 *   New Jersey (state only)   -> new-jersey
	 *   07102 alone / non-US      -> "" (not an SEO location)
	 *
	 * A ZIP never becomes a slug: it pinpoints the user, not the page.
	 *
	 * @param array<string,mixed> $input Raw components.
	 */
	public static function create_slug( array $input ): string {
		$country_code = isset( $input['country_code'] ) && is_string( $input['country_code'] )
			? strtoupper( trim( $input['country_code'] ) )
			: '';

		// US-only launch. A non-US result is not routable here.
		if ( '' !== $country_code && 'US' !== $country_code ) {
			return '';
		}

		$state_code = self::resolve_state_code( $input );

		if ( '' === $state_code ) {
			return '';
		}

		$settlement = self::resolve_settlement( $input );

		if ( '' !== $settlement ) {
			return self::kebab( $settlement ) . '-' . strtolower( $state_code );
		}

		return self::kebab( States::name_from_code( $state_code ) );
	}

	/**
	 * The canonical identity of a location: what it routes to and how it reads.
	 *
	 * Returns null when the input cannot be placed in a US state — including a
	 * bare ZIP — because there is no SEO location to route to.
	 *
	 * @param array<string,mixed> $input Raw components.
	 * @return array{display_name:string,city:string,state:string,state_code:string,zip:string,slug:string,location_type:string}|null
	 */
	public static function canonicalize( array $input ): ?array {
		$slug = self::create_slug( $input );

		if ( '' === $slug ) {
			return null;
		}

		$state_code  = self::resolve_state_code( $input );
		$state       = States::name_from_code( $state_code );
		$city        = self::resolve_settlement( $input );
		$source_type = isset( $input['source_type'] ) && is_string( $input['source_type'] ) ? $input['source_type'] : '';

		$zip = in_array( $source_type, self::AREA_SOURCE_TYPES, true )
			? ''
			: self::normalize_zip( isset( $input['zip'] ) && is_string( $input['zip'] ) ? $input['zip'] : '' );

		$label = '' !== $city ? $city . ', ' . $state_code : $state;

		if ( 'postcode' === $source_type && '' !== $zip ) {
			$display_name = $label . ' ' . $zip;
		} elseif ( '' !== $city ) {
			$display_name = $label;
		} else {
			$display_name = $state . ', USA';
		}

		return array(
			'display_name'  => $display_name,
			'city'          => $city,
			'state'         => $state,
			'state_code'    => $state_code,
			'zip'           => $zip,
			'slug'          => $slug,
			'location_type' => '' !== $city ? 'city' : 'state',
		);
	}

	/**
	 * Builds a full Location from raw components.
	 *
	 * The one constructor for canonical locations. The input array is the
	 * neutral contract every provider maps onto:
	 *
	 *   city, town, village, municipality, locality  settlement candidates
	 *   state, state_code                            either or both
	 *   zip                                          raw postal code
	 *   country_code                                 ISO alpha-2; non-US rejected
	 *   source_type                                  one of Location::TYPES
	 *   name, latitude, longitude                    optional detail
	 *   id, provider_ref                             optional identifiers
	 *
	 * @param array<string,mixed> $input Raw components.
	 */
	public static function create( array $input ): ?Location {
		$canonical = self::canonicalize( $input );

		if ( null === $canonical ) {
			return null;
		}

		$get = static function ( string $key ) use ( $input ): string {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
		};

		$source_type = $get( 'source_type' );

		return new Location(
			'' !== $get( 'id' ) ? $get( 'id' ) : $canonical['slug'],
			$canonical['display_name'],
			$get( 'name' ),
			$canonical['city'],
			$canonical['state'],
			$canonical['state_code'],
			$canonical['zip'],
			'' !== $get( 'country' ) ? $get( 'country' ) : 'United States',
			'US',
			$get( 'latitude' ),
			$get( 'longitude' ),
			$canonical['slug'],
			$canonical['location_type'],
			in_array( $source_type, Location::TYPES, true ) ? $source_type : $canonical['location_type'],
			$get( 'provider_ref' )
		);
	}

	/**
	 * Resolves a URL slug that is not in the curated catalogue back to a Location.
	 *
	 * Deliberately narrow: "<state-name>", "<city>-<st>" and
	 * "<city>-<state-name>", all validated against the state table. Anything
	 * else returns null and the page 404s, so the URL space stays predictable.
	 *
	 * The returned slug is always canonical and can differ from the one passed
	 * in ("newark-new-jersey" -> "newark-nj"); Phase 7 redirects when it does.
	 *
	 * @param string $slug URL slug.
	 */
	public static function from_slug( string $slug ): ?Location {
		$normalised = strtolower( trim( $slug ) );

		if ( '' === $normalised || 1 !== preg_match( '/^[a-z0-9-]+$/', $normalised ) ) {
			return null;
		}

		$words = array_values( array_filter( explode( '-', $normalised ), static fn( $w ) => '' !== $w ) );

		if ( array() === $words ) {
			return null;
		}

		// "<state-name>", e.g. "north-carolina".
		$state_code = States::code_from_name( implode( ' ', $words ) );

		if ( '' !== $state_code ) {
			return self::create( array( 'state_code' => $state_code ) );
		}

		if ( count( $words ) < 2 ) {
			return null;
		}

		// "<city>-<st>", e.g. "toledo-oh".
		$last = strtoupper( $words[ count( $words ) - 1 ] );

		if ( States::is_code( $last ) ) {
			return self::create(
				array(
					'city'       => self::title_case( implode( ' ', array_slice( $words, 0, -1 ) ) ),
					'state_code' => $last,
				)
			);
		}

		/*
		 * "<city>-<state-name>", e.g. "newark-new-jersey". Longest state name
		 * first, so "charleston-west-virginia" is not read as
		 * "Charleston West, Virginia".
		 */
		for ( $size = min( 4, count( $words ) - 1 ); $size >= 1; $size-- ) {
			$code = States::code_from_name( implode( ' ', array_slice( $words, -$size ) ) );

			if ( '' !== $code ) {
				return self::create(
					array(
						'city'       => self::title_case( implode( ' ', array_slice( $words, 0, -$size ) ) ),
						'state_code' => $code,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Capitalises each word.
	 *
	 * @param string $value Lowercase words.
	 */
	private static function title_case( string $value ): string {
		$words = array_filter( explode( ' ', $value ), static fn( $w ) => '' !== $w );

		return implode(
			' ',
			array_map(
				static fn( string $word ): string => strtoupper( substr( $word, 0, 1 ) ) . substr( $word, 1 ),
				$words
			)
		);
	}
}
