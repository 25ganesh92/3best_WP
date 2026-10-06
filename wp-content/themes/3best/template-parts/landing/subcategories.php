<?php
/**
 * Landing page part: subcategories.
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
<section class="tb-section" aria-labelledby="popular-services">
	<div class="container">
		<div class="tb-reveal tb-section__head">
			<span class="tb-eyebrow"><?php echo esc_html( $tb_content['services_eyebrow'] ); ?></span>
			<h2 id="popular-services"><?php echo esc_html( $tb_content['services_heading'] ); ?></h2>
			<p class="tb-section__lead"><?php echo esc_html( $tb_content['services_intro'] ); ?></p>
		</div>

		<div class="tb-categories">
			<?php foreach ( $tb_service['subcategories'] as $tb_index => $tb_subcategory ) : ?>
				<div class="tb-reveal" style="transition-delay:<?php echo (int) ( $tb_index * 70 ); ?>ms">
					<?php
					/*
					 * Each card jumps to the form and preselects itself. The
					 * value is a canonical subcategory id, and the server
					 * re-checks that it belongs to this service on submission.
					 */
					?>
					<button
						type="button"
						class="tb-card tb-subcategory"
						data-tb-pick-subcategory="<?php echo esc_attr( $tb_subcategory['id'] ); ?>"
					>
						<span class="tb-card__content">
							<span class="tb-card__icon" aria-hidden="true">
								<?php echo threebest_icon( null !== $tb_category ? $tb_category['icon'] : 'home', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
							</span>
							<span class="tb-card__title"><?php echo esc_html( $tb_subcategory['name'] ); ?></span>
							<span class="tb-card__description"><?php echo esc_html( $tb_subcategory['description'] ); ?></span>
							<span class="tb-card__action">
								<?php esc_html_e( 'Choose this', '3best' ); ?>
								<?php echo threebest_icon( 'arrow', 16, 'tb-card__arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
							</span>
						</span>
					</button>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
