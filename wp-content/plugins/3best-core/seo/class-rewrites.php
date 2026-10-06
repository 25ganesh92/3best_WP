<?php
/**
 * Dynamic landing page routing.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Routes /{service}-in-{city}-{state}/ to the landing template.
 *
 * ONE RULE, EVERY COMBINATION. 16 services across the curated catalogue alone
 * is 528+ pairs, and the geocoder can produce more. None of them is a WordPress
 * post: the URL space is computed, exactly as the React implementation computes
 * it, so there is nothing to create, publish or keep in sync.
 *
 * ---------------------------------------------------------------------------
 * WHY THE RULE IS NARROW
 * ---------------------------------------------------------------------------
 * The pattern requires the literal "-in-" and is anchored at both ends, so it
 * cannot swallow an ordinary page. It is registered at the TOP of the rewrite
 * list, which makes collision a real risk — a page genuinely slugged
 * "investing-in-property" would match — so before claiming a request the
 * handler checks whether WordPress already has a page or post at that path and
 * stands aside if it does. Existing content always wins.
 *
 * Matching the pattern is also not enough to render. The slug is resolved
 * through Service_Locations, and an unresolvable one 404s. Nothing trusts the
 * URL simply because its shape is right.
 *
 * ---------------------------------------------------------------------------
 * RESPONSES
 * ---------------------------------------------------------------------------
 *   canonical, resolvable        200, rendered
 *   alias of a canonical slug    301 to the canonical URL
 *   legacy ZIP form              301 to the canonical city/state URL
 *   anything else                404, through WordPress's own handling
 *
 * Rewrite rules are flushed on activation and on deactivation only — never on a
 * request. A flush rebuilds every rule on the site and is one of the most
 * expensive things a plugin can do casually.
 */
final class Rewrites {

	/**
	 * The query var carrying the matched slug.
	 */
	public const QUERY_VAR = 'threebest_landing';

	/**
	 * Matches "<something>-in-<something>", lowercase slug characters only.
	 *
	 * Deliberately not greedy about what a service or location looks like —
	 * that is the resolver's job, not the router's.
	 */
	private const PATTERN = '^([a-z0-9]+(?:-[a-z0-9]+)*-in-[a-z0-9]+(?:-[a-z0-9]+)*)/?$';

	/**
	 * Registers the rule, the query var and the handlers.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rule' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_var' ) );

		// parse_request runs before the main query, which is where a request
		// can still be redirected or handed back to WordPress cleanly.
		add_action( 'parse_request', array( self::class, 'resolve' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_404' ) );
		add_filter( 'template_include', array( self::class, 'use_template' ) );
	}

	/**
	 * Adds the rewrite rule.
	 */
	public static function add_rule(): void {
		add_rewrite_rule( self::PATTERN, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Registers the query var.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function add_query_var( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Resolves a matched request, redirecting or standing aside as needed.
	 *
	 * @param \WP $wp Current WordPress environment.
	 */
	public static function resolve( $wp ): void {
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$slug = Sanitizer::identifier( (string) $wp->query_vars[ self::QUERY_VAR ], 191 );

		if ( '' === $slug ) {
			unset( $wp->query_vars[ self::QUERY_VAR ] );

			return;
		}

		/*
		 * Existing WordPress content wins. The rule sits at the top of the list,
		 * so without this a page slugged with "-in-" would become unreachable.
		 */
		if ( self::wordpress_owns( $slug ) ) {
			unset( $wp->query_vars[ self::QUERY_VAR ] );
			$wp->query_vars['pagename'] = $slug;

			return;
		}

		$resolved = Service_Locations::resolve_slug( $slug );

		if ( null !== $resolved ) {
			// An alias such as "plumber-in-newark-new-jersey" resolves, but is
			// not the canonical URL. One page, one address.
			if ( empty( $resolved['is_canonical'] ) ) {
				self::redirect( home_url( '/' . $resolved['slug'] . '/' ) );
			}

			Landing_Page::set( $resolved );

			return;
		}

		// Legacy "/plumber-in-07102/". Not generated any more, but links exist.
		$legacy = Slug::parse_legacy_zip( $slug );

		if ( null !== $legacy ) {
			$location = Catalog::by_zip( $legacy['zip'] );

			if ( null !== $location ) {
				self::redirect( home_url( Slug::path( $legacy['service'], $location ) ) );
			}

			/*
			 * A ZIP we cannot place. The React version asked the geocoder here,
			 * but that would put an external API call behind a URL anyone can
			 * request — a rate-limit and cost problem with no upside, since an
			 * unplaceable ZIP has no canonical page to redirect to. It 404s,
			 * which is the honest answer: there is no such page.
			 */
			Logger::log( 'Legacy ZIP URL could not be placed.', array( 'zip_known' => false ) );
		}

		// Unresolvable. Leave the query var set so maybe_404() can answer.
		$wp->query_vars[ self::QUERY_VAR ] = $slug;
	}

	/**
	 * Turns an unresolved landing URL into a proper 404.
	 *
	 * WordPress's own 404 handling is used rather than a bespoke response, so
	 * the status, the body, the template and the robots behaviour all match
	 * every other missing page on the site.
	 */
	public static function maybe_404(): void {
		global $wp_query;

		if ( '' === get_query_var( self::QUERY_VAR, '' ) ) {
			return;
		}

		if ( Landing_Page::is_active() ) {
			return;
		}

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Swaps in the landing template for a resolved page.
	 *
	 * @param string $template Template WordPress chose.
	 */
	public static function use_template( $template ): string {
		if ( ! Landing_Page::is_active() ) {
			return (string) $template;
		}

		$located = locate_template( array( '3best-landing.php' ) );

		if ( '' !== $located ) {
			return $located;
		}

		// A theme without the template is a deployment error, not a reason to
		// render the wrong page.
		Logger::warning( 'Landing template missing from the active theme.' );

		return (string) $template;
	}

	/**
	 * Whether WordPress already has content at this path.
	 *
	 * @param string $slug Requested slug.
	 */
	private static function wordpress_owns( string $slug ): bool {
		$page = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );

		return $page instanceof \WP_Post;
	}

	/**
	 * Permanently redirects, preserving a query string if one was sent.
	 *
	 * 301 rather than the 308 the React app used: 301 is what WordPress,
	 * caches and search engines expect, and the method is never anything but
	 * GET here. Campaign parameters are kept so attribution survives the
	 * redirect; the canonical tag on the destination is what keeps the query
	 * string out of the indexed URL.
	 *
	 * @param string $destination Absolute canonical URL.
	 */
	private static function redirect( string $destination ): void {
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : '';

		if ( '' !== $query ) {
			$destination .= ( false === strpos( $destination, '?' ) ? '?' : '&' ) . $query;
		}

		wp_safe_redirect( $destination, 301 );
		exit;
	}
}
