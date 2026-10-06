<?php
/**
 * Regression suite 4 of 4 — the Gutenberg editing architecture.
 *
 * Covers what the block migration introduced: theme.json, block patterns, the
 * dynamic blocks, post_content rendering, the editable landing layout, and
 * header/footer configurability.
 *
 * Parks and restores everything. Sends nothing anywhere. The development OTP
 * records are never touched.
 */

require_once __DIR__ . '/bootstrap.php';

use ThreeBest\Core\Seo\Landing_Page;
use ThreeBest\Core\Seo\Landing_Template;
use ThreeBest\Core\Seo\Service_Locations;

global $wpdb;

$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
wp_set_current_user( $admin_id );

$theme_dir = get_template_directory();

function fetch( string $path ): array {
	$r = wp_remote_get( home_url( $path ), array( 'timeout' => 25, 'redirection' => 0 ) );
	if ( is_wp_error( $r ) ) {
		return array( 0, '' );
	}
	return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
}

/* =================================================================== */
echo "\n--- 1. THEME.JSON ---\n";
$theme_json_path = $theme_dir . '/theme.json';
check( 'theme.json exists', file_exists( $theme_json_path ) );
$json = json_decode( (string) file_get_contents( $theme_json_path ), true );
check( 'theme.json is valid JSON', is_array( $json ), json_last_error_msg() );
check( 'schema version 3', 3 === ( $json['version'] ?? 0 ) );

$palette = array_column( $json['settings']['color']['palette'] ?? array(), 'color', 'slug' );
foreach ( array( 'primary' => '#1a56db', 'accent' => '#0e9f6e', 'ink' => '#0f172a', 'body' => '#475569', 'surface-alt' => '#f8fafc' ) as $slug => $hex ) {
	check( "palette carries the existing token {$slug}", ( $palette[ $slug ] ?? '' ) === $hex, $palette[ $slug ] ?? 'missing' );
}
check( 'custom colours are disabled, so the palette is the palette', false === ( $json['settings']['color']['custom'] ?? true ) );
check( 'content width matches the narrow container', '760px' === ( $json['settings']['layout']['contentSize'] ?? '' ) );
check( 'wide width matches the container', '1200px' === ( $json['settings']['layout']['wideSize'] ?? '' ) );
check( 'Inter is the registered font family', false !== strpos( (string) ( $json['settings']['typography']['fontFamilies'][0]['fontFamily'] ?? '' ), 'Inter' ) );

list( , $home_html ) = fetch( '/' );
check( 'global styles are emitted on the front end', false !== strpos( $home_html, 'global-styles-inline-css' ) );
check( 'the palette reaches the page as CSS custom properties', false !== strpos( $home_html, '--wp--preset--color--primary' ) );
check( 'editor styles are supported', current_theme_supports( 'editor-styles' ) );
check( 'wide alignment is supported', current_theme_supports( 'align-wide' ) );

/* =================================================================== */
echo "\n--- 2. BLOCK PATTERNS ---\n";
$patterns = WP_Block_Patterns_Registry::get_instance()->get_all_registered();
$ours     = array_values( array_filter( $patterns, static fn( array $p ): bool => 0 === strpos( (string) $p['name'], 'threebest/' ) ) );
check( 'seven 3Best patterns registered', 7 === count( $ours ), (string) count( $ours ) );

$names = array_column( $ours, 'name' );
foreach ( array( 'page-hero', 'section-header', 'benefits', 'steps', 'faq', 'cta', 'prose' ) as $slug ) {
	check( "pattern present: {$slug}", in_array( 'threebest/' . $slug, $names, true ) );
}
$categories = array();
foreach ( $ours as $p ) {
	$categories = array_merge( $categories, (array) ( $p['categories'] ?? array() ) );
}
check( 'all patterns sit in the 3Best category', array( 'threebest' ) === array_values( array_unique( $categories ) ), implode( ',', array_unique( $categories ) ) );

$pattern_markup = implode( '', array_column( $ours, 'content' ) );
check( 'patterns use core blocks, so they stay editable after insertion', false !== strpos( $pattern_markup, '<!-- wp:heading' ) && false !== strpos( $pattern_markup, '<!-- wp:paragraph' ) );
check( 'patterns carry the theme classes, so they match the design', false !== strpos( $pattern_markup, 'tb-section' ) && false !== strpos( $pattern_markup, 'tb-eyebrow' ) );
check( 'no pattern locks its blocks', false === strpos( $pattern_markup, '"lock"' ) );

/* =================================================================== */
echo "\n--- 3. DYNAMIC BLOCKS ---\n";
$registry = WP_Block_Type_Registry::get_instance();
$blocks   = array_values( array_filter( array_keys( $registry->get_all_registered() ), static fn( string $n ): bool => 0 === strpos( $n, 'threebest/' ) ) );
check( 'twelve 3Best blocks registered', 12 === count( $blocks ), (string) count( $blocks ) );

foreach ( array(
	'threebest/search-form', 'threebest/lead-form', 'threebest/services-catalogue',
	'threebest/media', 'threebest/hero-carousel', 'threebest/category-cards', 'threebest/icon-list',
	'threebest/landing-hero', 'threebest/landing-subcategories', 'threebest/landing-benefits',
	'threebest/landing-body', 'threebest/landing-faq',
) as $name ) {
	check( "block registered: {$name}", in_array( $name, $blocks, true ) );
}

$dynamic = 0;
foreach ( $blocks as $name ) {
	$type = $registry->get_registered( $name );
	if ( is_callable( $type->render_callback ) ) {
		$dynamic++;
	}
}
check( 'every 3Best block is server rendered', 12 === $dynamic, (string) $dynamic );

/*
 * The blocks must WRAP the existing renderers, not reimplement them. Checked
 * structurally: the source calls the actions the plugin already provided, and
 * contains none of the logic those renderers own.
 */
$blocks_src = (string) file_get_contents( THREEBEST_CORE_DIR . 'frontend/class-blocks.php' );
check( 'the lead-form block calls the existing action', false !== strpos( $blocks_src, "do_action( 'threebest_lead_form'" ) );
check( 'the search-form block calls the existing action', false !== strpos( $blocks_src, "do_action( 'threebest_search_form'" ) );
check( 'the catalogue block calls the existing action', false !== strpos( $blocks_src, "do_action( 'threebest_category_services'" ) );
foreach ( array( 'wpdb', 'INSERT INTO', 'Otp_Service', 'Delivery_Queue', 'Lead_Repository', 'Lead_Validator', 'wp_remote_post' ) as $forbidden ) {
	check( "no business logic in the blocks: {$forbidden}", false === strpos( $blocks_src, $forbidden ) );
}
check( 'blocks never resolve a service or location themselves', false === strpos( $blocks_src, 'Service_Locations::resolve' ) && false === strpos( $blocks_src, 'Catalog::' ) );

/* =================================================================== */
echo "\n--- 4. STATIC PAGES ARE EDITABLE CONTENT ---\n";
$expect = array(
	'home'         => array( 'tb-home-hero', 'threebest/search-form' ),
	'about'        => array( 'tb-page-hero', 'wp:heading' ),
	'services'     => array( 'threebest/services-catalogue', 'threebest/search-form' ),
	'how-it-works' => array( 'threebest/icon-list', 'tb-page-hero' ),
);

foreach ( $expect as $slug => $markers ) {
	$page = get_page_by_path( $slug );
	check( "page exists: {$slug}", $page instanceof WP_Post );

	if ( ! $page instanceof WP_Post ) {
		continue;
	}

	$content = (string) $page->post_content;
	check( "  {$slug} has real post_content", strlen( $content ) > 500, strlen( $content ) . ' chars' );
	check( "  {$slug} content is blocks", has_blocks( $content ) );

	foreach ( $markers as $marker ) {
		check( "  {$slug} content carries {$marker}", false !== strpos( $content, $marker ) );
	}

	$parsed = parse_blocks( $content );
	check( "  {$slug} parses into blocks", count( $parsed ) > 0, count( $parsed ) . ' top-level' );
}

/*
 * The editor must not lie: whatever an administrator sees in Pages -> Edit has
 * to be what the front end renders. An empty editor over a PHP-rendered page
 * was the original problem.
 */
foreach ( array_keys( $expect ) as $slug ) {
	$page = get_page_by_path( $slug );
	$path = '/' === $slug ? '/' : '/' . $slug . '/';
	$path = 'home' === $slug ? '/' : $path;

	list( $code, $html ) = fetch( $path );
	check( "{$path} returns 200", 200 === $code, (string) $code );

	$heading = '';
	if ( 1 === preg_match( '/<!-- wp:heading[^>]*-->\s*<h1[^>]*>(.*?)<\/h1>/s', (string) $page->post_content, $m ) ) {
		$heading = trim( wp_strip_all_tags( $m[1] ) );
	}

	check(
		"  the H1 an editor sees is the H1 the visitor sees{$path}",
		'' === $heading || false !== strpos( $html, $heading ),
		$heading
	);
}

check( 'the migrated templates render the_content', ( function () use ( $theme_dir ): bool {
	foreach ( array( 'front-page.php', 'page-about.php', 'page-services.php', 'page-how-it-works.php' ) as $file ) {
		if ( false === strpos( (string) file_get_contents( $theme_dir . '/' . $file ), 'the_content()' ) ) {
			return false;
		}
	}
	return true;
} )() );

check( 'no migrated template still hard-codes its copy', ( function () use ( $theme_dir ): bool {
	foreach ( array( 'front-page.php', 'page-about.php', 'page-services.php', 'page-how-it-works.php' ) as $file ) {
		if ( filesize( $theme_dir . '/' . $file ) > 1200 ) {
			return false;
		}
	}
	return true;
} )() );

/* =================================================================== */
echo "\n--- 5. LANDING LAYOUT ---\n";
$layout_id = Landing_Template::layout_id();
check( 'one editable landing layout exists', $layout_id > 0, (string) $layout_id );
$layout = get_post( $layout_id );
check( 'it is the layout post type', $layout instanceof WP_Post && Landing_Template::POST_TYPE === $layout->post_type );
check( 'it is editable as blocks', $layout instanceof WP_Post && has_blocks( $layout->post_content ) );
check( 'it is not publicly queryable', false === ( get_post_type_object( Landing_Template::POST_TYPE )->publicly_queryable ?? true ) );
check( 'it is excluded from search', true === ( get_post_type_object( Landing_Template::POST_TYPE )->exclude_from_search ?? false ) );
check( 'it is available in the block editor', true === ( get_post_type_object( Landing_Template::POST_TYPE )->show_in_rest ?? false ) );

check( 'exactly ONE layout, not fourteen pages', 1 === (int) ( new WP_Query( array(
	'post_type'      => Landing_Template::POST_TYPE,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) ) )->found_posts );

check( 'still exactly 14 approved combinations', 14 === count( Service_Locations::indexable() ) );

list( $code, $landing_html ) = fetch( '/plumber-in-newark-nj/' );
check( 'a landing page renders', 200 === $code, (string) $code );
foreach ( array( 'tb-landing-hero', 'tb-categories', 'tb-landing-benefit', 'data-tb-lead-form', 'tb-landing-content', 'tb-faq__item' ) as $marker ) {
	check( "  landing page renders {$marker}", false !== strpos( $landing_html, $marker ) );
}
check( '  exactly one lead form on the landing page', 1 === substr_count( $landing_html, 'data-tb-lead-form' ) );
check( '  exactly one H1', 1 === substr_count( $landing_html, '<h1' ) );
check( '  the H1 is generated from the resolved service and location', false !== strpos( $landing_html, 'Find Trusted Plumbers in Newark, NJ' ) );
check( '  no block placeholder leaked to the front end', false === strpos( $landing_html, 'tb-block-placeholder' ) );

$second = fetch( '/hvac-in-dallas-tx/' )[1];
check( 'a different combination renders different generated copy', false !== strpos( $second, 'Find Trusted HVAC Professionals in Dallas, TX' ) );
check( 'both use the same single layout', false !== strpos( $second, 'tb-landing-hero' ) && false !== strpos( $second, 'tb-faq__item' ) );

/* The fallback: an empty layout must degrade to the PHP parts, not a blank page. */
$saved = $layout->post_content;
wp_update_post( array( 'ID' => $layout_id, 'post_content' => '' ) );
list( $code, $fallback_html ) = fetch( '/plumber-in-newark-nj/' );
check( 'with the layout emptied the page still renders', 200 === $code, (string) $code );
check( '  the PHP fallback produced the hero', false !== strpos( $fallback_html, 'tb-landing-hero' ) );
check( '  and the lead form', false !== strpos( $fallback_html, 'data-tb-lead-form' ) );
wp_update_post( array( 'ID' => $layout_id, 'post_content' => wp_slash( $saved ) ) );
check( 'layout restored', trim( (string) get_post( $layout_id )->post_content ) === trim( (string) $saved ) );

/* =================================================================== */
echo "\n--- 6. HEADER AND FOOTER ---\n";
check( 'custom logo is supported', current_theme_supports( 'custom-logo' ) );
$menus = get_registered_nav_menus();
check( 'a primary menu location exists', isset( $menus['primary'] ) );
check( 'a footer menu location exists', isset( $menus['footer'] ) );
check( 'a footer widget area is registered', is_registered_sidebar( 'threebest-footer' ) );

check( 'header CTA text is a setting', function_exists( 'threebest_option' ) && '' !== threebest_option( 'threebest_header_cta_text' ), threebest_option( 'threebest_header_cta_text' ) );
check( 'header CTA link is a setting', '' !== threebest_option( 'threebest_header_cta_url' ) );
check( 'footer description is a setting', '' !== threebest_option( 'threebest_footer_about' ) );
check( 'footer small print is a setting', '' !== threebest_option( 'threebest_footer_note' ) );

/* Changing a setting must change the page, with no PHP edit. */
$original_cta = get_theme_mod( 'threebest_header_cta_text', '' );
set_theme_mod( 'threebest_header_cta_text', 'Start a request' );
list( , $changed ) = fetch( '/' );
check( 'changing the header CTA in settings changes the page', false !== strpos( $changed, 'Start a request' ) );
if ( '' === $original_cta ) {
	remove_theme_mod( 'threebest_header_cta_text' );
} else {
	set_theme_mod( 'threebest_header_cta_text', $original_cta );
}
list( , $restored ) = fetch( '/' );
check( 'and restoring it restores the page', false !== strpos( $restored, 'Get Started' ) );

check( 'the theme is still a classic theme, not converted to FSE', ! wp_is_block_theme() );

/* =================================================================== */
echo "\n--- 7. SPECTRA STATE ---\n";
$active = (array) get_option( 'active_plugins' );
check( 'Spectra Legacy is not active', ! in_array( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', $active, true ) );
check( 'Spectra Blocks is not active', ! in_array( 'spectra-blocks/spectra-blocks.php', $active, true ) );
check( '3best-core is the only active plugin', array( '3best-core/3best-core.php' ) === array_values( $active ), implode( ',', $active ) );
check( 'no page content depends on Spectra', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%uagb%' OR post_content LIKE '%spectra%'" ) );
check( 'the architecture stays Spectra-compatible: theme.json presents the palette to any block', isset( $json['settings']['color']['palette'] ) );

list( , $page_html ) = fetch( '/about/' );
check( 'no Spectra CSS or JS on the front end', false === stripos( $page_html, 'spectra' ) && false === stripos( $page_html, 'uag' ) );
check( 'page weight is back to normal', strlen( $page_html ) < 120000, strlen( $page_html ) . ' bytes' );

tb_report( 'SUITE 4' );
