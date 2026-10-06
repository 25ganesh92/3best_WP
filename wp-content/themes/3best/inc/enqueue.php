<?php
/**
 * Asset registration.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Version string for an asset, derived from its modification time.
 *
 * filemtime() rather than the theme version, so an edited stylesheet busts the
 * cache immediately instead of waiting for a version bump. Falls back to the
 * theme version if the file cannot be read.
 *
 * @param string $relative_path Path relative to the theme root.
 */
function threebest_asset_version( string $relative_path ): string {
	$absolute = THREEBEST_THEME_DIR . '/' . ltrim( $relative_path, '/' );
	$mtime    = file_exists( $absolute ) ? filemtime( $absolute ) : false;

	return false !== $mtime ? (string) $mtime : THREEBEST_THEME_VERSION;
}

/**
 * Registers front-end styles and scripts.
 */
function threebest_theme_enqueue(): void {
	wp_enqueue_style(
		'threebest-main',
		THREEBEST_THEME_URI . '/assets/css/main.css',
		array(),
		threebest_asset_version( 'assets/css/main.css' )
	);

	/*
	 * Scroll reveal and the mobile menu. Deferred: neither is needed before
	 * first paint, and the reveal script leaves content visible if it never
	 * runs at all.
	 */
	wp_enqueue_script(
		'threebest-app',
		THREEBEST_THEME_URI . '/assets/js/app.js',
		array(),
		threebest_asset_version( 'assets/js/app.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);

	// Only the front page carries the carousel, so only it pays for the script.
	if ( is_front_page() ) {
		wp_enqueue_script(
			'threebest-carousel',
			THREEBEST_THEME_URI . '/assets/js/hero-carousel.js',
			array(),
			threebest_asset_version( 'assets/js/hero-carousel.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'threebest_theme_enqueue' );

/**
 * Preloads the two Inter weights used above the fold.
 *
 * 400 and 600 cover body copy and headings on first paint. The other two
 * weights load normally — preloading all four would compete with the hero
 * image for bandwidth.
 */
function threebest_theme_preload_fonts(): void {
	foreach ( array( '400', '600' ) as $weight ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
			esc_url( THREEBEST_THEME_URI . '/assets/fonts/inter-latin-' . $weight . '-normal.woff2' )
		);
	}
}
add_action( 'wp_head', 'threebest_theme_preload_fonts', 1 );
