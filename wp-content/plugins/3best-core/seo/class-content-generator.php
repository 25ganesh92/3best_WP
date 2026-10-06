<?php
/**
 * Landing page copy generation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the copy for one service/location page.
 *
 * Ported from src/lib/content.ts. One dynamic route serves every combination,
 * so the words are generated from the two canonical records rather than written
 * per page — but they are generated to read like a person wrote them.
 *
 * Three rules carried over from the React implementation, all of them SEO
 * decisions as much as editorial ones:
 *
 *  - No claim we cannot back up. No counts, no ratings, no reviews, no prices,
 *    no availability, no guarantees. The copy describes what the page does, not
 *    what the market looks like.
 *  - No keyword stuffing. The place name appears where a person would say it
 *    and nowhere else.
 *  - Grammar that survives generation. "a plumbing service" but "an HVAC
 *    service" and "an IT service"; "AC repair" keeps its capitals while
 *    "emergency plumbing" does not.
 *
 * An editor can override the title and description per combination through the
 * sparse service_locations row; everything else is generated.
 */
final class Content_Generator {

	/**
	 * Acronym initials that take "an" rather than "a".
	 */
	private const AN_LETTERS = array( 'A', 'E', 'F', 'H', 'I', 'L', 'M', 'N', 'O', 'R', 'S', 'X' );

	/**
	 * Builds the full content set for a service/location pair.
	 *
	 * @param array<string,mixed> $service  Canonical service record.
	 * @param Location            $location Canonical location.
	 * @return array<string,mixed>
	 */
	public static function build( array $service, Location $location ): array {
		$place   = Labels::label( $location );
		$term    = (string) $service['service_term'];
		$article = self::indefinite_article( $term );

		return array(
			'h1'    => sprintf(
				/* translators: 1: professional plural, 2: place label */
				__( 'Find Trusted %1$s in %2$s', '3best-core' ),
				$service['professional_plural'],
				$place
			),
			'intro' => sprintf(
				/* translators: %s: service term, e.g. "plumbing" */
				__( 'Tell us what %s service you need and we’ll help connect you with qualified professionals serving your area.', '3best-core' ),
				$term
			),

			'services_eyebrow' => sprintf(
				/* translators: %s: service term */
				__( 'Popular %s services', '3best-core' ),
				$term
			),
			'services_heading' => sprintf(
				/* translators: %s: service term */
				__( 'What kind of %s help do you need?', '3best-core' ),
				$term
			),
			'services_intro'   => __( 'Pick the option closest to your project. You can change it later in the request form.', '3best-core' ),

			'subcategory_placeholder' => sprintf(
				/* translators: 1: "a" or "an", 2: service term */
				__( 'Select %1$s %2$s service', '3best-core' ),
				$article,
				$term
			),

			'paragraphs' => array(
				sprintf(
					/* translators: 1: audience, 2: place, 3: service term, 4: example services, 5: service term */
					__( 'For %1$s in %2$s, finding the right %3$s professional can make all the difference. Whether you need %4$s, or other %5$s services, tell us what you need and we’ll help connect you with professionals serving your area.', '3best-core' ),
					self::audience_for( (string) $service['category'] ),
					$place,
					$term,
					self::list_subcategories( $service, 3 ),
					$term
				),
				sprintf(
					/* translators: %s: scope sentence */
					__( 'Start by choosing the service that matches your project, add your contact details and verify your mobile number. %s', '3best-core' ),
					self::scope_sentence( $location )
				),
			),

			'benefits' => array(
				array(
					'title'       => __( 'Local professionals', '3best-core' ),
					'description' => sprintf(
						/* translators: %s: place label */
						__( 'We focus on professionals who work in %s, not a national call centre.', '3best-core' ),
						$place
					),
				),
				array(
					'title'       => __( 'One simple request', '3best-core' ),
					'description' => __( 'Fill in one short form instead of calling around for quotes one at a time.', '3best-core' ),
				),
				array(
					'title'       => __( 'No obligation', '3best-core' ),
					'description' => __( 'Submitting a request is free, and you decide what happens next.', '3best-core' ),
				),
			),

			'faqs' => array(
				array(
					'question' => sprintf(
						/* translators: %s: place label */
						__( 'How does 3Best work in %s?', '3best-core' ),
						$place
					),
					'answer'   => sprintf(
						/* translators: 1: service term, 2: place label */
						__( 'Tell us which %1$s service you need and where you need it. We take a few details about your project along with your contact information, then work to connect you with professionals who serve %2$s.', '3best-core' ),
						$term,
						$place
					),
				),
				array(
					'question' => sprintf(
						/* translators: %s: service term */
						__( 'Which %s services can I request?', '3best-core' ),
						$term
					),
					'answer'   => sprintf(
						/* translators: %s: list of example services */
						__( 'This page covers %s. If your project does not fit neatly into one of those, choose the closest match — we follow up on the details.', '3best-core' ),
						self::list_subcategories( $service, 4 )
					),
				),
				array(
					'question' => __( 'Is there a cost to submit a request?', '3best-core' ),
					'answer'   => __( 'No. Submitting a request through 3Best is free and there is no obligation to move forward with anyone.', '3best-core' ),
				),
				array(
					'question' => __( 'Why do you verify my mobile number?', '3best-core' ),
					'answer'   => __( 'Verifying your number confirms that a request comes from a real person, so professionals receive genuine enquiries. Your number is only used in connection with your request.', '3best-core' ),
				),
			),

			'seo_title'       => sprintf(
				/* translators: 1: professional plural, 2: place label */
				__( '%1$s in %2$s | Find Your 3 Best', '3best-core' ),
				$service['professional_plural'],
				$place
			),
			'seo_description' => sprintf(
				/* translators: 1: professional plural lowercase, 2: place, 3: service term */
				__( 'Find trusted %1$s in %2$s. Tell us what %3$s service you need and get connected with qualified local professionals.', '3best-core' ),
				$service['professional_plural_lower'],
				$place,
				$term
			),
		);
	}

	/**
	 * "a" or "an" for a service term.
	 *
	 * An all-caps acronym is read letter by letter, so "HVAC" takes "an"
	 * (aitch) while "plumbing" takes "a".
	 *
	 * @param string $term Service term.
	 */
	public static function indefinite_article( string $term ): string {
		$first = substr( $term, 0, 1 );

		if ( 1 === preg_match( '/^[A-Z]{2,}/', $term ) ) {
			return in_array( $first, self::AN_LETTERS, true ) ? 'an' : 'a';
		}

		return 1 === preg_match( '/^[aeiou]/i', $term ) ? 'an' : 'a';
	}

	/**
	 * Who this category is for, in a phrase that fits mid-sentence.
	 *
	 * @param string $category Category id.
	 */
	private static function audience_for( string $category ): string {
		switch ( $category ) {
			case 'home-services':
				return __( 'homeowners and businesses', '3best-core' );
			case 'real-estate':
				return __( 'buyers, sellers and property owners', '3best-core' );
			case 'finance':
				return __( 'individuals and business owners', '3best-core' );
			default:
				return __( 'businesses and organizations', '3best-core' );
		}
	}

	/**
	 * The first N subcategory names, lowercased for use in a sentence.
	 *
	 * @param array<string,mixed> $service Canonical service record.
	 * @param int                 $count   How many to list.
	 */
	private static function list_subcategories( array $service, int $count ): string {
		$names = array_map(
			static fn( array $subcategory ): string => Services_Repository::to_sentence_case( (string) $subcategory['name'] ),
			array_slice( (array) $service['subcategories'], 0, $count )
		);

		return implode( ', ', $names );
	}

	/**
	 * A sentence explaining how far the page's area reaches.
	 *
	 * A state page covers a lot of ground and needs the address to narrow it; a
	 * city page is already narrow. Saying the right one matters, because the
	 * address field is doing real work in both cases.
	 *
	 * @param Location $location Canonical location.
	 */
	private static function scope_sentence( Location $location ): string {
		if ( 'state' === $location->location_type ) {
			return sprintf(
				/* translators: %s: state name */
				__( '%s covers a lot of ground, so the city or ZIP code in your address is what we use to narrow things down.', '3best-core' ),
				$location->state
			);
		}

		return sprintf(
			/* translators: %s: city name */
			__( 'Your address helps us focus on professionals who actually cover %s rather than the wider region.', '3best-core' ),
			$location->city
		);
	}
}
