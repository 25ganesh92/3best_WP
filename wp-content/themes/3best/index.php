<?php
/**
 * Fallback template.
 *
 * The 3Best site has no blog surface, so this exists only to satisfy the
 * WordPress template hierarchy. It renders the page shell with whatever the
 * query produced rather than a post loop with archive furniture.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="tb-page-hero">
	<div class="container">
		<div class="tb-page-hero__inner">
			<span class="tb-eyebrow"><?php esc_html_e( '3Best', '3best' ); ?></span>
			<h1><?php echo esc_html( wp_get_document_title() ); ?></h1>
		</div>
	</div>
</section>

<section class="tb-page-body">
	<div class="container">
		<div class="tb-prose">
			<?php if ( have_posts() ) : ?>
				<?php
				while ( have_posts() ) :
					the_post();
					the_content();
				endwhile;
				?>
			<?php else : ?>
				<p><?php esc_html_e( 'There is nothing to show here.', '3best' ); ?></p>
			<?php endif; ?>

			<div class="tb-cta">
				<?php threebest_button_link( home_url( '/' ), __( 'Back to Home', '3best' ), array( 'size' => 'lg' ) ); ?>
			</div>
		</div>
	</div>
</section>
<?php
get_footer();
