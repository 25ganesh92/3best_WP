<?php
/**
 * Landing page part: faq.
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
<section class="tb-section" aria-labelledby="faq">
	<div class="container">
		<div class="tb-section__head">
			<span class="tb-eyebrow"><?php esc_html_e( 'Questions', '3best' ); ?></span>
			<h2 id="faq"><?php esc_html_e( 'Frequently asked questions', '3best' ); ?></h2>
		</div>

		<div class="tb-faq">
			<?php foreach ( $tb_content['faqs'] as $tb_faq ) : ?>
				<?php /* Native <details>: keyboard accessible and works with no JavaScript. */ ?>
				<details class="tb-faq__item">
					<summary class="tb-faq__question">
						<?php echo esc_html( $tb_faq['question'] ); ?>
						<?php echo threebest_icon( 'chevron', 20, 'tb-faq__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					</summary>
					<p class="tb-faq__answer"><?php echo esc_html( $tb_faq['answer'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>
