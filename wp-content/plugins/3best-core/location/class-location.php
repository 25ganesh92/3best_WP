<?php
/**
 * The normalised location value object.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * The one shape the whole application works with.
 *
 * This is OUR vocabulary, not a provider's. Nominatim's address fields, Google
 * Places' address_components and anything that comes later are all mapped onto
 * this object, so nothing downstream — routing, the lead form, SEO output —
 * depends on which geocoder answered. A raw provider response never escapes the
 * provider class.
 *
 * It carries two things at once, deliberately:
 *
 *   - the CANONICAL SEO location (slug, city, state_code) that drives routing
 *     and metadata, and
 *   - the PRECISE detail the user actually searched with (zip, coordinates,
 *     the landmark's own name), kept for lead routing and never used in a URL.
 *
 * `slug` is always routable — "city-st" or "state-name", never a ZIP.
 *
 * Immutable: built only by Normalizer::create() or Normalizer::canonicalize(),
 * so an invalid combination cannot be assembled by hand somewhere else.
 */
final class Location implements \JsonSerializable {

	/**
	 * Location types this application recognises.
	 *
	 * Provider classifications are mapped onto this list.
	 */
	public const TYPES = array(
		'state',
		'city',
		'town',
		'village',
		'neighborhood',
		'postcode',
		'landmark',
		'address',
	);

	/**
	 * @param string $id            Stable identifier; the slug, or a provider reference.
	 * @param string $display_name  "Newark, NJ", "Newark, NJ 07102" or "New Jersey, USA".
	 * @param string $name          The place's own name when it differs from the canonical area.
	 * @param string $city          Settlement name, or '' for a state-level location.
	 * @param string $state         Full state name.
	 * @param string $state_code    Two-letter USPS code, or '' when unresolvable.
	 * @param string $zip           Precise ZIP when meaningful; '' otherwise.
	 * @param string $country       Country name.
	 * @param string $country_code  ISO 3166-1 alpha-2, always 'US' here.
	 * @param string $latitude      Decimal string, or ''.
	 * @param string $longitude     Decimal string, or ''.
	 * @param string $slug          Canonical SEO slug. Never a ZIP.
	 * @param string $location_type What this routes as: 'city' or 'state'.
	 * @param string $source_type   What the user actually matched. Display only.
	 * @param string $provider_ref  Provider's own reference, for support and debugging.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $display_name,
		public readonly string $name,
		public readonly string $city,
		public readonly string $state,
		public readonly string $state_code,
		public readonly string $zip,
		public readonly string $country,
		public readonly string $country_code,
		public readonly string $latitude,
		public readonly string $longitude,
		public readonly string $slug,
		public readonly string $location_type,
		public readonly string $source_type,
		public readonly string $provider_ref = ''
	) {}

	/**
	 * Returns a copy with coordinates replaced.
	 *
	 * Used when a curated catalogue entry, which has no coordinates of its own,
	 * is matched by a provider result that does.
	 *
	 * @param string $latitude  Decimal string.
	 * @param string $longitude Decimal string.
	 */
	public function with_coordinates( string $latitude, string $longitude ): self {
		return new self(
			$this->id,
			$this->display_name,
			$this->name,
			$this->city,
			$this->state,
			$this->state_code,
			$this->zip,
			$this->country,
			$this->country_code,
			$latitude,
			$longitude,
			$this->slug,
			$this->location_type,
			$this->source_type,
			$this->provider_ref
		);
	}

	/**
	 * The key that decides whether two results are the same suggestion.
	 *
	 * "Newark" and "07102" route to the same page but are different selections —
	 * only one of them carries a ZIP — so both rows are kept.
	 */
	public function dedupe_key(): string {
		return $this->slug . '|' . $this->zip;
	}

	/**
	 * The shape sent to the browser.
	 *
	 * Only what the frontend needs. No provider metadata, no internal fields,
	 * no credentials. Keys are camelCase to match the contract the React client
	 * already consumes, so the browser code ports across unchanged.
	 *
	 * @return array<string,string>
	 */
	public function to_response(): array {
		return array(
			'id'           => $this->id,
			'displayName'  => $this->display_name,
			'name'         => $this->name,
			'city'         => $this->city,
			'state'        => $this->state,
			'stateCode'    => $this->state_code,
			'zip'          => $this->zip,
			'country'      => $this->country,
			'countryCode'  => $this->country_code,
			'latitude'     => $this->latitude,
			'longitude'    => $this->longitude,
			'slug'         => $this->slug,
			'type'         => $this->location_type,
			'locationType' => $this->location_type,
			'sourceType'   => '' !== $this->source_type ? $this->source_type : $this->location_type,
		);
	}

	/**
	 * Full internal representation, for caching and storage.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'display_name'  => $this->display_name,
			'name'          => $this->name,
			'city'          => $this->city,
			'state'         => $this->state,
			'state_code'    => $this->state_code,
			'zip'           => $this->zip,
			'country'       => $this->country,
			'country_code'  => $this->country_code,
			'latitude'      => $this->latitude,
			'longitude'     => $this->longitude,
			'slug'          => $this->slug,
			'location_type' => $this->location_type,
			'source_type'   => $this->source_type,
			'provider_ref'  => $this->provider_ref,
		);
	}

	/**
	 * Rebuilds a Location from to_array(), for the cache.
	 *
	 * Defensive: a cache entry written by an older plugin version, or anything
	 * malformed, returns null rather than a half-built object.
	 *
	 * @param mixed $data Candidate array.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		foreach ( array( 'id', 'display_name', 'slug', 'location_type' ) as $required ) {
			if ( ! isset( $data[ $required ] ) || ! is_string( $data[ $required ] ) || '' === $data[ $required ] ) {
				return null;
			}
		}

		if ( ! in_array( $data['location_type'], self::TYPES, true ) ) {
			return null;
		}

		$get = static fn( string $key ): string => isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';

		return new self(
			$data['id'],
			$data['display_name'],
			$get( 'name' ),
			$get( 'city' ),
			$get( 'state' ),
			$get( 'state_code' ),
			$get( 'zip' ),
			'' !== $get( 'country' ) ? $get( 'country' ) : 'United States',
			'' !== $get( 'country_code' ) ? $get( 'country_code' ) : 'US',
			$get( 'latitude' ),
			$get( 'longitude' ),
			$data['slug'],
			$data['location_type'],
			$get( 'source_type' ),
			$get( 'provider_ref' )
		);
	}

	/**
	 * Serialises as the response shape.
	 *
	 * @return array<string,string>
	 */
	public function jsonSerialize(): array {
		return $this->to_response();
	}
}
