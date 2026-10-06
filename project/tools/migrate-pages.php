<?php
/**
 * Layer 4 — move the four PHP-driven pages into editable post_content.
 *
 * The copy is carried across verbatim from the templates. Section scaffolding
 * is reproduced with wp:group + className, which renders
 * <div class="wp-block-group tb-section">; every selector in main.css is
 * class-only (verified), so the design is unchanged.
 *
 * Idempotent: running it twice produces the same content.
 */

define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true );
require_once 'C:/xampp/htdocs/3best/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

global $wpdb;
wp_set_current_user( (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ) );

/** Opens a group block. */
function g_open( string $class, string $extra = '' ): string {
	$attrs = '{"className":"' . $class . '"' . ( '' !== $extra ? ',' . $extra : '' ) . '}';
	return '<!-- wp:group ' . $attrs . ' --><div class="wp-block-group ' . $class . '">';
}
function g_close(): string {
	return '</div><!-- /wp:group -->';
}
function para( string $text, string $class = '' ): string {
	$attrs = '' !== $class ? ' {"className":"' . $class . '"}' : '';
	$open  = '' !== $class ? '<p class="' . $class . '">' : '<p>';
	return '<!-- wp:paragraph' . $attrs . ' -->' . $open . $text . '</p><!-- /wp:paragraph -->';
}
function heading( int $level, string $text, string $class = '' ): string {
	$attrs = '{"level":' . $level . ( '' !== $class ? ',"className":"' . $class . '"' : '' ) . '}';
	$open  = '' !== $class ? '<h' . $level . ' class="' . $class . '">' : '<h' . $level . '>';
	return '<!-- wp:heading ' . $attrs . ' -->' . $open . $text . '</h' . $level . '><!-- /wp:heading -->';
}
function button( string $url, string $label ): string {
	return '<!-- wp:buttons {"className":"tb-cta"} --><div class="wp-block-buttons tb-cta">'
		. '<!-- wp:button {"className":"tb-button tb-button--lg"} -->'
		. '<div class="wp-block-button tb-button tb-button--lg"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">' . $label . '</a></div>'
		. '<!-- /wp:button --></div><!-- /wp:buttons -->';
}
function media( string $key, string $ratio = 'landscape', string $radius = 'xl' ): string {
	return '<!-- wp:threebest/media {"imageKey":"' . $key . '","ratio":"' . $ratio . '","radius":"' . $radius . '"} /-->';
}
function page_hero( string $eyebrow, string $h1, string $lead, string $image ): string {
	return g_open( 'tb-page-hero' ) . g_open( 'container' ) . g_open( 'tb-page-hero__grid' )
		. g_open( 'tb-page-hero__inner' )
		. para( $eyebrow, 'tb-eyebrow' )
		. heading( 1, $h1 )
		. para( $lead, 'tb-page-hero__lead' )
		. g_close()
		. g_open( 'tb-page-hero__media' ) . media( $image ) . g_close()
		. g_close() . g_close() . g_close();
}

/* ------------------------------------------------------------------ */
$pages = array();

/* ---- Home -------------------------------------------------------- */
function icon_list( string $variant, array $items ): string {
	$lines = implode( "
", $items );
	return '<!-- wp:threebest/icon-list {"variant":"' . $variant . '","items":' . wp_json_encode( $lines ) . '} /-->';
}

$home = g_open( 'tb-home-hero', '"anchor":"find"' ) . g_open( 'container' ) . g_open( 'tb-home-hero__grid' )
	. g_open( 'tb-home-hero__content' )
	. para( 'Local professionals, made simple', 'tb-pill-eyebrow' )
	. heading( 1, 'Find Your 3 Best Local Professionals', 'tb-home-hero__title' )
	. para( 'Tell us what service you need and where you need it. We&#8217;ll help you find qualified professionals in your area.', 'tb-home-hero__lead' )
	. '<!-- wp:threebest/search-form {"context":"home"} /-->'
	. icon_list( 'reassurance', array( 'Free to submit', 'No obligation', 'Takes about a minute' ) )
	. g_close()
	. g_open( 'tb-home-hero__media' ) . '<!-- wp:threebest/hero-carousel /-->' . g_close()
	. g_close() . g_close() . g_close()

	. g_open( 'tb-section' ) . g_open( 'container' )
	. g_open( 'tb-section__head tb-section__head--center' )
	. para( 'How it works', 'tb-eyebrow' )
	. heading( 2, 'Three steps, start to finish' )
	. para( 'The whole process is designed to take a couple of minutes.', 'tb-section__lead' )
	. g_close()
	. icon_list(
		'steps',
		array(
			'Tell Us What You Need | Choose a service and enter your location.',
			'Tell Us About Your Project | Answer a few quick questions and provide your contact details.',
			'Get Your 3 Best Matches | We&#8217;ll help connect you with professionals who can help.',
		)
	)
	. g_close() . g_close()

	. g_open( 'tb-section tb-section--alt' ) . g_open( 'container' )
	. g_open( 'tb-section__head tb-section__head--center' )
	. para( 'Popular service categories', 'tb-eyebrow' )
	. heading( 2, 'What can we help you with?' )
	. para( 'Browse the categories we cover, or search for exactly what you need above.', 'tb-section__lead' )
	. g_close()
	. '<!-- wp:threebest/category-cards /-->'
	. g_close() . g_close()

	. g_open( 'tb-section' ) . g_open( 'container' ) . g_open( 'tb-value' )
	. g_open( 'tb-value__body' )
	. para( 'Why 3Best', 'tb-eyebrow' )
	. heading( 2, 'One Simple Request. Three Great Options.', 'tb-value__title' )
	. para( 'Instead of searching, comparing and calling one professional at a time, tell us once what you need. We take it from there.', 'tb-value__text' )
	. icon_list(
		'value',
		array(
			'Local professionals | We focus on the service you need and the place you need it.',
			'Quick and easy request process | Two questions to start, then a short form. No calling around.',
			'Verified requests | We confirm your mobile number so professionals know the request is genuine.',
		)
	)
	. g_open( 'tb-value__cta' ) . button( home_url( '/#find' ), 'Find My 3 Best' ) . g_close()
	. g_close()
	. g_open( 'tb-value__media' ) . media( 'page-why-3best' ) . g_close()
	. g_close() . g_close() . g_close();

$pages['home'] = $home;

/* ---- About ------------------------------------------------------- */
$about = page_hero(
	'About us',
	'Three good options beat a hundred search results',
	'3Best exists to make the first step of hiring a professional as short as possible.',
	'page-about'
)
	. g_open( 'tb-page-body' ) . g_open( 'container' )
	. g_open( 'tb-prose' )
	. para( 'Finding a professional in the United States usually means opening a dozen tabs, comparing listings that all look the same, and calling several businesses before anyone picks up. Most people stop halfway.' )
	. para( 'We think that first step should take a minute. Choose the service you need, tell us where you need it, describe the job in a sentence or two, and we take it from there.' )
	. g_close()
	. g_open( 'tb-split' )
	. g_open( 'tb-prose' )
	. heading( 2, 'What we ask for, and why' )
	. '<!-- wp:list --><ul class="wp-block-list">'
	. '<!-- wp:list-item --><li><strong>Service and location</strong> &#8212; so we know who could realistically help.</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li><strong>The type of work</strong> &#8212; an emergency repair and a planned installation are very different requests.</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li><strong>Your contact details and address</strong> &#8212; so a professional can reach you about the right property.</li><!-- /wp:list-item -->'
	. '</ul><!-- /wp:list -->'
	. para( 'We verify your mobile number before submitting a request. It keeps the requests genuine, which is what makes them worth responding to.' )
	. g_close()
	. g_open( 'tb-split__media' ) . media( 'page-why-3best' ) . g_close()
	. g_close()
	. g_open( 'tb-prose' )
	. para( '3Best is temporary branding for this concept demo. The name, logo and copy are placeholders and are designed to be replaced.', 'tb-note' )
	. button( home_url( '/#find' ), 'Find My 3 Best' )
	. g_close()
	. g_close() . g_close();

$pages['about'] = $about;

/* ---- How it works ------------------------------------------------ */
$how = page_hero(
	'How it works',
	'From &#8220;I need help&#8221; to a submitted request',
	'The whole process is built around two questions: what do you need, and where do you need it.',
	'page-how-it-works'
)
	. g_open( 'tb-page-body' ) . g_open( 'container' )
	. icon_list(
		'process',
		array(
			'Tell us what you need | Start on the homepage by choosing a service and entering a city, state or ZIP code. That takes you to a page built specifically for that service in that place.',
			'Choose the type of work | Every service has a handful of common request types &#8212; for plumbing that might be emergency work, drain cleaning or a water heater. Pick the one closest to your project.',
			'Add your details | Your name, email, mobile number and the address where the work is needed. Nothing else. The address matters because it is what determines which professionals can actually help.',
			'Verify your phone | We send a six-digit code to your mobile. Verifying it confirms your request is genuine, which is what makes it worth a professional&#8217;s time.',
			'Submit your request | That is it. Your request is submitted and we work on finding your three best matches. There is no cost and no obligation.',
		)
	)
	. g_open( 'tb-prose' )
	. para( 'This is a concept demo. Phone verification, lead storage and lead delivery are simulated so the full journey can be reviewed before the production integrations are connected.', 'tb-note' )
	. button( home_url( '/#find' ), 'Find My 3 Best' )
	. g_close()
	. g_close() . g_close();

$pages['how-it-works'] = $how;

/* ---- Services ---------------------------------------------------- */
$services = g_open( 'tb-page-hero' ) . g_open( 'container' )
	. g_open( 'tb-page-hero__inner' )
	. para( 'Services', 'tb-eyebrow' )
	. heading( 1, 'Every service we cover' )
	. para( 'Choose the service you need and tell us where you need it. Each service below opens a request for professionals working in your area.', 'tb-page-hero__lead' )
	. g_close()
	. g_open( 'tb-services__search' ) . '<!-- wp:threebest/search-form {"context":"services"} /-->' . g_close()
	. g_close() . g_close()
	. g_open( 'tb-page-body' ) . g_open( 'container' )
	. '<!-- wp:threebest/services-catalogue /-->'
	. g_close() . g_close();

$pages['services'] = $services;

/* ------------------------------------------------------------------ */
foreach ( $pages as $slug => $content ) {
	$page = get_page_by_path( $slug );

	if ( ! $page instanceof WP_Post ) {
		printf( "  [SKIP] no page with slug %s\n", $slug );
		continue;
	}

	$before = strlen( (string) $page->post_content );

	/*
	 * wp_slash: wp_update_post() expects slashed data and unslashes it. Without
	 * this the 
 escapes inside a block attribute's JSON lose their backslash
	 * and the list items run together on the front end.
	 */
	$result = wp_update_post(
		array(
			'ID'           => $page->ID,
			'post_content' => wp_slash( $content ),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		printf( "  [FAIL] %-14s %s\n", $slug, $result->get_error_message() );
		continue;
	}

	$after  = strlen( (string) get_post( $page->ID )->post_content );
	$blocks = substr_count( $content, '<!-- wp:' );

	printf( "  [OK]   %-14s id=%-3d content %d -> %d chars, %d blocks\n", $slug, $page->ID, $before, $after, $blocks );
}

echo "\nDone.\n";
