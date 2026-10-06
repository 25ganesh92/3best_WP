<?php
/**
 * Landing page part: hero.
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
<section class="tb-landing-hero">
	<div class="container">
		<nav class="tb-breadcrumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', '3best' ); ?>">
			<ol class="tb-breadcrumbs__list">
				<li><a class="tb-breadcrumbs__link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', '3best' ); ?></a></li>
				<li class="tb-breadcrumbs__separator" aria-hidden="true"><?php echo threebest_icon( 'chevron', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></li>
				<li><a class="tb-breadcrumbs__link" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Services', '3best' ); ?></a></li>
				<li class="tb-breadcrumbs__separator" aria-hidden="true"><?php echo threebest_icon( 'chevron', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></li>
				<li><a class="tb-breadcrumbs__link" href="<?php echo esc_url( home_url( '/services/#' . $tb_service['slug'] ) ); ?>"><?php echo esc_html( $tb_service['name'] ); ?></a></li>
				<li class="tb-breadcrumbs__separator" aria-hidden="true"><?php echo threebest_icon( 'chevron', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></li>
				<li><span class="tb-breadcrumbs__current" aria-current="page"><?php echo esc_html( $tb_service['professional_plural'] . ' in ' . $tb_label ); ?></span></li>
			</ol>
		</nav>

		<div class="tb-landing-hero__grid">
			<div class="tb-landing-hero__content">
				<div class="tb-landing-hero__badges">
					<span class="tb-landing-badge">
						<?php echo threebest_icon( 'search', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						<?php echo esc_html( $tb_service['name'] ); ?>
					</span>
					<span class="tb-landing-badge">
						<?php echo threebest_icon( 'map-pin', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						<?php echo esc_html( $tb_label ); ?>
					</span>
				</div>

				<h1><?php echo esc_html( $tb_content['h1'] ); ?></h1>
				<p class="tb-landing-hero__lead"><?php echo esc_html( $tb_content['intro'] ); ?></p>

				<div class="tb-landing-hero__cta">
					<?php
					threebest_button_link( '#lead-form', __( 'Find My 3 Best', '3best' ), array( 'size' => 'lg' ) );
					threebest_button_link(
						home_url( '/' ),
						__( 'Change service or location', '3best' ),
						array(
							'size'    => 'lg',
							'variant' => 'secondary',
						)
					);
					?>
				</div>
			</div>

			<div class="tb-landing-hero__media">
				<?php
				threebest_media(
					$tb_image_key,
					array(
						'ratio'    => 'landscape',
						'radius'   => 'xl',
						'priority' => true,
					)
				);
				?>
			</div>
		</div>
	</div>
</section>
