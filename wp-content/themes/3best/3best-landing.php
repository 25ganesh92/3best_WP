<?php
/**
 * Service/location landing page.
 *
 * Ported from app/[slug]/page.tsx. One template serves every combination.
 *
 * NO RESOLUTION HAPPENS HERE. The service, the location, the canonical slug and
 * the indexability decision were all settled by the plugin before this file ran,
 * and are read from Landing_Page. A template asking "is this indexable?" would
 * be a second source of truth for the single most consequential SEO decision in
 * the project.
 *
 * PRESENTATION IS NOW EDITABLE. The order and composition of the sections live
 * in one block layout (Landing_Template) that an administrator edits in the
 * ordinary block editor. The copy inside each section is still generated per
 * combination by Content_Generator, so editing the layout can never produce
 * hand-written copy for one city that drifts from every other.
 *
 * If that layout is missing or renders to nothing, this falls back to the six
 * PHP parts it replaced — so a half-saved layout degrades to the previous
 * behaviour rather than to a blank page.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

use ThreeBest\Core\Seo\Landing_Page;
use ThreeBest\Core\Seo\Landing_Template;

// Defensive: the plugin only routes here once both resolve, but a template
// should not fatal if that ever changes.
if ( null === Landing_Page::service() || null === Landing_Page::location() ) {
	get_template_part( '404' );

	return;
}

get_header();

$tb_layout = Landing_Template::render();

if ( '' !== $tb_layout ) {
	echo $tb_layout; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block output, escaped by each block's own renderer.
} else {
	foreach ( array( 'hero', 'subcategories', 'benefits', 'form', 'body', 'faq' ) as $tb_part ) {
		get_template_part( 'template-parts/landing/' . $tb_part );
	}
}

get_footer();
