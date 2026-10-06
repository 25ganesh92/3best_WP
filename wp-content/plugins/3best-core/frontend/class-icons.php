<?php
/**
 * Inline SVG icons for plugin-rendered markup.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The few icons the plugin's own markup needs.
 *
 * The theme has its own icon set for the templates it owns. This is a
 * deliberate, small duplication: the search form is rendered by the plugin and
 * must not break if the theme is replaced, and three path definitions is a
 * cheaper coupling to avoid than a shared dependency in either direction.
 */
final class Icons {

	/**
	 * Path data, keyed by name.
	 *
	 * @var array<string,string>
	 */
	private const PATHS = array(
		'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'close'   => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
	);

	/**
	 * Returns an inline SVG. Static markup, safe to echo.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Width and height in pixels.
	 */
	public static function svg( string $name, int $size = 20 ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
			$size,
			self::PATHS[ $name ]
		);
	}
}
