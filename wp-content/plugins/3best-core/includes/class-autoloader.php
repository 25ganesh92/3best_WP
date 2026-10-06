<?php
/**
 * Class file autoloader.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps namespaced class names onto WordPress-style class-*.php files.
 *
 * ThreeBest\Core\Schema            -> includes/class-schema.php
 * ThreeBest\Core\Admin\Status_Page -> admin/class-status-page.php
 *
 * A sub-namespace becomes a directory; everything else lives in includes/.
 * This keeps WordPress file naming conventions without hand-maintaining a map.
 */
final class Autoloader {

	/**
	 * Only classes under this namespace are handled.
	 */
	private const PREFIX = 'ThreeBest\\Core\\';

	/**
	 * Registers the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Resolves and includes the file for a class, if it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$leaf     = array_pop( $parts );

		$directory = $parts
			? strtolower( implode( '/', $parts ) ) . '/'
			: 'includes/';

		$slug = strtolower( str_replace( '_', '-', $leaf ) );
		$base = realpath( THREEBEST_CORE_DIR );

		if ( false === $base ) {
			return;
		}

		// WordPress names interfaces and traits with their own prefixes, so all
		// three are tried. Classes are by far the common case and come first.
		foreach ( array( 'class', 'interface', 'trait' ) as $prefix ) {
			$real = realpath( THREEBEST_CORE_DIR . $directory . $prefix . '-' . $slug . '.php' );

			// realpath() plus a prefix check keeps traversal out of the include path.
			if ( false === $real || 0 !== strpos( $real, $base ) ) {
				continue;
			}

			require_once $real;

			return;
		}
	}
}
