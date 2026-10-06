<?php
/**
 * 404.
 *
 * Ported from components/NotFoundView. The React version offers the search
 * form and example landing-page links; both need Phase 4 data, so this offers
 * the two navigation routes that exist today instead of linking to pages that
 * are not built yet.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="tb-notfound">
	<div class="container">
		<div class="tb-notfound__inner">
			<span class="tb-notfound__code"><?php esc_html_e( '404', '3best' ); ?></span>
			<h1><?php esc_html_e( 'We could not find that page', '3best' ); ?></h1>
			<p class="tb-notfound__lead">
				<?php esc_html_e( 'The page you were looking for may have moved, or the link may be out of date. You can start a new search from the homepage.', '3best' ); ?>
			</p>

			<div class="tb-notfound__actions">
				<?php
				threebest_button_link( home_url( '/' ), __( 'Back to Home', '3best' ), array( 'size' => 'lg' ) );
				threebest_button_link(
					home_url( '/services/' ),
					__( 'Browse Services', '3best' ),
					array(
						'size'    => 'lg',
						'variant' => 'secondary',
					)
				);
				?>
			</div>
		</div>
	</div>
</section>
<?php
get_footer();
