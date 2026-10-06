<?php
/**
 * Location search endpoint.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Location\Location_Service;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Security\Request_Guard;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/threebest/v1/locations?q=…
 *
 * The browser's only route to a geocoder. It never talks to Nominatim or Google
 * directly, which is what keeps provider choice, credentials and rate-limit
 * handling on the server — and is why swapping the provider later changes one
 * class rather than any client code.
 *
 * Public consumer endpoint, so it uses ACCESS_PUBLIC_NONCE rather than a
 * capability: an anonymous visitor using the search form has no capabilities at
 * all. Throttled through the location_search bucket.
 *
 * The response is the Phase 2 envelope with a results array:
 *
 *   { "success": true, "results": [ … ] }
 *
 * which is a superset of the { results: [...] } shape the React client already
 * reads, so the browser code ports across unchanged.
 */
final class Locations_Controller extends Controller {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'     => '/locations',
				'methods'  => 'GET',
				'callback' => array( $this, 'search' ),
				'access'   => Request_Guard::ACCESS_PUBLIC_NONCE,
				'throttle' => 'location_search',
				'args'     => array(
					'q' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Search text: a city, state, ZIP code or landmark.',
					),
				),
			),
		);
	}

	/**
	 * Handles a search request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 *
	 * @throws Api_Exception On a query that is too short, or a total provider failure.
	 */
	public function search( WP_REST_Request $request ): array {
		// Sanitise before anything else looks at it: control characters
		// stripped, whitespace collapsed, length capped at 120.
		$query = Sanitizer::search_query( $request->get_param( 'q' ) );

		$minimum = Location_Service::min_query_length();

		if ( strlen( $query ) < $minimum ) {
			throw Api_Exception::bad_request(
				sprintf(
					/* translators: %d: minimum number of characters */
					_n(
						'Enter at least %d character.',
						'Enter at least %d characters.',
						$minimum,
						'3best-core'
					),
					$minimum
				)
			);
		}

		/*
		 * Location_Service throws Api_Exception::upstream() only when there is
		 * nothing at all to offer. The base controller turns that into a 502
		 * with a safe message and keeps the provider's own words in the log.
		 */
		$locations = Location_Service::search( $query );

		$results = array();

		foreach ( $locations as $location ) {
			$parts = Labels::display_parts( $location );

			// Only the fields the frontend needs. No provider metadata, no
			// internal identifiers, no credentials.
			$results[] = array_merge(
				$location->to_response(),
				array(
					'primary'   => $parts['primary'],
					'secondary' => $parts['secondary'],
				)
			);
		}

		return array( 'results' => $results );
	}
}
