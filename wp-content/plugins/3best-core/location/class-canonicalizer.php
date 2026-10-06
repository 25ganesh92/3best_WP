<?php
/**
 * Provider result -> routable canonical location.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * The last step between a provider result and something we can route to.
 *
 * Most of the work has already happened: every provider runs its rows through
 * Normalizer::create(), so a ZIP, a landmark or a neighbourhood arrives already
 * carrying its city/state slug. That is why "07102" routes to
 * /plumber-in-newark-nj/ and "Times Square" to /plumber-in-new-york-ny/ rather
 * than minting /plumber-in-07102/ or /plumber-in-times-square/.
 *
 * What is left is the curated ZIP override. A geocoder does not place every ZIP
 * in a city — OpenStreetMap returns 90001 with only a county — and the
 * catalogue does. Where a curated entry exists it wins, borrowing the
 * provider's coordinates, which the curated record has none of.
 */
final class Canonicalizer {

	/**
	 * Resolves a provider result to the location it should route to.
	 *
	 * @param Location $result Normalised provider result.
	 */
	public static function resolve( Location $result ): ?Location {
		if ( 'postcode' === $result->source_type ) {
			$curated = Catalog::by_zip( $result->zip );

			if ( null !== $curated ) {
				return $curated->with_coordinates( $result->latitude, $result->longitude );
			}
		}

		// A result that could not be placed in a US state has no slug and is
		// not routable. Normalizer::create() already returns null for those, so
		// this is a guard rather than an expected branch.
		return '' !== $result->slug ? $result : null;
	}
}
