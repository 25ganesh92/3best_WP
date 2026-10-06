<?php
/**
 * Presentation helpers used by the templates.
 *
 * Everything here is presentational: markup, icons, image metadata. No data
 * lookup, no slug generation, no request handling — those belong to the plugin.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Inline SVG icon.
 *
 * Ported from the React Icons component. Inline rather than a sprite because
 * these icons inherit currentColor inside hover states, and there are few
 * enough that a sprite request would cost more than it saves.
 *
 * @param string $name  Icon name.
 * @param int    $size  Width and height in pixels.
 * @param string $class Optional CSS class.
 */
function threebest_icon( string $name, int $size = 20, string $class = '' ): string {
	$paths = array(
		'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'map-pin'   => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'check'     => '<path d="m20 6-11 11-5-5"/>',
		'clipboard' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
		'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
		'arrow'     => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
		'chevron'   => '<path d="m9 6 6 6-6 6"/>',
		'menu'      => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
		'close'     => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
		'home'      => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
		'building'  => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M9 7h2"/><path d="M13 7h2"/><path d="M9 11h2"/><path d="M13 11h2"/><path d="M10 21v-4h4v4"/>',
		'chart'     => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M21 20H3"/>',
		'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return sprintf(
		'<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"%2$s>%3$s</svg>',
		$size,
		'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
		$paths[ $name ]
	);
}

/**
 * The image manifest, ported from src/data/images.ts.
 *
 * Kept in one place for the same reason it is in the React project: no template
 * contains an image path or alt text, so replacing the photography means
 * editing this array or swapping the files in assets/images/.
 *
 * DEMO ASSETS. The photography is Unsplash placeholder material chosen to
 * communicate the concept, and alt text describes what is actually in each
 * photo — deliberately never naming the page's city, which would be both
 * inaccurate and keyword stuffing.
 *
 * @return array<string,array{src:string,width:int,height:int,alt:string}>
 */
function threebest_images(): array {
	$base = THREEBEST_THEME_URI . '/assets/images/';

	return array(
		'hero-home-services'          => array(
			'src'    => $base . 'hero/home-services.webp',
			'width'  => 900,
			'height' => 1125,
			'alt'    => __( 'Electrician in a hard hat testing wiring in an electrical box', '3best' ),
		),
		'hero-real-estate'            => array(
			'src'    => $base . 'hero/real-estate.webp',
			'width'  => 900,
			'height' => 1125,
			'alt'    => __( 'Modern American family home lit up at dusk', '3best' ),
		),
		'hero-finance'                => array(
			'src'    => $base . 'hero/finance.webp',
			'width'  => 900,
			'height' => 1125,
			'alt'    => __( 'Advisor going through paperwork with a client across a desk', '3best' ),
		),
		'hero-professional-services'  => array(
			'src'    => $base . 'hero/professional-services.webp',
			'width'  => 900,
			'height' => 1125,
			'alt'    => __( 'Colleagues working together around a boardroom table', '3best' ),
		),
		'category-real-estate'        => array(
			'src'    => $base . 'categories/real-estate.webp',
			'width'  => 800,
			'height' => 500,
			'alt'    => __( 'Modern American family home lit up at dusk', '3best' ),
		),
		'category-finance'            => array(
			'src'    => $base . 'categories/finance.webp',
			'width'  => 800,
			'height' => 500,
			'alt'    => __( 'Advisor going through paperwork with a client across a desk', '3best' ),
		),
		'category-home-services'      => array(
			'src'    => $base . 'categories/home-services.webp',
			'width'  => 800,
			'height' => 500,
			'alt'    => __( 'Roofer working on the shingles of a residential roof', '3best' ),
		),
		'category-professional-services' => array(
			'src'    => $base . 'categories/professional-services.webp',
			'width'  => 800,
			'height' => 500,
			'alt'    => __( 'Colleagues working together around a boardroom table', '3best' ),
		),
		'page-about'                  => array(
			'src'    => $base . 'pages/about.webp',
			'width'  => 1000,
			'height' => 750,
			'alt'    => __( 'Two people shaking hands at the end of a meeting', '3best' ),
		),
		'page-how-it-works'           => array(
			'src'    => $base . 'pages/how-it-works.webp',
			'width'  => 1000,
			'height' => 750,
			'alt'    => __( 'Two professionals in safety gear reviewing a job on site', '3best' ),
		),
		'page-why-3best'              => array(
			'src'    => $base . 'pages/why-3best.webp',
			'width'  => 1000,
			'height' => 750,
			'alt'    => __( 'Front porch of an American home on a sunny afternoon', '3best' ),
		),
	);
}

/**
 * Renders a framed image.
 *
 * The PHP counterpart of the React Media component: one place where aspect
 * ratio, rounding, object-fit and loading strategy are decided. The files in
 * assets/images/ are already correctly sized and cropped, so no server-side
 * resizing is involved.
 *
 * @param string $key      Image key from threebest_images().
 * @param array  $options  ratio, radius, priority, class, alt.
 */
function threebest_media( string $key, array $options = array() ): void {
	$images = threebest_images();

	if ( ! isset( $images[ $key ] ) ) {
		return;
	}

	$image    = $images[ $key ];
	$ratio    = isset( $options['ratio'] ) ? (string) $options['ratio'] : 'landscape';
	$radius   = isset( $options['radius'] ) ? (string) $options['radius'] : 'lg';
	$priority = ! empty( $options['priority'] );
	$class    = isset( $options['class'] ) ? (string) $options['class'] : '';
	$alt      = isset( $options['alt'] ) ? (string) $options['alt'] : $image['alt'];

	printf(
		'<div class="tb-media tb-media--%1$s tb-media--%2$s%3$s">',
		esc_attr( $ratio ),
		esc_attr( $radius ),
		'' !== $class ? ' ' . esc_attr( $class ) : ''
	);

	printf(
		'<img class="tb-media__img" src="%1$s" width="%2$d" height="%3$d" alt="%4$s" loading="%5$s" decoding="%6$s"%7$s>',
		esc_url( $image['src'] ),
		(int) $image['width'],
		(int) $image['height'],
		esc_attr( $alt ),
		$priority ? 'eager' : 'lazy',
		$priority ? 'sync' : 'async',
		$priority ? ' fetchpriority="high"' : ''
	);

	echo '</div>';
}

/**
 * Renders a button-styled link.
 *
 * @param string $href    Destination.
 * @param string $label   Visible text.
 * @param array  $options variant, size, class, full.
 */
function threebest_button_link( string $href, string $label, array $options = array() ): void {
	$variant = isset( $options['variant'] ) ? (string) $options['variant'] : 'primary';
	$size    = isset( $options['size'] ) ? (string) $options['size'] : 'md';

	$classes = array( 'tb-button', 'tb-button--' . $variant, 'tb-button--' . $size );

	if ( ! empty( $options['full'] ) ) {
		$classes[] = 'tb-button--full';
	}

	if ( ! empty( $options['class'] ) ) {
		$classes[] = (string) $options['class'];
	}

	printf(
		'<a class="%s" href="%s">%s</a>',
		esc_attr( implode( ' ', $classes ) ),
		esc_url( $href ),
		esc_html( $label )
	);
}

/**
 * The four service categories, for presentation only.
 *
 * PHASE 4 SWAP POINT. The authoritative service catalogue — 4 categories, 16
 * services, 64 subcategories, with the curated SEO slugs — belongs to
 * 3best-core and arrives in Phase 4. When the plugin answers this filter it
 * wins, and no template changes.
 *
 * Until then the theme supplies the four cards so the homepage and the services
 * page render at full parity rather than with holes in them. This is card
 * copy, not application logic: no slug is generated from it and nothing routes
 * by it.
 *
 * @return array<int,array{id:string,name:string,slug:string,description:string,icon:string}>
 */
function threebest_service_categories(): array {
	$fallback = array(
		array(
			'id'          => 'real-estate',
			'name'        => __( 'Real Estate', '3best' ),
			'slug'        => 'real-estate',
			'description' => __( 'Buying, selling, leasing and managing residential and commercial property.', '3best' ),
			'icon'        => 'building',
		),
		array(
			'id'          => 'finance',
			'name'        => __( 'Finance', '3best' ),
			'slug'        => 'finance',
			'description' => __( 'Tax, accounting, planning and lending support for people and businesses.', '3best' ),
			'icon'        => 'chart',
		),
		array(
			'id'          => 'home-services',
			'name'        => __( 'Home Services', '3best' ),
			'slug'        => 'home-services',
			'description' => __( 'Plumbing, HVAC, electrical and roofing work for homes and businesses.', '3best' ),
			'icon'        => 'home',
		),
		array(
			'id'          => 'professional-services',
			'name'        => __( 'Professional Services', '3best' ),
			'slug'        => 'professional-services',
			'description' => __( 'Legal, consulting, marketing and IT expertise for growing organisations.', '3best' ),
			'icon'        => 'briefcase',
		),
	);

	/**
	 * Filters the service categories used for presentation.
	 *
	 * 3best-core overrides this in Phase 4 with the authoritative catalogue.
	 *
	 * @param array $fallback Theme-supplied categories.
	 */
	$categories = apply_filters( 'threebest_service_categories', $fallback );

	return is_array( $categories ) && array() !== $categories ? $categories : $fallback;
}

/**
 * Renders the service/location search form.
 *
 * PHASE 4 SWAP POINT. The real two-question form — the searchable service
 * picker and the debounced location autocomplete — needs the service catalogue,
 * the location provider and the REST endpoint, all of which arrive in Phase 4.
 *
 * Until then this renders a call to action rather than a non-functional input.
 * Shipping a search box that silently does nothing would be worse than not
 * shipping one: it looks finished and fails on contact.
 *
 * @param string $context Where the form is being rendered: 'home' or 'services'.
 */
function threebest_search_form( string $context = 'home' ): void {
	if ( has_action( 'threebest_search_form' ) ) {
		do_action( 'threebest_search_form', $context );

		return;
	}

	/*
	 * The fallback CTA points at the services page, so it is suppressed there:
	 * offering someone a link to the page they are already reading is worse
	 * than offering nothing. Phase 4 fills this slot on both pages.
	 */
	if ( 'services' === $context ) {
		return;
	}

	echo '<div class="tb-search-placeholder">';
	threebest_button_link(
		home_url( '/services/' ),
		__( 'Browse Services', '3best' ),
		array(
			'size'  => 'lg',
			'class' => 'tb-search-placeholder__cta',
		)
	);
	echo '</div>';
}

/**
 * The common values every landing-page part needs.
 *
 * Phase 7 resolved the service and location before the template ran; this only
 * reads that decision and derives the few presentational values the parts
 * share. It resolves nothing, so a part can never disagree with the router.
 *
 * @return array<string,mixed>|null Null when no landing page is active.
 */
function threebest_landing_context(): ?array {
	static $context = null;

	if ( null !== $context ) {
		return $context;
	}

	if ( ! \ThreeBest\Core\Seo\Landing_Page::is_active() ) {
		return null;
	}

	$service  = \ThreeBest\Core\Seo\Landing_Page::service();
	$location = \ThreeBest\Core\Seo\Landing_Page::location();

	if ( null === $service || null === $location ) {
		return null;
	}

	$category = \ThreeBest\Core\Services\Services_Repository::category( (string) $service['category'] );

	$context = array(
		'service'   => $service,
		'location'  => $location,
		'content'   => \ThreeBest\Core\Seo\Landing_Page::content(),
		'label'     => \ThreeBest\Core\Location\Labels::label( $location ),
		'category'  => $category,
		'image_key' => 'category-' . ( null !== $category ? $category['slug'] : 'home-services' ),
		'related'   => array_slice(
			array_values(
				array_filter(
					\ThreeBest\Core\Services\Services_Repository::in_category( (string) $service['category'] ),
					static fn( array $item ): bool => $item['id'] !== $service['id']
				)
			),
			0,
			4
		),
	);

	return $context;
}
