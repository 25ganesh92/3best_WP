<?php
/**
 * Front page.
 *
 * The homepage content is editable WordPress content: headings, copy, buttons
 * and the application's own components, composed as blocks in Pages -> Home.
 * This template only provides the document around it, so changing the homepage
 * never means editing PHP.
 *
 * Components that need the application - the search form, the hero carousel,
 * the category cards - are dynamic blocks registered by 3best-core. They call
 * the renderers that already existed; no business logic lives in the content.
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
