<?php
/**
 * Geocoding provider contract.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * The seam the UI never sees through.
 *
 * An implementation talks to one geocoder and is responsible for exactly two
 * things: making the request, and mapping the response onto Location objects
 * via Normalizer::create(). A raw provider payload must never leave the class.
 *
 * Because of that, swapping Nominatim for Google Places changes one class. The
 * REST controller, the location service, the canonicaliser, the slug rules and
 * the browser code all stay as they are.
 *
 * Implementations are SERVER-SIDE ONLY. They hold endpoint configuration and,
 * in production, credentials — which is why the browser calls our REST route
 * and never a geocoder directly.
 */
interface Location_Provider {

	/**
	 * Machine-readable provider name, e.g. 'nominatim', 'google_places'.
	 */
	public function name(): string;

	/**
	 * Whether this provider has everything it needs to run.
	 *
	 * A provider requiring an API key returns false until one is configured, so
	 * the factory can refuse to select it rather than failing at request time.
	 */
	public function is_available(): bool;

	/**
	 * Attribution text this provider's licence requires, or '' when none.
	 *
	 * Attribution is provider-specific: OpenStreetMap's licence requires a
	 * visible credit, Google's terms require different wording. Leaving it as a
	 * fixed string elsewhere would mean swapping the provider silently produced
	 * a false or missing credit, so it belongs on the provider itself.
	 */
	public function attribution(): string;

	/**
	 * Searches for US locations matching a query.
	 *
	 * Results are already normalised: each is a canonical Location with the
	 * provider's classification recorded as source_type. Ordering is the
	 * provider's preference; the caller may re-rank.
	 *
	 * Must not throw for "no results" — that is an empty array. Throw only when
	 * the provider itself failed.
	 *
	 * @param string $query Sanitised search text.
	 * @param int    $limit Maximum results wanted.
	 * @return Location[]
	 *
	 * @throws \ThreeBest\Core\Api_Exception On transport, HTTP or payload failure.
	 */
	public function search( string $query, int $limit = 5 ): array;
}
