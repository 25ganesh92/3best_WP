<?php
/**
 * Service catalogue access.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Services;

defined( 'ABSPATH' ) || exit;

/**
 * The only way to reach the service catalogue.
 *
 * Ported from src/lib/services.ts. Everything that needs a service goes through
 * here and receives a canonical record — never a string a client supplied.
 * That is what stops an arbitrary "plumber" from the browser being trusted as a
 * service: it is looked up, and an unknown slug resolves to null.
 */
final class Services_Repository {

	/**
	 * @var array<string,mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $by_slug = null;

	/**
	 * @var array<string,array<string,mixed>>|null
	 */
	private static ?array $by_id = null;

	/**
	 * Acronyms that keep their case inside a sentence.
	 */
	private const ACRONYMS = array( 'AC', 'HVAC', 'FHA', 'VA', 'IT', 'SEO', 'LLC', 'IRA' );

	/**
	 * Every service, in catalogue order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		self::load();

		return array_values( self::$by_id );
	}

	/**
	 * Every category, in display order.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function categories(): array {
		self::load();

		$by_id = array();

		foreach ( self::$data['categories'] as $category ) {
			$by_id[ $category['id'] ] = $category;
		}

		$ordered = array();

		foreach ( self::$data['order'] as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * One category by id.
	 *
	 * @param string $id Category id.
	 * @return array<string,string>|null
	 */
	public static function category( string $id ): ?array {
		foreach ( self::categories() as $category ) {
			if ( $category['id'] === $id ) {
				return $category;
			}
		}

		return null;
	}

	/**
	 * A service by its curated SEO slug.
	 *
	 * @param string $slug Service slug, e.g. 'plumber'.
	 * @return array<string,mixed>|null
	 */
	public static function by_slug( string $slug ): ?array {
		self::load();

		return self::$by_slug[ strtolower( trim( $slug ) ) ] ?? null;
	}

	/**
	 * A service by its internal id.
	 *
	 * @param string $id Service id, e.g. 'plumbing'.
	 * @return array<string,mixed>|null
	 */
	public static function by_id( string $id ): ?array {
		self::load();

		return self::$by_id[ strtolower( trim( $id ) ) ] ?? null;
	}

	/**
	 * Services within a category.
	 *
	 * @param string $category_id Category id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function in_category( string $category_id ): array {
		return array_values(
			array_filter(
				self::all(),
				static fn( array $service ): bool => $service['category'] === $category_id
			)
		);
	}

	/**
	 * Categories with their services, in display order.
	 *
	 * @return array<int,array{category:array<string,string>,services:array<int,array<string,mixed>>}>
	 */
	public static function groups(): array {
		$groups = array();

		foreach ( self::categories() as $category ) {
			$groups[] = array(
				'category' => $category,
				'services' => self::in_category( $category['id'] ),
			);
		}

		return $groups;
	}

	/**
	 * One subcategory of a service, by its namespaced id.
	 *
	 * @param array<string,mixed> $service        Service record.
	 * @param string              $subcategory_id e.g. 'plumbing-water-heater-repair'.
	 * @return array<string,string>|null
	 */
	public static function subcategory( array $service, string $subcategory_id ): ?array {
		foreach ( $service['subcategories'] as $subcategory ) {
			if ( $subcategory['id'] === $subcategory_id ) {
				return $subcategory;
			}
		}

		return null;
	}

	/**
	 * Filters the catalogue for the service picker.
	 *
	 * Matches on service name, term, description, category name and
	 * subcategory names, so typing "water heater" still surfaces Plumbing.
	 *
	 * @param string $query Raw query.
	 * @return array<int,array{category:array<string,string>,services:array<int,array<string,mixed>>}>
	 */
	public static function search_groups( string $query ): array {
		$trimmed = strtolower( trim( $query ) );

		if ( '' === $trimmed ) {
			return self::groups();
		}

		$matched = array();

		foreach ( self::groups() as $group ) {
			$services = array_values(
				array_filter(
					$group['services'],
					static function ( array $service ) use ( $group, $trimmed ): bool {
						$haystack = strtolower(
							implode(
								' ',
								array_merge(
									array(
										$service['name'],
										$service['service_term'],
										$service['description'],
										$group['category']['name'],
									),
									array_column( $service['subcategories'], 'name' )
								)
							)
						);

						return false !== strpos( $haystack, $trimmed );
					}
				)
			);

			if ( array() !== $services ) {
				$matched[] = array(
					'category' => $group['category'],
					'services' => $services,
				);
			}
		}

		return $matched;
	}

	/**
	 * Lowercases a label for use inside a sentence, preserving acronyms.
	 *
	 * "AC Repair" -> "AC repair", "Emergency Plumbing" -> "emergency plumbing"
	 *
	 * @param string $label Title-case label.
	 */
	public static function to_sentence_case( string $label ): string {
		$words = explode( ' ', $label );

		return implode(
			' ',
			array_map(
				static fn( string $word ): string => in_array( $word, self::ACRONYMS, true ) ? $word : strtolower( $word ),
				$words
			)
		);
	}

	/**
	 * The catalogue as the browser needs it, for the service picker.
	 *
	 * Filtering is synchronous and local in the React implementation, so the
	 * catalogue is localised into the page rather than fetched: a picker that
	 * waits on a network round trip for every keystroke would be a regression.
	 * It is ~15KB and contains nothing sensitive.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_script(): array {
		$payload = array();

		foreach ( self::groups() as $group ) {
			$services = array();

			foreach ( $group['services'] as $service ) {
				$services[] = array(
					'id'          => $service['id'],
					'name'        => $service['name'],
					'slug'        => $service['slug'],
					'description' => $service['description'],
					'term'        => $service['service_term'],
					'keywords'    => implode( ' ', array_column( $service['subcategories'], 'name' ) ),
				);
			}

			$payload[] = array(
				'id'       => $group['category']['id'],
				'name'     => $group['category']['name'],
				'services' => $services,
			);
		}

		return $payload;
	}

	/**
	 * Loads and indexes the catalogue once per request.
	 */
	private static function load(): void {
		if ( null !== self::$data ) {
			return;
		}

		$data = require THREEBEST_CORE_DIR . 'data/services.php';

		$by_slug = array();
		$by_id   = array();

		foreach ( $data['services'] as $service ) {
			// Subcategory ids are derived, never stored, so they cannot drift
			// from the service they belong to.
			$subcategories = array();

			foreach ( $service['subcategories'] as $subcategory ) {
				$subcategories[] = array(
					'id'          => $service['id'] . '-' . $subcategory[1],
					'name'        => $subcategory[0],
					'slug'        => $subcategory[1],
					'description' => $subcategory[2],
				);
			}

			$service['subcategories'] = $subcategories;

			$by_slug[ $service['slug'] ] = $service;
			$by_id[ $service['id'] ]     = $service;
		}

		self::$data    = $data;
		self::$by_slug = $by_slug;
		self::$by_id   = $by_id;
	}

	/**
	 * Clears the memoised catalogue. Test seam.
	 */
	public static function flush(): void {
		self::$data    = null;
		self::$by_slug = null;
		self::$by_id   = null;
	}
}
