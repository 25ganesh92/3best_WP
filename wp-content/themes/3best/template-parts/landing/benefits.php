<?php
/**
 * Landing page part: benefits.
 *
 * Extracted verbatim from 3best-landing.php so the rendered markup is
 * unchanged. Resolution still happens in the plugin; this only presents the
 * already-resolved values.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$tb = threebest_landing_context();

if ( null === $tb ) {
	return;
}

$tb_service  = $tb['service'];
$tb_location = $tb['location'];
$tb_content  = $tb['content'];
$tb_label    = $tb['label'];
$tb_category = $tb['category'];
$tb_related  = $tb['related'];
$tb_image_key = $tb['image_key'];
?>
<section class="tb-section tb-section--alt" aria-labelledby="why-3best">
	<div class="container">
		<div class="tb-reveal tb-section__head">
			<span class="tb-eyebrow"><?php esc_html_e( 'Why use 3Best', '3best' ); ?></span>
			<h2 id="why-3best">
				<?php
				printf(
					/* translators: %s: service term, e.g. "plumbing" */
					esc_html__( 'A simpler way to start a %s project', '3best' ),
					esc_html( $tb_service['service_term'] )
				);
				?>
			</h2>
		</div>

		<div class="tb-landing-benefits">
			<?php foreach ( $tb_content['benefits'] as $tb_index => $tb_benefit ) : ?>
				<div class="tb-reveal tb-landing-benefit" style="transition-delay:<?php echo (int) ( $tb_index * 80 ); ?>ms">
					<span class="tb-landing-benefit__icon" aria-hidden="true">
						<?php echo threebest_icon( 'check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					</span>
					<div>
						<h3 class="tb-landing-benefit__title"><?php echo esc_html( $tb_benefit['title'] ); ?></h3>
						<p class="tb-landing-benefit__text"><?php echo esc_html( $tb_benefit['description'] ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
