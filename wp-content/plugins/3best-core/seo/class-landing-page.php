<?php
/**
 * The resolved landing page for the current request.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Location;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the one resolution for the current request.
 *
 * Four separate things need the resolved service and location — the rewrite
 * handler, the metadata, the structured data and the template — and each runs
 * at a different point in the WordPress lifecycle. Without a shared home they
 * would each re-resolve, which means repeating the slug parse, the catalogue
 * lookup and the service_locations query three extra times per page view.
 *
 * So resolution happens once, in the rewrite handler, and everything else reads
 * it from here. This is the React `cache()` wrapper's job, done the way PHP
 * does it: request-scoped memoisation, no persistence, no cross-request state.
 *
 * It is also the reason the TEMPLATE contains no resolution logic. A template
 * asking "is this combination indexable?" would be a second source of truth.
 */
final class Landing_Page {

	/**
	 * The resolution for this request, or null when this is not a landing page.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $current = null;

	/**
	 * Generated copy, built lazily and only once.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $content = null;

	/**
	 * Records the resolution for this request.
	 *
	 * @param array<string,mixed> $resolved Result from Service_Locations::resolve_slug().
	 */
	public static function set( array $resolved ): void {
		self::$current = $resolved;
		self::$content = null;
	}

	/**
	 * Whether the current request is a resolved landing page.
	 */
	public static function is_active(): bool {
		return null !== self::$current;
	}

	/**
	 * The full resolution, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get(): ?array {
		return self::$current;
	}

	/**
	 * The canonical service record.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function service(): ?array {
		return self::$current['service'] ?? null;
	}

	/**
	 * The canonical location.
	 */
	public static function location(): ?Location {
		$location = self::$current['location'] ?? null;

		return $location instanceof Location ? $location : null;
	}

	/**
	 * The canonical combination slug.
	 */
	public static function slug(): string {
		return isset( self::$current['slug'] ) ? (string) self::$current['slug'] : '';
	}

	/**
	 * The canonical absolute URL for this page.
	 *
	 * Built from the RESOLVED entities, never from the requested URL, and
	 * deliberately without any query string: a canonical pointing at
	 * ?utm_source=… would split the page's signals across every campaign.
	 */
	public static function canonical_url(): string {
		$slug = self::slug();

		return '' !== $slug ? home_url( '/' . $slug . '/' ) : '';
	}

	/**
	 * Whether this combination has been approved for indexing.
	 */
	public static function is_indexable(): bool {
		return ! empty( self::$current['is_indexable'] );
	}

	/**
	 * Generated copy for this page, memoised.
	 *
	 * @return array<string,mixed>
	 */
	public static function content(): array {
		if ( null !== self::$content ) {
			return self::$content;
		}

		$service  = self::service();
		$location = self::location();

		if ( null === $service || null === $location ) {
			self::$content = array();

			return self::$content;
		}

		$content = Content_Generator::build( $service, $location );

		/*
		 * A stored row may override the title and description for one
		 * combination — the editorial escape hatch the sparse table exists for.
		 * Only these two are overridable; body copy stays generated so it cannot
		 * drift out of step with the service catalogue.
		 */
		$record = self::$current['record'] ?? null;

		if ( is_array( $record ) ) {
			if ( ! empty( $record['seo_title'] ) ) {
				$content['seo_title'] = (string) $record['seo_title'];
			}

			if ( ! empty( $record['seo_description'] ) ) {
				$content['seo_description'] = (string) $record['seo_description'];
			}
		}

		self::$content = $content;

		return self::$content;
	}

	/**
	 * Clears the resolution. Test seam.
	 */
	public static function reset(): void {
		self::$current = null;
		self::$content = null;
	}
}
