<?php
/**
 * Homepage hero carousel.
 *
 * Ported from components/HeroCarousel. Every slide is in the DOM and the first
 * is active before JavaScript runs, so the hero is never blank and degrades to
 * a single static image if the script fails.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$tb_images = threebest_images();

$tb_slides = array(
	array(
		'image'       => 'hero-home-services',
		'eyebrow'     => __( 'Home Services', '3best' ),
		'title'       => __( 'Trusted Home Service Professionals', '3best' ),
		'description' => __( 'Find qualified professionals serving your area.', '3best' ),
	),
	array(
		'image'       => 'hero-real-estate',
		'eyebrow'     => __( 'Real Estate', '3best' ),
		'title'       => __( 'Real Estate Professionals', '3best' ),
		'description' => __( 'Connect with professionals who understand your local market.', '3best' ),
	),
	array(
		'image'       => 'hero-finance',
		'eyebrow'     => __( 'Finance', '3best' ),
		'title'       => __( 'Financial Professionals', '3best' ),
		'description' => __( 'Find professionals who can help with your financial needs.', '3best' ),
	),
	array(
		'image'       => 'hero-professional-services',
		'eyebrow'     => __( 'Professional Services', '3best' ),
		'title'       => __( 'Professional Services', '3best' ),
		'description' => __( 'Find experienced professionals for your business needs.', '3best' ),
	),
);

$tb_count = count( $tb_slides );
?>
<div
	class="tb-carousel"
	role="group"
	aria-roledescription="carousel"
	aria-label="<?php esc_attr_e( 'How 3Best helps across our service categories', '3best' ); ?>"
	data-tb-carousel
	tabindex="0"
>
	<?php foreach ( $tb_slides as $tb_index => $tb_slide ) : ?>
		<?php
		$tb_image  = $tb_images[ $tb_slide['image'] ] ?? null;
		$tb_active = 0 === $tb_index;

		if ( null === $tb_image ) {
			continue;
		}
		?>
		<div
			class="tb-carousel__slide<?php echo $tb_active ? ' is-active' : ''; ?>"
			role="group"
			aria-roledescription="slide"
			aria-label="<?php
				printf(
					/* translators: 1: slide number, 2: total slides, 3: slide category */
					esc_attr__( '%1$d of %2$d: %3$s', '3best' ),
					(int) $tb_index + 1,
					(int) $tb_count,
					esc_attr( $tb_slide['eyebrow'] )
				);
			?>"
			<?php echo $tb_active ? '' : 'aria-hidden="true" inert'; ?>
			data-tb-slide
		>
			<img
				src="<?php echo esc_url( $tb_image['src'] ); ?>"
				width="<?php echo (int) $tb_image['width']; ?>"
				height="<?php echo (int) $tb_image['height']; ?>"
				alt="<?php echo esc_attr( $tb_image['alt'] ); ?>"
				loading="<?php echo $tb_active ? 'eager' : 'lazy'; ?>"
				decoding="<?php echo $tb_active ? 'sync' : 'async'; ?>"
				<?php echo $tb_active ? 'fetchpriority="high"' : ''; ?>
			>
			<div class="tb-carousel__scrim" aria-hidden="true"></div>
			<div class="tb-carousel__caption">
				<span class="tb-carousel__eyebrow"><?php echo esc_html( $tb_slide['eyebrow'] ); ?></span>
				<p class="tb-carousel__title"><?php echo esc_html( $tb_slide['title'] ); ?></p>
				<p class="tb-carousel__description"><?php echo esc_html( $tb_slide['description'] ); ?></p>
			</div>
		</div>
	<?php endforeach; ?>

	<div class="tb-carousel__controls">
		<ul class="tb-carousel__dots">
			<?php foreach ( $tb_slides as $tb_index => $tb_slide ) : ?>
				<li>
					<button
						type="button"
						class="tb-carousel__dot<?php echo 0 === $tb_index ? ' is-active' : ''; ?>"
						aria-label="<?php
							printf(
								/* translators: 1: slide number, 2: slide category */
								esc_attr__( 'Show slide %1$d: %2$s', '3best' ),
								(int) $tb_index + 1,
								esc_attr( $tb_slide['eyebrow'] )
							);
						?>"
						aria-current="<?php echo 0 === $tb_index ? 'true' : 'false'; ?>"
						data-tb-dot="<?php echo (int) $tb_index; ?>"
					></button>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="tb-carousel__arrows">
			<button type="button" class="tb-carousel__arrow" aria-label="<?php esc_attr_e( 'Previous slide', '3best' ); ?>" data-tb-prev>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14.5 6-6 6 6 6"/></svg>
			</button>
			<button type="button" class="tb-carousel__arrow" aria-label="<?php esc_attr_e( 'Next slide', '3best' ); ?>" data-tb-next>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9.5 6 6 6-6 6"/></svg>
			</button>
		</div>
	</div>
</div>
