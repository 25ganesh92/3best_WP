<?php
/**
 * The how-it-works page.
 *
 * Its content is editable WordPress content, composed as blocks in
 * Pages -> Edit. This template only provides the document around it.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

get_footer();
