<?php
/**
 * Dynamic blocks for the application's own components.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Frontend;

use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Seo\Landing_Page;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an editor place the application's components on a page.
 *
 * ---------------------------------------------------------------------------
 * THESE BLOCKS CONTAIN NO BUSINESS LOGIC
 * ---------------------------------------------------------------------------
 * Every render callback here does one thing: call the renderer that already
 * existed and capture its output. Search_Form::render(), Lead_Form::render()
 * and the landing-page content from Landing_Page are untouched, and remain the
 * single implementation of each component.
 *
 * A block decides WHERE a component appears on a page. It never decides what
 * the component does, what it validates, or what it stores. Service and
 * location resolution, canonical URLs, SEO, consent, OTP, lead storage,
 * delivery, security and rate limiting all stay exactly where they were.
 *
 * The landing blocks read the ALREADY-RESOLVED Landing_Page context. They
 * never resolve anything themselves, so a landing page that failed to resolve
 * cannot be half-rendered by a block that disagreed with the router.
 *
 * No build step: the editor registration is plain ES5 in assets/js/blocks.js,
 * so the plugin stays installable by copying a directory.
 */
final class Blocks {

	/**
	 * Blocks that render application components.
	 */
	private const COMPONENT_BLOCKS = array(
		'threebest/search-form',
		'threebest/lead-form',
		'threebest/services-catalogue',
		'threebest/media',
		'threebest/hero-carousel',
		'threebest/category-cards',
		'threebest/icon-list',
	);

	/**
	 * Blocks that render a resolved landing page.
	 */
	private const LANDING_BLOCKS = array(
		'threebest/landing-hero',
		'threebest/landing-subcategories',
		'threebest/landing-benefits',
		'threebest/landing-body',
		'threebest/landing-faq',
	);

	/**
	 * Registers the blocks and the editor assets.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_filter( 'block_categories_all', array( self::class, 'category' ), 10, 1 );
	}

	/**
	 * Adds a 3Best block category so the blocks are findable.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing categories.
	 * @return array<int,array<string,mixed>>
	 */
	public static function category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'threebest',
				'title' => __( '3Best', '3best-core' ),
			)
		);

		return $categories;
	}

	/**
	 * Registers every dynamic block.
	 */
	public static function register_blocks(): void {
		/*
		 * Registering twice is a notice rather than an error, but it is noise in
		 * a log that should stay quiet enough for a real entry to stand out.
		 */
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'threebest/search-form' ) ) {
			return;
		}

		register_block_type(
			'threebest/search-form',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best search form', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'search',
				'description'     => __( 'The service and location search. Submitting it sends the visitor to the matching page.', '3best-core' ),
				'attributes'      => array(
					'context' => array(
						'type'    => 'string',
						'default' => 'home',
					),
				),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_search_form' ),
			)
		);

		register_block_type(
			'threebest/lead-form',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best lead form', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'feedback',
				'description'     => __( 'The request form, with consent and phone verification. On a service/location page it uses that page automatically.', '3best-core' ),
				'attributes'      => array(
					'service'  => array( 'type' => 'string', 'default' => '' ),
					'location' => array( 'type' => 'string', 'default' => '' ),
				),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_lead_form' ),
			)
		);

		register_block_type(
			'threebest/services-catalogue',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best services catalogue', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'screenoptions',
				'description'     => __( 'Every service category and the services inside it, from the catalogue.', '3best-core' ),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_services_catalogue' ),
			)
		);

		register_block_type(
			'threebest/media',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best image', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'format-image',
				'description'     => __( 'One of the bundled theme images, with its ratio and corner treatment.', '3best-core' ),
				'attributes'      => array(
					'imageKey' => array( 'type' => 'string', 'default' => '' ),
					'ratio'    => array( 'type' => 'string', 'default' => 'landscape' ),
					'radius'   => array( 'type' => 'string', 'default' => 'xl' ),
				),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_media' ),
			)
		);

		register_block_type(
			'threebest/hero-carousel',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best hero carousel', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'images-alt2',
				'description'     => __( 'The rotating hero imagery used on the homepage.', '3best-core' ),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_hero_carousel' ),
			)
		);

		register_block_type(
			'threebest/category-cards',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best category cards', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'grid-view',
				'description'     => __( 'The four service categories as linked cards.', '3best-core' ),
				'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
				'render_callback' => array( self::class, 'render_category_cards' ),
			)
		);

		register_block_type(
			'threebest/icon-list',
			array(
				'api_version'     => 3,
				'title'           => __( '3Best icon list', '3best-core' ),
				'category'        => 'threebest',
				'icon'            => 'editor-ul',
				'description'     => __( 'A checked list in one of the themes list styles. One item per line.', '3best-core' ),
				'attributes'      => array(
					'variant' => array( 'type' => 'string', 'default' => 'reassurance' ),
					'items'   => array( 'type' => 'string', 'default' => '' ),
				),
				'supports'        => array( 'html' => false ),
				'render_callback' => array( self::class, 'render_icon_list' ),
			)
		);

		$landing = array(
			'threebest/landing-hero'          => array( 'title' => __( '3Best landing hero', '3best-core' ), 'icon' => 'cover-image', 'cb' => 'render_landing_hero' ),
			'threebest/landing-subcategories' => array( 'title' => __( '3Best landing service picker', '3best-core' ), 'icon' => 'grid-view', 'cb' => 'render_landing_subcategories' ),
			'threebest/landing-benefits'      => array( 'title' => __( '3Best landing benefits', '3best-core' ), 'icon' => 'yes-alt', 'cb' => 'render_landing_benefits' ),
			'threebest/landing-body'          => array( 'title' => __( '3Best landing body copy', '3best-core' ), 'icon' => 'editor-paragraph', 'cb' => 'render_landing_body' ),
			'threebest/landing-faq'           => array( 'title' => __( '3Best landing FAQ', '3best-core' ), 'icon' => 'editor-help', 'cb' => 'render_landing_faq' ),
		);

		foreach ( $landing as $name => $spec ) {
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'title'           => $spec['title'],
					'category'        => 'threebest',
					'icon'            => $spec['icon'],
					'description'     => __( 'Renders from the resolved service and location of the page being viewed.', '3best-core' ),
					'supports'        => array( 'html' => false, 'align' => array( 'wide', 'full' ) ),
					'render_callback' => array( self::class, $spec['cb'] ),
				)
			);
		}
	}

	/**
	 * Loads the editor registration script.
	 */
	public static function editor_assets(): void {
		$relative = 'assets/js/blocks.js';
		$path     = THREEBEST_CORE_DIR . $relative;

		wp_enqueue_script(
			'threebest-blocks',
			THREEBEST_CORE_URL . $relative,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			file_exists( $path ) ? (string) filemtime( $path ) : THREEBEST_CORE_VERSION,
			true
		);

		wp_add_inline_script(
			'threebest-blocks',
			'window.threebestBlockData = ' . wp_json_encode( self::editor_data() ) . ';',
			'before'
		);
	}

	/**
	 * Non-sensitive data the editor registration needs.
	 *
	 * Service slugs and names only: the same public catalogue the front end
	 * already ships. No credentials, no settings, no lead data.
	 *
	 * @return array<string,mixed>
	 */
	private static function editor_data(): array {
		$services = array();

		foreach ( Services_Repository::all() as $service ) {
			$services[] = array(
				'slug' => (string) $service['slug'],
				'name' => (string) $service['name'],
			);
		}

		return array(
			'services'      => $services,
			'componentList' => self::COMPONENT_BLOCKS,
			'landingList'   => self::LANDING_BLOCKS,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Component blocks
	 * ------------------------------------------------------------------ */

	/**
	 * The search form.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_search_form( array $attributes ): string {
		$context = Sanitizer::choice( $attributes['context'] ?? 'home', array( 'home', 'services' ), 'home' );

		ob_start();
		do_action( 'threebest_search_form', $context );

		return (string) ob_get_clean();
	}

	/**
	 * The lead form.
	 *
	 * On a resolved landing page the service and location come from
	 * Landing_Page, so an editor never has to keep a block attribute in step
	 * with the URL. Elsewhere the block's own attributes are used, and the
	 * endpoint re-resolves both on submission regardless.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_lead_form( array $attributes ): string {
		$service  = Sanitizer::slug( $attributes['service'] ?? '', 64 );
		$location = Sanitizer::slug( $attributes['location'] ?? '', 96 );

		if ( Landing_Page::is_active() ) {
			$resolved_service  = Landing_Page::service();
			$resolved_location = Landing_Page::location();

			if ( null !== $resolved_service && null !== $resolved_location ) {
				$service  = (string) $resolved_service['slug'];
				$location = $resolved_location->slug;
			}
		}

		if ( '' === $service || '' === $location ) {
			return self::editor_notice( __( 'The lead form needs a service and a location. Add this block to a service/location page, or choose them in the block settings.', '3best-core' ) );
		}

		ob_start();
		do_action( 'threebest_lead_form', $service, $location );

		return (string) ob_get_clean();
	}

	/**
	 * The full services catalogue.
	 */
	public static function render_services_catalogue(): string {
		ob_start();

		echo '<div class="tb-services__list">';

		foreach ( Services_Repository::categories() as $category ) {
			echo '<div class="tb-services__category" id="' . esc_attr( (string) $category['slug'] ) . '">';
			echo '<div class="tb-services__category-head"><div><h2>' . esc_html( (string) $category['name'] ) . '</h2>';
			echo '<p class="tb-services__category-description">' . esc_html( (string) $category['description'] ) . '</p></div></div>';

			do_action( 'threebest_category_services', $category );

			echo '</div>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * One of the theme's bundled images.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_media( array $attributes ): string {
		$key = Sanitizer::slug( $attributes['imageKey'] ?? '', 64 );

		if ( '' === $key || ! function_exists( 'threebest_media' ) ) {
			return self::editor_notice( __( 'Choose an image in the block settings.', '3best-core' ) );
		}

		ob_start();
		threebest_media(
			$key,
			array(
				'ratio'    => Sanitizer::choice( $attributes['ratio'] ?? 'landscape', array( 'landscape', 'square', 'portrait' ), 'landscape' ),
				'radius'   => Sanitizer::choice( $attributes['radius'] ?? 'xl', array( 'sm', 'md', 'lg', 'xl' ), 'xl' ),
				'priority' => true,
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * The homepage hero carousel.
	 */
	public static function render_hero_carousel(): string {
		ob_start();
		get_template_part( 'template-parts/hero-carousel' );

		return (string) ob_get_clean();
	}

	/**
	 * The four service categories as linked cards.
	 */
	public static function render_category_cards(): string {
		if ( ! function_exists( 'threebest_service_categories' ) ) {
			return '';
		}

		ob_start();
		echo '<div class="tb-categories">';

		foreach ( threebest_service_categories() as $index => $category ) {
			echo '<div class="tb-reveal" style="transition-delay:' . (int) ( $index * 80 ) . 'ms">';
			get_template_part(
				'template-parts/service-card',
				null,
				array(
					'icon'        => $category['icon'],
					'title'       => $category['name'],
					'description' => $category['description'],
					'href'        => home_url( '/services/#' . $category['slug'] ),
				)
			);
			echo '</div>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * A checked list, in one of the three list styles the design uses.
	 *
	 * Items are plain text, one per line, optionally "Title | description".
	 * The markup and the icon come from the theme, so an editor changes wording
	 * without touching PHP and without being able to break the layout.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_icon_list( array $attributes ): string {
		$variant = Sanitizer::choice( $attributes['variant'] ?? 'reassurance', array( 'reassurance', 'steps', 'process', 'value' ), 'reassurance' );
		$raw     = (string) ( $attributes['items'] ?? '' );
		$lines   = array_values( array_filter( array_map( 'trim', preg_split( '/
||
/', $raw ) ?: array() ) ) );

		if ( array() === $lines ) {
			return self::editor_notice( __( 'Add one item per line in the block settings.', '3best-core' ) );
		}

		$icon = function_exists( 'threebest_icon' ) ? threebest_icon( 'check', 15 ) : '';
		ob_start();

		if ( 'reassurance' === $variant ) {
			echo '<ul class="tb-reassurance">';
			foreach ( $lines as $line ) {
				echo '<li class="tb-reassurance__item">';
				echo function_exists( 'threebest_icon' ) ? threebest_icon( 'check', 15, 'tb-reassurance__icon' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
				echo esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		} elseif ( 'steps' === $variant ) {
			echo '<ol class="tb-steps">';
			foreach ( $lines as $index => $line ) {
				list( $title, $text ) = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				echo '<li class="tb-reveal tb-steps__item" style="transition-delay:' . (int) ( $index * 90 ) . 'ms">';
				echo '<div class="tb-steps__head"><span class="tb-steps__icon">' . ( function_exists( 'threebest_icon' ) ? threebest_icon( 'check', 22 ) : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
				echo '<span class="tb-steps__number" aria-hidden="true">' . esc_html( sprintf( '%02d', $index + 1 ) ) . '</span></div>';
				echo '<h3 class="tb-steps__title">' . esc_html( $title ) . '</h3>';
				echo '<p class="tb-steps__text">' . esc_html( $text ) . '</p></li>';
			}
			echo '</ol>';
		} elseif ( 'process' === $variant ) {
			/* A real <ol>: a numbered process is a list, and screen readers should be told so. */
			echo '<ol class="tb-process">';
			foreach ( $lines as $index => $line ) {
				list( $title, $text ) = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				echo '<li class="tb-reveal tb-process__item" style="transition-delay:' . (int) ( $index * 60 ) . 'ms">';
				echo '<span class="tb-process__number" aria-hidden="true">' . esc_html( (string) ( $index + 1 ) ) . '</span>';
				echo '<div><h2 class="tb-process__title">' . esc_html( $title ) . '</h2>';
				echo '<p class="tb-process__text">' . esc_html( $text ) . '</p></div></li>';
			}
			echo '</ol>';
		} else {
			echo '<ul class="tb-value__list">';
			foreach ( $lines as $line ) {
				list( $title, $text ) = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				echo '<li class="tb-value__item"><span class="tb-value__icon" aria-hidden="true">' . $icon . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
				echo '<span><span class="tb-value__item-title">' . esc_html( $title ) . '</span>';
				echo '<span class="tb-value__item-text">' . esc_html( $text ) . '</span></span></li>';
			}
			echo '</ul>';
		}

		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ *
	 * Landing-page blocks
	 * ------------------------------------------------------------------ */

	/**
	 * The landing hero: badges, H1, intro and calls to action.
	 */
	public static function render_landing_hero(): string {
		if ( ! Landing_Page::is_active() ) {
			return self::editor_notice( __( 'Landing hero: shows the service, location and generated headline of a service/location page.', '3best-core' ) );
		}

		ob_start();
		get_template_part( 'template-parts/landing/hero' );

		return (string) ob_get_clean();
	}

	/**
	 * The subcategory picker.
	 */
	public static function render_landing_subcategories(): string {
		if ( ! Landing_Page::is_active() ) {
			return self::editor_notice( __( 'Service picker: the four subcategories of the resolved service.', '3best-core' ) );
		}

		ob_start();
		get_template_part( 'template-parts/landing/subcategories' );

		return (string) ob_get_clean();
	}

	/**
	 * The benefits grid.
	 */
	public static function render_landing_benefits(): string {
		if ( ! Landing_Page::is_active() ) {
			return self::editor_notice( __( 'Benefits: the generated reasons to use 3Best for this service.', '3best-core' ) );
		}

		ob_start();
		get_template_part( 'template-parts/landing/benefits' );

		return (string) ob_get_clean();
	}

	/**
	 * The body copy and related services.
	 */
	public static function render_landing_body(): string {
		if ( ! Landing_Page::is_active() ) {
			return self::editor_notice( __( 'Body copy: the generated paragraphs about this service in this place, plus sibling services.', '3best-core' ) );
		}

		ob_start();
		get_template_part( 'template-parts/landing/body' );

		return (string) ob_get_clean();
	}

	/**
	 * The FAQ list.
	 */
	public static function render_landing_faq(): string {
		if ( ! Landing_Page::is_active() ) {
			return self::editor_notice( __( 'FAQ: the generated questions and answers for this service and location.', '3best-core' ) );
		}

		ob_start();
		get_template_part( 'template-parts/landing/faq' );

		return (string) ob_get_clean();
	}

	/**
	 * A quiet placeholder shown where a block cannot render.
	 *
	 * Visible in the editor so an author understands what the block will do,
	 * and harmless on the front end where it simply explains that this block
	 * belongs on a service/location page.
	 *
	 * @param string $message Explanation.
	 */
	private static function editor_notice( string $message ): string {
		if ( ! is_admin() && ! Landing_Page::is_active() && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<p class="tb-block-placeholder">' . esc_html( $message ) . '</p>';
	}
}
