<?php
/**
 * The one editable layout shared by every service/location page.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Seo;

use ThreeBest\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * ONE layout, rendered for every combination.
 *
 * ---------------------------------------------------------------------------
 * WHY A SINGLE POST AND NOT FOURTEEN PAGES
 * ---------------------------------------------------------------------------
 * There are 16 services across the curated catalogue; the renderable space is
 * several hundred combinations and the approved set changes from an admin
 * screen. Fourteen hand-made pages would be fourteen things to keep in step
 * with a decision recorded somewhere else, and would break the moment a
 * fifteenth combination was approved.
 *
 * So the LAYOUT is editable exactly once, as blocks, and the CONTENT stays
 * generated per combination by Content_Generator from the resolved service and
 * location. An editor rearranges sections, adds a paragraph or drops one; they
 * never author copy for a specific city.
 *
 * Stored as a private post type so it is edited with the ordinary block editor
 * — no bespoke builder, no second rendering engine. It is excluded from search,
 * from feeds, from the REST posts collection for anonymous callers, and has no
 * public permalink: it is a layout, not a page.
 *
 * FALLBACK IS THE OLD TEMPLATE. If the layout post is missing, empty, or its
 * blocks render to nothing, the landing template falls back to the PHP parts
 * that preceded this change. A broken or half-saved layout degrades to the
 * previous behaviour rather than to a blank page.
 */
final class Landing_Template {

	public const POST_TYPE = 'threebest_layout';

	private const OPTION_ID = 'threebest_landing_layout_id';

	/**
	 * Registers the post type and seeds the layout once.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'admin_init', array( self::class, 'ensure_layout' ) );
	}

	/**
	 * The layout post type.
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Landing layout', '3best-core' ),
					'singular_name' => __( 'Landing layout', '3best-core' ),
					'edit_item'     => __( 'Edit the landing layout', '3best-core' ),
					'menu_name'     => __( 'Landing layout', '3best-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_ui'             => true,
				'show_in_menu'        => 'threebest-core',
				'show_in_rest'        => true,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Creates the layout the first time an administrator opens the admin.
	 *
	 * Seeded with the same six sections the PHP template rendered, in the same
	 * order, so the very first render after this change is byte-identical to
	 * the one before it.
	 */
	public static function ensure_layout(): void {
		if ( self::layout_id() > 0 ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		if ( array() !== $existing ) {
			update_option( self::OPTION_ID, (int) $existing[0], false );

			return;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => __( 'Service / location landing layout', '3best-core' ),
				'post_content' => self::default_blocks(),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			Logger::warning( 'Landing layout could not be created.', array( 'code' => $id->get_error_code() ) );

			return;
		}

		update_option( self::OPTION_ID, (int) $id, false );

		Logger::log( 'Landing layout created.', array( 'id' => (int) $id ) );
	}

	/**
	 * The seeded layout: the six sections the PHP template already rendered.
	 */
	private static function default_blocks(): string {
		return implode(
			"\n\n",
			array(
				'<!-- wp:threebest/landing-hero /-->',
				'<!-- wp:threebest/landing-subcategories /-->',
				'<!-- wp:threebest/landing-benefits /-->',
				'<!-- wp:threebest/lead-form /-->',
				'<!-- wp:threebest/landing-body /-->',
				'<!-- wp:threebest/landing-faq /-->',
			)
		);
	}

	/**
	 * The stored layout post id, or 0.
	 */
	public static function layout_id(): int {
		$id = (int) get_option( self::OPTION_ID, 0 );

		if ( $id <= 0 ) {
			return 0;
		}

		$post = get_post( $id );

		return ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) ? $id : 0;
	}

	/**
	 * Renders the layout for the landing page currently resolved.
	 *
	 * @return string Rendered blocks, or '' when there is no usable layout.
	 */
	public static function render(): string {
		if ( ! Landing_Page::is_active() ) {
			return '';
		}

		$id = self::layout_id();

		if ( 0 === $id ) {
			return '';
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || '' === trim( (string) $post->post_content ) ) {
			return '';
		}

		/*
		 * do_blocks() only. Not the_content: that filter carries wpautop,
		 * shortcode expansion and whatever else a plugin has attached to post
		 * bodies, none of which belongs in a layout made of explicit blocks.
		 */
		$html = do_blocks( (string) $post->post_content );

		return '' === trim( wp_strip_all_tags( $html ) ) && false === strpos( $html, '<section' )
			? ''
			: $html;
	}
}
