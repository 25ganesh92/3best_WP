<?php
/**
 * US state name / USPS code mapping.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for US state names and codes.
 *
 * Ported from src/lib/location/states.ts. Nothing else in the plugin may carry
 * a second state table: a duplicate that drifts would mint different slugs for
 * the same place, which is the one failure the canonicalisation design exists
 * to prevent.
 *
 * A provider usually supplies ISO 3166-2 ("US-NJ"), which is the preferred
 * source. This table is the fallback for results carrying only a state name.
 * If neither resolves, the state code stays empty — it is never guessed.
 */
final class States {

	/**
	 * Lowercased state name => USPS code. 50 states, DC and 5 territories.
	 *
	 * @var array<string,string>
	 */
	private const NAME_TO_CODE = array(
		'alabama'                        => 'AL',
		'alaska'                         => 'AK',
		'arizona'                        => 'AZ',
		'arkansas'                       => 'AR',
		'california'                     => 'CA',
		'colorado'                       => 'CO',
		'connecticut'                    => 'CT',
		'delaware'                       => 'DE',
		'district of columbia'           => 'DC',
		'florida'                        => 'FL',
		'georgia'                        => 'GA',
		'hawaii'                         => 'HI',
		'idaho'                          => 'ID',
		'illinois'                       => 'IL',
		'indiana'                        => 'IN',
		'iowa'                           => 'IA',
		'kansas'                         => 'KS',
		'kentucky'                       => 'KY',
		'louisiana'                      => 'LA',
		'maine'                          => 'ME',
		'maryland'                       => 'MD',
		'massachusetts'                  => 'MA',
		'michigan'                       => 'MI',
		'minnesota'                      => 'MN',
		'mississippi'                    => 'MS',
		'missouri'                       => 'MO',
		'montana'                        => 'MT',
		'nebraska'                       => 'NE',
		'nevada'                         => 'NV',
		'new hampshire'                  => 'NH',
		'new jersey'                     => 'NJ',
		'new mexico'                     => 'NM',
		'new york'                       => 'NY',
		'north carolina'                 => 'NC',
		'north dakota'                   => 'ND',
		'ohio'                           => 'OH',
		'oklahoma'                       => 'OK',
		'oregon'                         => 'OR',
		'pennsylvania'                   => 'PA',
		'rhode island'                   => 'RI',
		'south carolina'                 => 'SC',
		'south dakota'                   => 'SD',
		'tennessee'                      => 'TN',
		'texas'                          => 'TX',
		'utah'                           => 'UT',
		'vermont'                        => 'VT',
		'virginia'                       => 'VA',
		'washington'                     => 'WA',
		'west virginia'                  => 'WV',
		'wisconsin'                      => 'WI',
		'wyoming'                        => 'WY',
		'puerto rico'                    => 'PR',
		'guam'                           => 'GU',
		'american samoa'                 => 'AS',
		'u.s. virgin islands'            => 'VI',
		'united states virgin islands'   => 'VI',
		'northern mariana islands'       => 'MP',
	);

	/**
	 * USPS code => canonical display name.
	 *
	 * Built once and memoised. Where two names share a code (the Virgin
	 * Islands have two spellings) the FIRST is kept as canonical, so the
	 * display name is stable regardless of array ordering. The React version
	 * let the last win, which made "United States Virgin Islands" canonical;
	 * "U.S. Virgin Islands" reads better and neither affects a slug, since
	 * territories are not in the curated catalogue.
	 *
	 * @var array<string,string>|null
	 */
	private static ?array $code_to_name = null;

	/**
	 * "New Jersey" => "NJ". Empty string when unknown — never guessed.
	 *
	 * @param string $state_name State name.
	 */
	public static function code_from_name( string $state_name ): string {
		$key = strtolower( trim( $state_name ) );

		if ( '' === $key ) {
			return '';
		}

		return self::NAME_TO_CODE[ $key ] ?? '';
	}

	/**
	 * "NJ" => "New Jersey". Empty string when unknown.
	 *
	 * @param string $state_code USPS code.
	 */
	public static function name_from_code( string $state_code ): string {
		$key = strtoupper( trim( $state_code ) );

		if ( '' === $key ) {
			return '';
		}

		return self::code_to_name()[ $key ] ?? '';
	}

	/**
	 * Whether a value is a known USPS state code.
	 *
	 * @param string $value Candidate code.
	 */
	public static function is_code( string $value ): bool {
		return '' !== self::name_from_code( $value );
	}

	/**
	 * Reads a state code out of an ISO 3166-2 subdivision string.
	 *
	 * "US-NJ" => "NJ". Anything else, including a non-US subdivision, => "".
	 *
	 * @param string $iso ISO 3166-2 value.
	 */
	public static function code_from_iso( string $iso ): string {
		$iso = trim( $iso );

		if ( '' === $iso || 1 !== preg_match( '/^US-([A-Za-z]{2})$/', $iso, $matches ) ) {
			return '';
		}

		$code = strtoupper( $matches[1] );

		return self::is_code( $code ) ? $code : '';
	}

	/**
	 * Every USPS code this table knows.
	 *
	 * @return string[]
	 */
	public static function all_codes(): array {
		return array_keys( self::code_to_name() );
	}

	/**
	 * Builds and memoises the reverse map.
	 *
	 * @return array<string,string>
	 */
	private static function code_to_name(): array {
		if ( null !== self::$code_to_name ) {
			return self::$code_to_name;
		}

		$map = array();

		foreach ( self::NAME_TO_CODE as $name => $code ) {
			if ( isset( $map[ $code ] ) ) {
				continue;
			}

			// Title case, preserving the dots in "U.S. Virgin Islands".
			$map[ $code ] = preg_replace_callback(
				'/\b[a-z]/',
				static fn( array $m ): string => strtoupper( $m[0] ),
				$name
			) ?? $name;
		}

		self::$code_to_name = $map;

		return $map;
	}
}
