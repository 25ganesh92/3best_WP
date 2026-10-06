<?php
/**
 * Service/location combination resolution.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Schema;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a service plus a location into the combination that governs it.
 *
 * Reads the Phase 1 wp_threebest_service_locations table, which is SPARSE by
 * design: a row exists only where a decision has been recorded, never one per
 * renderable combination. 16 services across the curated catalogue alone is
 * 528+ pairs, and the point of the table is to record the handful that have
 * been approved or overridden — not to enumerate the space.
 *
 * The distinction that matters, carried over from the React implementation:
 *
 *   RESOLVABLE  the pair is valid and a page could render.
 *   INDEXABLE   the pair has been approved for search engines.
 *
 * These are separate decisions. Everything resolvable renders; only approved
 * combinations are indexable. Confirmed decision 8 makes that set
 * admin-controlled, seeded with the 14 approved at launch. Nothing here ever
 * creates or approves a combination on its own — an arbitrary pair a visitor
 * happens to request stays un-indexed until somebody says otherwise.
 *
 * Phase 7 consumes this for canonical URLs, robots metadata and the sitemap.
 * Phase 4 only needs it to resolve correctly.
 */
final class Service_Locations {

	/**
	 * Resolves a combination slug to everything known about it.
	 *
	 * @param string $slug Combination slug, e.g. 'plumber-in-newark-nj'.
	 * @return array{service:array<string,mixed>,location:Location,slug:string,is_canonical:bool,is_indexable:bool,record:array<string,mixed>|null}|null
	 */
	public static function resolve_slug( string $slug ): ?array {
		$parsed = Slug::parse( $slug );

		if ( null === $parsed ) {
			return null;
		}

		return self::describe( $parsed['service'], $parsed['location'], strtolower( trim( $slug ) ) );
	}

	/**
	 * Resolves a service slug plus a location into a combination.
	 *
	 * Used by the search form: the browser sends identifiers, and both halves
	 * are looked up rather than trusted.
	 *
	 * @param string   $service_slug Service slug from the client.
	 * @param Location $location     Canonical location.
	 * @return array{service:array<string,mixed>,location:Location,slug:string,is_canonical:bool,is_indexable:bool,record:array<string,mixed>|null}|null
	 */
	public static function resolve( string $service_slug, Location $location ): ?array {
		$service = Services_Repository::by_slug( $service_slug );

		if ( null === $service ) {
			return null;
		}

		return self::describe( $service, $location, Slug::build( $service, $location ) );
	}

	/**
	 * Whether a combination has been approved for indexing.
	 *
	 * @param array<string,mixed> $service  Canonical service record.
	 * @param Location            $location Canonical location.
	 */
	public static function is_indexable( array $service, Location $location ): bool {
		$record = self::record( Slug::build( $service, $location ) );

		return null !== $record
			&& 1 === (int) $record['is_indexable']
			&& 'active' === $record['status'];
	}

	/**
	 * Every approved, active combination.
	 *
	 * Phase 7 uses this for the sitemap and for pre-rendering decisions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function indexable(): array {
		global $wpdb;

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		if ( ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return array();
		}

		// Identifier comes from a class constant and $wpdb->prefix; the values
		// are literals. No request input reaches this statement.
		$rows = $wpdb->get_results(
			"SELECT combo_slug, service_slug, location_slug, is_indexable, status, seo_title, seo_description, sitemap_priority
			 FROM `{$table}`
			 WHERE is_indexable = 1 AND status = 'active'
			 ORDER BY combo_slug",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The stored row for a combination slug, if one exists.
	 *
	 * @param string $combo_slug Combination slug.
	 * @return array<string,mixed>|null
	 */
	public static function record( string $combo_slug ): ?array {
		global $wpdb;

		if ( '' === $combo_slug || ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return null;
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE combo_slug = %s LIMIT 1",
				$combo_slug
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A page of stored combinations, newest decision first.
	 *
	 * Admin listing only. The table is sparse, so this is the whole record of
	 * decisions taken — not a view of the renderable space.
	 *
	 * @param int                 $limit   Rows per page.
	 * @param int                 $offset  Rows to skip.
	 * @param array<string,string> $filters Optional 'service' and 'indexable' ('1'|'0').
	 * @return array<int,array<string,mixed>>
	 */
	public static function page( int $limit, int $offset, array $filters = array() ): array {
		global $wpdb;

		if ( ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return array();
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		list( $where, $params ) = self::filter_clause( $filters );

		$params[] = max( 1, min( 200, $limit ) );
		$params[] = max( 0, $offset );

		// Identifier from a class constant; every value is bound.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` {$where} ORDER BY updated_at DESC, combo_slug ASC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many stored combinations match a filter.
	 *
	 * @param array<string,string> $filters Same shape as page().
	 */
	public static function count( array $filters = array() ): int {
		global $wpdb;

		if ( ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return 0;
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		list( $where, $params ) = self::filter_clause( $filters );

		if ( array() === $params ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` {$where}", $params )
		);
	}

	/**
	 * Builds a WHERE clause and its bound parameters.
	 *
	 * @param array<string,string> $filters Raw filter map.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function filter_clause( array $filters ): array {
		$clauses = array();
		$params  = array();

		if ( isset( $filters['service'] ) && '' !== $filters['service'] ) {
			$clauses[] = 'service_slug = %s';
			$params[]  = $filters['service'];
		}

		if ( isset( $filters['indexable'] ) && '' !== $filters['indexable'] ) {
			$clauses[] = 'is_indexable = %d';
			$params[]  = '1' === $filters['indexable'] ? 1 : 0;
		}

		return array(
			array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			$params,
		);
	}

	/**
	 * Creates or updates one combination.
	 *
	 * BOTH HALVES ARE RESOLVED BEFORE ANYTHING IS WRITTEN. An administrator
	 * cannot store a combination that would not render: the service must exist
	 * in the repository and the location in the catalogue, and the stored
	 * combo_slug is always rebuilt by Slug::build() rather than accepted from
	 * the form. That keeps this table in step with the resolver — a row that
	 * said "indexable" for a slug nothing could resolve would put a URL in a
	 * sitemap that answers 404.
	 *
	 * @param string              $service_slug  Service slug.
	 * @param string              $location_slug Location slug.
	 * @param array<string,mixed> $fields        Optional is_indexable, status, seo_title, seo_description, sitemap_priority, notes.
	 * @return string The canonical combo slug, or '' when the pair does not resolve.
	 */
	public static function save( string $service_slug, string $location_slug, array $fields = array() ): string {
		global $wpdb;

		if ( ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return '';
		}

		$service  = Services_Repository::by_slug( $service_slug );
		$location = Catalog::by_slug( $location_slug );

		if ( null === $service || null === $location ) {
			return '';
		}

		$combo_slug = Slug::build( $service, $location );
		$existing   = self::record( $combo_slug );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$table      = Schema::table( Schema::SERVICE_LOCATIONS );

		$data = array(
			'combo_slug'    => $combo_slug,
			'service_slug'  => (string) $service['slug'],
			'location_slug' => $location->slug,
			'updated_at'    => $now,
		);

		if ( array_key_exists( 'is_indexable', $fields ) ) {
			$data['is_indexable'] = empty( $fields['is_indexable'] ) ? 0 : 1;
		}

		if ( array_key_exists( 'status', $fields ) ) {
			$data['status'] = in_array( $fields['status'], array( 'active', 'paused' ), true )
				? (string) $fields['status']
				: 'active';
		}

		foreach ( array( 'seo_title', 'seo_description', 'notes' ) as $text_field ) {
			if ( array_key_exists( $text_field, $fields ) ) {
				$data[ $text_field ] = (string) $fields[ $text_field ];
			}
		}

		if ( array_key_exists( 'sitemap_priority', $fields ) ) {
			$priority                 = (float) $fields['sitemap_priority'];
			$data['sitemap_priority'] = ( $priority > 0 && $priority <= 1 ) ? $priority : null;
		}

		if ( null === $existing ) {
			$data['created_at'] = $now;

			$inserted = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			return false === $inserted ? '' : $combo_slug;
		}

		$updated = $wpdb->update( $table, $data, array( 'combo_slug' => $combo_slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false === $updated ? '' : $combo_slug;
	}

	/**
	 * Flips the indexable flag on an existing combination.
	 *
	 * Does NOT create a row: approving something that was never recorded is a
	 * create, and a create goes through save() where the pair is validated.
	 *
	 * @param string $combo_slug Combination slug.
	 * @param bool   $indexable  Desired state.
	 */
	public static function set_indexable( string $combo_slug, bool $indexable ): bool {
		global $wpdb;

		if ( null === self::record( $combo_slug ) ) {
			return false;
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'is_indexable' => $indexable ? 1 : 0,
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'combo_slug' => $combo_slug )
		);

		return false !== $updated;
	}

	/**
	 * Removes a recorded decision.
	 *
	 * The page keeps rendering afterwards — it simply stops being indexable,
	 * because an absent row means "no decision", not "forbidden".
	 *
	 * @param string $combo_slug Combination slug.
	 */
	public static function delete( string $combo_slug ): bool {
		global $wpdb;

		if ( '' === $combo_slug || ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return false;
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );

		return false !== $wpdb->delete( $table, array( 'combo_slug' => $combo_slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Builds the resolution result for a validated pair.
	 *
	 * @param array<string,mixed> $service   Canonical service record.
	 * @param Location            $location  Canonical location.
	 * @param string              $requested The slug as requested.
	 * @return array{service:array<string,mixed>,location:Location,slug:string,is_canonical:bool,is_indexable:bool,record:array<string,mixed>|null}
	 */
	private static function describe( array $service, Location $location, string $requested ): array {
		$canonical = Slug::build( $service, $location );
		$record    = self::record( $canonical );

		return array(
			'service'      => $service,
			'location'     => $location,
			'slug'         => $canonical,
			// False when the requested slug was an alias, e.g.
			// "plumber-in-newark-new-jersey". Phase 7 turns this into a 301.
			'is_canonical' => $requested === $canonical,
			'is_indexable' => null !== $record
				&& 1 === (int) $record['is_indexable']
				&& 'active' === $record['status'],
			'record'       => $record,
		);
	}
}
