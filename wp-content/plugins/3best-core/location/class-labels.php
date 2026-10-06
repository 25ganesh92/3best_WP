<?php
/**
 * Human-readable location labels.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Location;

defined( 'ABSPATH' ) || exit;

/**
 * How a location reads in headings, titles, breadcrumbs and copy.
 *
 * Ported from src/lib/location/labels.ts. Kept separate from the Location
 * object because presentation and identity change for different reasons.
 */
final class Labels {

	/**
	 * The canonical label. Never includes a ZIP: the page identity is the
	 * city/state.
	 *
	 *   state -> "New Jersey"
	 *   city  -> "Dallas, TX"
	 *
	 * @param Location $location Location.
	 */
	public static function label( Location $location ): string {
		if ( 'state' === $location->location_type || '' === $location->city ) {
			return $location->state;
		}

		return '' !== $location->state_code
			? $location->city . ', ' . $location->state_code
			: $location->city . ', ' . $location->state;
	}

	/**
	 * The canonical label plus the precise ZIP when we have one.
	 *
	 * For places that describe the user's request rather than the page:
	 * "Newark, NJ 07102".
	 *
	 * @param Location $location Location.
	 */
	public static function label_with_zip( Location $location ): string {
		$label = self::label( $location );

		return '' !== $location->zip ? $label . ' ' . $location->zip : $label;
	}

	/**
	 * Two-line presentation for one autocomplete row.
	 *
	 *   Newark            07030                 Times Square
	 *   New Jersey, USA   Hoboken, New Jersey   New York, NY, USA
	 *
	 * Presents what the user actually matched, while routing stays canonical.
	 *
	 * @param Location $location Location.
	 * @return array{primary:string,secondary:string}
	 */
	public static function display_parts( Location $location ): array {
		$type = '' !== $location->source_type ? $location->source_type : $location->location_type;

		if ( 'state' === $type ) {
			return array(
				'primary'   => '' !== $location->state ? $location->state : $location->display_name,
				'secondary' => 'USA',
			);
		}

		if ( 'postcode' === $type && '' !== $location->zip ) {
			return array(
				'primary'   => $location->zip,
				'secondary' => '' !== $location->city
					? $location->city . ', ' . $location->state
					: ( '' !== $location->state ? $location->state : 'USA' ),
			);
		}

		if ( 'postcode' === $type ) {
			return array(
				'primary'   => '' !== $location->name ? $location->name : $location->display_name,
				'secondary' => '' !== $location->state ? $location->state : 'USA',
			);
		}

		if ( in_array( $type, array( 'landmark', 'neighborhood', 'address' ), true ) ) {
			$area = '' !== $location->city
				? $location->city . ', ' . ( '' !== $location->state_code ? $location->state_code : $location->state )
				: $location->state;

			return array(
				'primary'   => '' !== $location->name ? $location->name : $location->display_name,
				'secondary' => '' !== $area ? $area . ', USA' : 'USA',
			);
		}

		return array(
			'primary'   => '' !== $location->city ? $location->city : ( '' !== $location->name ? $location->name : $location->display_name ),
			'secondary' => '' !== $location->state ? $location->state . ', USA' : 'USA',
		);
	}
}
