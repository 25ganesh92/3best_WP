<?php
/**
 * Google Places provider — NOT YET IMPLEMENTED.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The production geocoding provider (confirmed decision 9).
 *
 * DELIBERATELY UNIMPLEMENTED. No Google Maps Platform credentials exist yet, so
 * any mapping written now could not be executed once, and untested code that
 * looks finished is worse than an honest gap — it gets trusted. This class
 * therefore refuses to run and documents exactly what the implementation owes
 * the rest of the system, so writing it is a contained task rather than an
 * archaeology exercise.
 *
 * The factory will not select this provider while is_available() is false, so
 * a misconfigured site falls back to the development provider rather than
 * failing every search.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE IMPLEMENTATION MUST DO
 * ---------------------------------------------------------------------------
 *
 * 1. Request. Places Autocomplete for suggestions, then Place Details for the
 *    structured address of a chosen suggestion — Autocomplete alone does not
 *    return address components. Restrict with components=country:us and pass a
 *    session token so the two calls bill as one session.
 *
 * 2. Map address_components onto the neutral input array that
 *    Normalizer::create() documents. The component types translate as:
 *
 *      locality                      -> city
 *      postal_town                   -> city        (rare in the US)
 *      sublocality / neighborhood    -> NOT a settlement; see source_type below
 *      administrative_area_level_3   -> municipality
 *      administrative_area_level_1   -> state / state_code (short_name is the
 *                                       USPS code; pass it as state_code)
 *      postal_code                   -> zip
 *      country                       -> country_code (short_name)
 *
 *    Use long_name for display values and short_name for the state code. Never
 *    infer a state code from a name; States::code_from_name() already does that
 *    correctly when only a name is available.
 *
 * 3. Classify each result as one of Location::TYPES and pass it as source_type.
 *    Google's own `types` array maps roughly as:
 *
 *      locality                        -> city
 *      administrative_area_level_1     -> state
 *      postal_code                     -> postcode
 *      neighborhood / sublocality      -> neighborhood
 *      point_of_interest / establishment / tourist_attraction / park / airport
 *                                      -> landmark
 *      street_address / premise / route -> address
 *
 *    Return null (drop the row) for business-like results with no useful
 *    service area, matching what the Nominatim provider rejects.
 *
 * 4. Return Location objects only. A raw Google payload must never leave this
 *    class, and the API key must never reach a response or the browser.
 *
 * 5. Throw Api_Exception::upstream() for transport, HTTP and payload failures,
 *    with the provider name and stage in the log context and nothing
 *    identifying in the public message. Treat Google's ZERO_RESULTS status as
 *    an empty array, not a failure.
 *
 * Everything else — canonicalisation, ZIP handling, the landmark rule, slug
 * generation, caching, throttling — already works and must not be touched:
 * those live in Normalizer, Canonicalizer and Location_Service, none of which
 * know a provider exists.
 */
final class Google_Places_Provider implements Location_Provider {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'google_places';
	}

	/**
	 * {@inheritDoc}
	 *
	 * False on both counts: there is no API key, and the mapping above is not
	 * written yet. The second condition is what stops a key alone from silently
	 * switching production onto an unimplemented provider.
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Google requires its own attribution, which differs from OpenStreetMap's
	 * and depends on how results are presented. Confirm the exact requirement
	 * against the Maps Platform terms when implementing search().
	 */
	public function attribution(): string {
		return '';
	}

	/**
	 * Whether a Google Maps Platform key is configured.
	 *
	 * Reported by the admin status screen so the remaining production
	 * dependency is visible without revealing the value.
	 */
	public function has_credentials(): bool {
		return Settings::is_configured( 'google_maps_api_key' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query Sanitised search text.
	 * @param int    $limit Maximum results wanted.
	 * @return Location[]
	 *
	 * @throws Api_Exception Always, until the mapping above is implemented.
	 */
	public function search( string $query, int $limit = 5 ): array {
		unset( $query, $limit );

		throw Api_Exception::upstream(
			'',
			array(
				'provider' => $this->name(),
				'stage'    => 'not_implemented',
				'detail'   => 'Google Places mapping is not implemented; see the class docblock.',
			)
		);
	}
}
