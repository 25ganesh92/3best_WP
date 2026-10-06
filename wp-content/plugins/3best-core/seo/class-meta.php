<?php
/**
 * Landing page metadata.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Title, description, canonical, robots and social tags for a landing page.
 *
 * The project is deliberately SEO-plugin-free (confirmed decision 7), so this
 * is the only thing writing these tags. That removes the usual failure mode —
 * two systems both emitting a canonical, and nobody sure which one wins — but
 * it also means getting it right here is not optional.
 *
 * Everything is derived from the RESOLVED entities held by Landing_Page. None
 * of it is built from the requested URL, which is what guarantees that an alias
 * or a campaign-tagged link cannot produce a different canonical than the page
 * it actually is.
 *
 * Scope is strict: every filter here returns untouched unless the current
 * request is a resolved landing page. Nothing else on the site is affected.
 */
final class Meta {

	/**
	 * Registers the filters and the head output.
	 */
	public static function register(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );

		// Priority 1: these belong near the top of <head>, before anything a
		// theme or another plugin adds.
		add_action( 'wp_head', array( self::class, 'render' ), 1 );
	}

	/**
	 * Replaces the document title on a landing page.
	 *
	 * Returning a non-empty string short-circuits WordPress's own title
	 * assembly, so exactly one <title> is produced.
	 *
	 * @param string $title Title WordPress would use.
	 */
	public static function document_title( $title ): string {
		if ( ! Landing_Page::is_active() ) {
			return (string) $title;
		}

		$content = Landing_Page::content();

		return isset( $content['seo_title'] ) && '' !== $content['seo_title']
			? (string) $content['seo_title']
			: (string) $title;
	}

	/**
	 * Sets robots directives from the approved indexability decision.
	 *
	 * A combination that merely RESOLVES is not thereby indexable. Only the
	 * approved set is, and everything else is noindex,follow — present and
	 * usable, crawlable for its links, but kept out of the index so hundreds of
	 * near-identical pages cannot dilute the ones that matter.
	 *
	 * The site's own robots behaviour is untouched: this returns early on every
	 * request that is not a landing page, so there is no way for it to put
	 * WordPress behind noindex.
	 *
	 * @param array<string,mixed> $robots Directives so far.
	 * @return array<string,mixed>
	 */
	public static function robots( $robots ): array {
		$robots = is_array( $robots ) ? $robots : array();

		if ( ! Landing_Page::is_active() ) {
			return $robots;
		}

		// A site set to "discourage search engines" must stay discouraged.
		if ( ! get_option( 'blog_public' ) ) {
			return $robots;
		}

		unset( $robots['noindex'], $robots['index'], $robots['nofollow'] );

		if ( Landing_Page::is_indexable() ) {
			$robots['index']  = true;
			$robots['follow'] = true;

			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	}

	/**
	 * Outputs the canonical and social tags.
	 */
	public static function render(): void {
		if ( ! Landing_Page::is_active() ) {
			return;
		}

		$content     = Landing_Page::content();
		$canonical   = Landing_Page::canonical_url();
		$title       = isset( $content['seo_title'] ) ? (string) $content['seo_title'] : '';
		$description = isset( $content['seo_description'] ) ? (string) $content['seo_description'] : '';

		if ( '' === $canonical ) {
			return;
		}

		echo "\n<!-- 3Best -->\n";

		printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $description ) );

		// The canonical never carries a query string: a campaign-tagged visit
		// must consolidate onto the clean URL, not fragment the page's signals.
		printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $canonical ) );

		printf( "<meta property=\"og:type\" content=\"website\">\n" );
		printf( "<meta property=\"og:site_name\" content=\"%s\">\n", esc_attr( get_bloginfo( 'name' ) ) );
		printf( "<meta property=\"og:locale\" content=\"%s\">\n", esc_attr( str_replace( '-', '_', get_bloginfo( 'language' ) ) ) );
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $title ) );
		printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $description ) );
		printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $canonical ) );

		/*
		 * Twitter cards. The React implementation had none; they are added here
		 * because the audit identified them as a genuine gap, and a summary
		 * card costs nothing beyond these three lines.
		 */
		printf( "<meta name=\"twitter:card\" content=\"summary_large_image\">\n" );
		printf( "<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr( $title ) );
		printf( "<meta name=\"twitter:description\" content=\"%s\">\n", esc_attr( $description ) );

		$image = self::social_image();

		if ( '' !== $image ) {
			printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $image ) );
			printf( "<meta name=\"twitter:image\" content=\"%s\">\n", esc_url( $image ) );
		}

		echo "<!-- /3Best -->\n";
	}

	/**
	 * The social preview image for this page.
	 *
	 * Uses the service photograph the theme already ships. It is a stock
	 * placeholder, like every image in the project, and is deliberately NOT
	 * presented as a photograph of the place or the professionals.
	 */
	private static function social_image(): string {
		$service = Landing_Page::service();

		if ( null === $service ) {
			return '';
		}

		/**
		 * Filters the social preview image for a landing page.
		 *
		 * @param string              $url     Image URL, or ''.
		 * @param array<string,mixed> $service Canonical service record.
		 */
		return (string) apply_filters( 'threebest_core_social_image', '', $service );
	}
}
