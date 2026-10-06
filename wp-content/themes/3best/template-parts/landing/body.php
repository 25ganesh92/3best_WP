<?php
/**
 * Landing page part: body.
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
<section class="tb-section tb-section--alt" aria-labelledby="about-service">
	<div class="container">
		<div class="tb-landing-content">
			<div>
				<h2 id="about-service">
					<?php echo esc_html( $tb_service['name'] . ' in ' . $tb_label ); ?>
				</h2>
				<div class="tb-prose">
					<?php foreach ( $tb_content['paragraphs'] as $tb_paragraph ) : ?>
						<p><?php echo esc_html( $tb_paragraph ); ?></p>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( array() !== $tb_related ) : ?>
				<aside class="tb-landing-related">
					<h3 class="tb-landing-related__title">
						<?php
						printf(
							/* translators: 1: category name, 2: place label */
							esc_html__( 'Other %1$s in %2$s', '3best' ),
							esc_html( null !== $tb_category ? $tb_category['name'] : __( 'services', '3best' ) ),
							esc_html( $tb_label )
						);
						?>
					</h3>
					<ul class="tb-landing-related__list">
						<?php foreach ( $tb_related as $tb_item ) : ?>
							<li>
								<?php
								/*
								 * Links only to sibling services in the SAME
								 * location. No mass cross-linking: this mirrors
								 * the React page and keeps internal linking to
								 * what a visitor would plausibly want next.
								 */
								?>
								<a class="tb-landing-related__link" href="<?php echo esc_url( home_url( '/' . $tb_item['slug'] . '-in-' . $tb_location->slug . '/' ) ); ?>">
									<?php echo esc_html( $tb_item['name'] ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</aside>
			<?php endif; ?>
		</div>
	</div>
</section>
