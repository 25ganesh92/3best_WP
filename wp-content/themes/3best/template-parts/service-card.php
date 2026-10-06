<?php
/**
 * Service / category card.
 *
 * Ported from components/ServiceCard (link variant).
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$tb = wp_parse_args(
	is_array( $args ) ? $args : array(),
	array(
		'title'        => '',
		'description'  => '',
		'href'         => '',
		'action_label' => '',
		'icon'         => '',
		'image'        => '',
	)
);

if ( '' === $tb['title'] || '' === $tb['href'] ) {
	return;
}

$tb_images = threebest_images();
$tb_image  = '' !== $tb['image'] && isset( $tb_images[ $tb['image'] ] ) ? $tb_images[ $tb['image'] ] : null;
?>
<a class="tb-card" href="<?php echo esc_url( $tb['href'] ); ?>">
	<?php if ( null !== $tb_image ) : ?>
		<span class="tb-card__media">
			<img
				src="<?php echo esc_url( $tb_image['src'] ); ?>"
				width="<?php echo (int) $tb_image['width']; ?>"
				height="<?php echo (int) $tb_image['height']; ?>"
				alt="<?php echo esc_attr( $tb_image['alt'] ); ?>"
				loading="lazy"
				decoding="async"
			>
		</span>
	<?php endif; ?>

	<span class="tb-card__content">
		<?php if ( '' !== $tb['icon'] ) : ?>
			<span class="tb-card__icon" aria-hidden="true">
				<?php echo threebest_icon( $tb['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
			</span>
		<?php endif; ?>

		<span class="tb-card__title"><?php echo esc_html( $tb['title'] ); ?></span>

		<?php if ( '' !== $tb['description'] ) : ?>
			<span class="tb-card__description"><?php echo esc_html( $tb['description'] ); ?></span>
		<?php endif; ?>

		<?php if ( '' !== $tb['action_label'] ) : ?>
			<span class="tb-card__action">
				<?php echo esc_html( $tb['action_label'] ); ?>
				<?php echo threebest_icon( 'arrow', 16, 'tb-card__arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
			</span>
		<?php endif; ?>
	</span>
</a>
