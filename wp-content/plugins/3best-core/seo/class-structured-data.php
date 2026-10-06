<?php
/**
 * JSON-LD structured data.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Location\Location;

defined( 'ABSPATH' ) || exit;

/**
 * Emits JSON-LD for a landing page.
 *
 * ONLY FACTS THE PAGE ACTUALLY SUPPORTS. No ratings, no reviews, no provider
 * counts, no prices, no availability, no street address, no telephone, no
 * opening hours — none of which this project has. Inventing any of them would
 * be structured-data spam, and it is the single easiest way to earn a manual
 * action. The React implementation drew the same line and this preserves it.
 *
 * Three graphs, matching the reference:
 *
 *   BreadcrumbList  the path the visitor took to this page
 *   Service         what the page offers, where, and which request types
 *   FAQPage         the four questions actually rendered on the page
 *
 * FAQPage is only emitted because the questions and answers are genuinely
 * present in the markup — marking up questions that a visitor cannot see is
 * exactly what the guidelines prohibit.
 *
 * Encoding goes through wp_json_encode with escaped slashes and unicode, so the
 * output cannot break out of the script element.
 */
final class Structured_Data {

	/**
	 * Registers the head output.
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'render' ), 20 );
	}

	/**
	 * Outputs every graph for the current landing page.
	 */
	public static function render(): void {
		if ( ! Landing_Page::is_active() ) {
			return;
		}

		$service  = Landing_Page::service();
		$location = Landing_Page::location();
		$content  = Landing_Page::content();

		if ( null === $service || null === $location ) {
			return;
		}

		$graphs = array(
			self::breadcrumbs( $service, $location ),
			self::service_graph( $service, $location, $content ),
		);

		if ( ! empty( $content['faqs'] ) ) {
			$graphs[] = self::faq_graph( (array) $content['faqs'] );
		}

		foreach ( $graphs as $graph ) {
			$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( false === $json ) {
				continue;
			}

			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				$json // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON-LD script element.
			);
		}
	}

	/**
	 * BreadcrumbList matching the trail rendered on the page.
	 *
	 * @param array<string,mixed> $service  Canonical service.
	 * @param Location            $location Canonical location.
	 * @return array<string,mixed>
	 */
	private static function breadcrumbs( array $service, Location $location ): array {
		$items = array(
			array(
				'name' => __( 'Home', '3best-core' ),
				'url'  => home_url( '/' ),
			),
			array(
				'name' => __( 'Services', '3best-core' ),
				'url'  => home_url( '/services/' ),
			),
			array(
				'name' => (string) $service['name'],
				'url'  => home_url( '/services/#' . $service['slug'] ),
			),
			array(
				// The final crumb has no url: it is this page.
				'name' => sprintf( '%s in %s', $service['professional_plural'], Labels::label( $location ) ),
				'url'  => '',
			),
		);

		$elements = array();

		foreach ( $items as $index => $item ) {
			$element = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $item['name'],
			);

			if ( '' !== $item['url'] ) {
				$element['item'] = $item['url'];
			}

			$elements[] = $element;
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $elements,
		);
	}

	/**
	 * Service graph: what is offered, where, and which request types.
	 *
	 * areaServed is the honest shape for each location type — a State for a
	 * state page, a City nested in its State for a city page. The provider is
	 * this site, which is what it actually is: the organisation taking the
	 * request, not the professional who fulfils it.
	 *
	 * @param array<string,mixed> $service  Canonical service.
	 * @param Location            $location Canonical location.
	 * @param array<string,mixed> $content  Generated copy.
	 * @return array<string,mixed>
	 */
	private static function service_graph( array $service, Location $location, array $content ): array {
		$offers = array();

		foreach ( (array) $service['subcategories'] as $subcategory ) {
			$offers[] = array(
				'@type'       => 'Offer',
				'itemOffered' => array(
					'@type'       => 'Service',
					'name'        => (string) $subcategory['name'],
					'description' => (string) $subcategory['description'],
				),
			);
		}

		return array(
			'@context'       => 'https://schema.org',
			'@type'          => 'Service',
			'name'           => sprintf( '%s in %s', $service['name'], Labels::label( $location ) ),
			'serviceType'    => (string) $service['name'],
			'description'    => isset( $content['seo_description'] ) ? (string) $content['seo_description'] : '',
			'url'            => Landing_Page::canonical_url(),
			'areaServed'     => self::area_served( $location ),
			'provider'       => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
			'hasOfferCatalog' => array(
				'@type'           => 'OfferCatalog',
				'name'            => sprintf( '%s services', $service['name'] ),
				'itemListElement' => $offers,
			),
		);
	}

	/**
	 * The area this page covers.
	 *
	 * @param Location $location Canonical location.
	 * @return array<string,mixed>
	 */
	private static function area_served( Location $location ): array {
		if ( 'state' === $location->location_type ) {
			return array(
				'@type' => 'State',
				'name'  => $location->state,
			);
		}

		return array(
			'@type'             => 'City',
			'name'              => $location->city,
			'containedInPlace'  => array(
				'@type' => 'State',
				'name'  => $location->state,
			),
		);
	}

	/**
	 * FAQPage for the questions rendered on the page.
	 *
	 * @param array<int,array<string,string>> $faqs Generated FAQs.
	 * @return array<string,mixed>
	 */
	private static function faq_graph( array $faqs ): array {
		$questions = array();

		foreach ( $faqs as $faq ) {
			if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) {
				continue;
			}

			$questions[] = array(
				'@type'          => 'Question',
				'name'           => (string) $faq['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => (string) $faq['answer'],
				),
			);
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $questions,
		);
	}
}
