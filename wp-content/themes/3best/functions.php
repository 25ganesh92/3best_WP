<?php
/**
 * 3Best theme bootstrap.
 *
 * Deliberately thin. This file wires up three includes and nothing else — no
 * business logic lives in a theme. Services, locations, slug generation, REST
 * endpoints, lead handling, verification, delivery and SEO output all belong to
 * the 3best-core plugin, so the site keeps working if the theme is replaced.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'THREEBEST_THEME_VERSION', '0.3.0' );
define( 'THREEBEST_THEME_DIR', get_template_directory() );
define( 'THREEBEST_THEME_URI', get_template_directory_uri() );

require_once THREEBEST_THEME_DIR . '/inc/setup.php';
require_once THREEBEST_THEME_DIR . '/inc/enqueue.php';
require_once THREEBEST_THEME_DIR . '/inc/template-tags.php';
require_once THREEBEST_THEME_DIR . '/inc/patterns.php';
require_once THREEBEST_THEME_DIR . '/inc/customizer.php';
