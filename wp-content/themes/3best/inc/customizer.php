<?php
/**
 * Header and footer settings, so ordinary changes need no PHP.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * The header and footer values an administrator can change.
 *
 * Deliberately narrow. The design is a built system, not a page builder, so
 * what is exposed here is the text and the links that genuinely change over the
 * life of a site — the call to action, the footer blurb, the small print — plus
 * the logo and the two menus. Colour and typography come from theme.json, where
 * the block editor already presents them.
 *
 * Every setting has the current hard-coded wording as its default, so the site
 * looks identical until somebody deliberately changes something.
 *
 * @return array<string,array{default:string,label:string,type:string}>
 */
function threebest_customizer_fields(): array {
	return array(
		'threebest_header_cta_text'   => array(
			'default' => __( 'Get Started', '3best' ),
			'label'   => __( 'Header button text', '3best' ),
			'type'    => 'text',
		),
		'threebest_header_cta_url'    => array(
			'default' => home_url( '/#find' ),
			'label'   => __( 'Header button link', '3best' ),
			'type'    => 'url',
		),
		'threebest_footer_about'      => array(
			'default' => __( '3Best helps people across the United States describe what they need and where they need it, then works to connect them with local professionals who can help.', '3best' ),
			'label'   => __( 'Footer description', '3best' ),
			'type'    => 'textarea',
		),
		'threebest_footer_note'       => array(
			'default' => __( 'Concept demo — brand and integrations are placeholders', '3best' ),
			'label'   => __( 'Footer small print', '3best' ),
			'type'    => 'text',
		),
		'threebest_footer_nav_title'  => array(
			'default' => __( 'Navigation', '3best' ),
			'label'   => __( 'Footer navigation column title', '3best' ),
			'type'    => 'text',
		),
		'threebest_footer_legal_title' => array(
			'default' => __( 'Legal', '3best' ),
			'label'   => __( 'Footer legal column title', '3best' ),
			'type'    => 'text',
		),
	);
}

/**
 * Reads one setting, falling back to the wording the theme shipped with.
 *
 * @param string $key Setting key.
 */
function threebest_option( string $key ): string {
	$fields = threebest_customizer_fields();

	if ( ! isset( $fields[ $key ] ) ) {
		return '';
	}

	$value = get_theme_mod( $key, $fields[ $key ]['default'] );

	return is_string( $value ) && '' !== trim( $value ) ? $value : (string) $fields[ $key ]['default'];
}

/**
 * Registers the section and its controls.
 *
 * @param WP_Customize_Manager $wp_customize Customizer manager.
 */
function threebest_customize_register( $wp_customize ): void {
	$wp_customize->add_section(
		'threebest_header_footer',
		array(
			'title'       => __( 'Header & footer', '3best' ),
			'priority'    => 30,
			'description' => __( 'Text and links in the header and footer. Menus are set under Menus; colours and type under Styles.', '3best' ),
		)
	);

	foreach ( threebest_customizer_fields() as $key => $field ) {
		$sanitize = 'url' === $field['type'] ? 'esc_url_raw' : ( 'textarea' === $field['type'] ? 'sanitize_textarea_field' : 'sanitize_text_field' );

		$wp_customize->add_setting(
			$key,
			array(
				'default'           => $field['default'],
				'sanitize_callback' => $sanitize,
				'transport'         => 'refresh',
			)
		);

		$wp_customize->add_control(
			$key,
			array(
				'label'   => $field['label'],
				'section' => 'threebest_header_footer',
				'type'    => 'textarea' === $field['type'] ? 'textarea' : ( 'url' === $field['type'] ? 'url' : 'text' ),
			)
		);
	}
}
add_action( 'customize_register', 'threebest_customize_register' );

/**
 * A footer widget area, for anything the fixed columns do not cover.
 */
function threebest_register_sidebars(): void {
	register_sidebar(
		array(
			'name'          => __( 'Footer extras', '3best' ),
			'id'            => 'threebest-footer',
			'description'   => __( 'Optional. Appears above the footer small print.', '3best' ),
			'before_widget' => '<div id="%1$s" class="tb-footer__widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h2 class="tb-footer__column-title">',
			'after_title'   => '</h2>',
		)
	);
}
add_action( 'widgets_init', 'threebest_register_sidebars' );
