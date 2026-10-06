<?php
/**
 * OpenStreetMap Nominatim provider — DEVELOPMENT ONLY.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Geocoding against the public OpenStreetMap Nominatim endpoint.
 *
 * DEVELOPMENT DEPENDENCY, NOT PRODUCTION INFRASTRUCTURE. The public endpoint
 * has a strict usage policy — roughly one request per second, no heavy use, a
 * required identifying User-Agent — and production traffic will get the IP
 * blocked. Confirmed decision 9 is Google Places for production; this class
 * exists so the system is testable before those credentials arrive.
 *
 * All Nominatim-specific knowledge is confined here: the query parameters, the
 * jsonv2 response shape, and the category/type/addresstype vocabulary. Nothing
 * outside this file knows any of it.
 */
final class Nominatim_Provider implements Location_Provider {

	private const DEFAULT_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
	private const TIMEOUT_SECONDS  = 6;

	/**
	 * How far up the suggestion list each kind of place sits.
	 *
	 * Settlements first, then postcodes, then neighborhoods, landmarks and
	 * finally street addresses.
	 */
	private const TYPE_RANK = array(
		'state'        => 0,
		'city'         => 1,
		'town'         => 2,
		'village'      => 3,
		'postcode'     => 4,
		'neighborhood' => 5,
		'landmark'     => 6,
		'address'      => 7,
	);

	/**
	 * Categories that are never a useful service area.
	 */
	private const REJECTED_CATEGORIES = array(
		'craft',
		'shop',
		'office',
		'amenity',
		'healthcare',
		'man_made',
		'military',
		'emergency',
		'railway',
		'waterway',
	);

	/**
	 * Administrative levels we never route to.
	 */
	private const REJECTED_ADDRESS_TYPES = array( 'county', 'state_district', 'region', 'province' );

	/**
	 * Nominatim address keys that are themselves a settlement.
	 */
	private const SETTLEMENT_ADDRESS_TYPES = array( 'city', 'town', 'village', 'hamlet', 'municipality', 'locality' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'nominatim';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The public endpoint needs no credentials, so this is always available.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Required by the Open Database Licence whenever OSM data is displayed.
	 */
	public function attribution(): string {
		return __( 'Location data © OpenStreetMap contributors', '3best-core' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query Sanitised search text.
	 * @param int    $limit Maximum results wanted.
	 * @return Location[]
	 *
	 * @throws Api_Exception On transport, HTTP or payload failure.
	 */
	public function search( string $query, int $limit = 5 ): array {
		$endpoint = (string) Settings::get( 'nominatim_base_url', self::DEFAULT_ENDPOINT );

		$url = add_query_arg(
			array(
				'q'              => rawurlencode( $query ),
				'format'         => 'jsonv2',
				'addressdetails' => '1',
				/*
				 * Ask for more than we show. Businesses, counties and duplicates
				 * are filtered out below, so a bare limit would often leave too
				 * few usable rows.
				 */
				'limit'          => (string) min( 20, max( $limit * 3, 10 ) ),
				// US-only launch, enforced at the provider as well as in
				// normalisation, so a non-US result never even arrives.
				'countrycodes'   => 'us',
			),
			$endpoint
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 2,
				'headers'     => array(
					// Nominatim's policy requires a descriptive, identifying UA.
					'User-Agent'      => (string) Settings::get( 'nominatim_user_agent' ),
					'Accept'          => 'application/json',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw Api_Exception::upstream(
				'',
				array(
					'provider' => $this->name(),
					'stage'    => 'transport',
					'detail'   => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			throw Api_Exception::upstream(
				'',
				array(
					'provider' => $this->name(),
					'stage'    => 'http',
					'status'   => $status,
				)
			);
		}

		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) ) {
			throw Api_Exception::upstream(
				'',
				array(
					'provider' => $this->name(),
					'stage'    => 'payload',
					'detail'   => 'Response was not a JSON array.',
				)
			);
		}

		return $this->map_results( $payload );
	}

	/**
	 * Maps raw rows onto canonical Locations, dropping what we cannot use.
	 *
	 * @param array<int,mixed> $rows Decoded response.
	 * @return Location[]
	 */
	private function map_results( array $rows ): array {
		$seen    = array();
		$results = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$location = $this->map_row( $row );

			if ( null === $location ) {
				continue;
			}

			/*
			 * One row per canonical place plus ZIP: "Times Square" the road, the
			 * square and the artwork are all New York, NY 10036.
			 */
			$key = $location->dedupe_key();

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$results[]    = $location;
		}

		usort(
			$results,
			static function ( Location $a, Location $b ): int {
				$rank_a = self::TYPE_RANK[ $a->source_type ] ?? 9;
				$rank_b = self::TYPE_RANK[ $b->source_type ] ?? 9;

				return $rank_a <=> $rank_b;
			}
		);

		return $results;
	}

	/**
	 * Maps one raw Nominatim row onto a Location.
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	private function map_row( array $row ): ?Location {
		$source_type = $this->map_location_type( $row );

		if ( null === $source_type ) {
			return null;
		}

		$address = isset( $row['address'] ) && is_array( $row['address'] ) ? $row['address'] : array();

		$field = static function ( string $key ) use ( $address ): string {
			return isset( $address[ $key ] ) && is_scalar( $address[ $key ] ) ? (string) $address[ $key ] : '';
		};

		$address_type = strtolower( isset( $row['addresstype'] ) && is_scalar( $row['addresstype'] ) ? (string) $row['addresstype'] : '' );
		$row_name     = isset( $row['name'] ) && is_scalar( $row['name'] ) ? (string) $row['name'] : '';

		/*
		 * When the result IS a settlement its own name wins over any containing
		 * one: "Beverly Hills" (town) inside "Southfield Township" (city) is
		 * Beverly Hills.
		 */
		$own_settlement = in_array( $address_type, self::SETTLEMENT_ADDRESS_TYPES, true )
			? ( '' !== $field( $address_type ) ? $field( $address_type ) : $row_name )
			: '';

		$display_name = isset( $row['display_name'] ) && is_scalar( $row['display_name'] ) ? (string) $row['display_name'] : '';
		$first_part   = '' !== $display_name ? trim( explode( ',', $display_name )[0] ) : '';

		$osm_type = isset( $row['osm_type'] ) && is_scalar( $row['osm_type'] ) ? (string) $row['osm_type'] : 'n';
		$osm_id   = isset( $row['osm_id'] ) && is_scalar( $row['osm_id'] ) ? (string) $row['osm_id'] : '';
		$place_id = isset( $row['place_id'] ) && is_scalar( $row['place_id'] ) ? (string) $row['place_id'] : '';
		$ref      = '' !== $osm_id ? $osm_id : $place_id;

		$location = Normalizer::create(
			array(
				'city'         => '' !== $own_settlement ? $own_settlement : $field( 'city' ),
				'town'         => $field( 'town' ),
				'village'      => $field( 'village' ),
				'municipality' => $field( 'municipality' ),
				'locality'     => $field( 'locality' ),
				'state'        => $field( 'state' ),
				'state_code'   => States::code_from_iso( $field( 'ISO3166-2-lvl4' ) ),
				'zip'          => $field( 'postcode' ),
				'country'      => '' !== $field( 'country' ) ? $field( 'country' ) : 'United States',
				'country_code' => strtoupper( $field( 'country_code' ) ),
				'source_type'  => $source_type,
				'name'         => '' !== $row_name ? $row_name : $first_part,
				'latitude'     => isset( $row['lat'] ) && is_scalar( $row['lat'] ) ? (string) $row['lat'] : '',
				'longitude'    => isset( $row['lon'] ) && is_scalar( $row['lon'] ) ? (string) $row['lon'] : '',
				'id'           => 'osm-' . $osm_type . '-' . ( '' !== $ref ? $ref : 'x' ),
				'provider_ref' => $ref,
			)
		);

		return $location;
	}

	/**
	 * Maps a Nominatim category/type/addresstype triple onto our vocabulary.
	 *
	 * Returns null for results we do not want to offer at all: businesses,
	 * shops, offices, counties and other administrative sub-regions.
	 *
	 * @param array<string,mixed> $row Raw row.
	 */
	private function map_location_type( array $row ): ?string {
		$get = static function ( string $key ) use ( $row ): string {
			return strtolower( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '' );
		};

		$category     = $get( 'category' );
		$type         = $get( 'type' );
		$address_type = $get( 'addresstype' );

		if ( in_array( $category, self::REJECTED_CATEGORIES, true ) ) {
			return null;
		}

		if ( in_array( $address_type, self::REJECTED_ADDRESS_TYPES, true ) ) {
			return null;
		}

		if ( 'state' === $address_type || 'state' === $type ) {
			return 'state';
		}

		if ( 'city' === $address_type ) {
			return 'city';
		}

		if ( 'town' === $address_type ) {
			return 'town';
		}

		if ( in_array( $address_type, array( 'village', 'hamlet' ), true ) ) {
			return 'village';
		}

		if ( 'postcode' === $address_type || 'postcode' === $type ) {
			return 'postcode';
		}

		if ( in_array( $address_type, array( 'suburb', 'neighbourhood', 'quarter', 'borough', 'city_district' ), true ) ) {
			return 'neighborhood';
		}

		if ( in_array( $category, array( 'tourism', 'leisure', 'historic', 'natural', 'aeroway' ), true ) ) {
			return 'landmark';
		}

		if ( 'square' === $address_type || 'square' === $type ) {
			return 'landmark';
		}

		if ( in_array( $category, array( 'highway', 'building', 'place' ), true ) ) {
			return 'address';
		}

		return null;
	}
}
