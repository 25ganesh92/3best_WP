<?php
/**
 * Theme supports and registrations.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Declares what the theme supports.
 */
function threebest_theme_setup(): void {
	load_theme_textdomain( '3best', THREEBEST_THEME_DIR . '/languages' );

	// WordPress owns the <title> tag; Phase 7 filters it for landing pages.
	add_theme_support( 'title-tag' );

	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'customize-selective-refresh-widgets' );

	/*
	 * Block editor. theme.json carries the design tokens, so core and any
	 * third-party blocks emit on-brand output; editor-styles makes the editor
	 * resemble the result rather than guessing at it.
	 */
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 32,
			'width'       => 120,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
	add_editor_style( 'assets/css/main.css' );

	add_theme_support(
		'html5',
		array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' )
	);

	/*
	 * Menu locations are registered even though Phase 3 renders the three fixed
	 * navigation links directly. The React header has a fixed nav plus a CTA,
	 * and the Twenty Twenty-Five block navigation left in the database does not
	 * match it. Registering the locations now means the client can take control
	 * of the menu later without a template change: the header falls back to the
	 * fixed links only while no menu is assigned.
	 */
	register_nav_menus(
		array(
			'primary' => __( 'Primary navigation', '3best' ),
			'footer'  => __( 'Footer navigation', '3best' ),
		)
	);
}
add_action( 'after_setup_theme', 'threebest_theme_setup' );

/**
 * Removes markup the 3Best design has no surface for.
 *
 * The site has no comments, no emoji and no oEmbed discovery, so the matching
 * head output is dead weight on every page.
 */
function threebest_theme_trim_head(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
}
add_action( 'init', 'threebest_theme_trim_head' );

/**
 * Keeps comments closed everywhere.
 *
 * Phase 1 set the site defaults, but a filter makes it true for any content
 * created afterwards by any route.
 *
 * @param bool $open Whether comments are open.
 */
function threebest_theme_close_comments( $open ): bool {
	unset( $open );

	return false;
}
add_filter( 'comments_open', 'threebest_theme_close_comments' );
add_filter( 'pings_open', 'threebest_theme_close_comments' );
