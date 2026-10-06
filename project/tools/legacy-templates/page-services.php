<?php
/**
 * Services page.
 *
 * Ported from app/services/page.tsx — PARTIAL, by design.
 *
 * The page chrome and the four category sections are complete. The service
 * grid inside each category (16 services, 64 subcategories, with their curated
 * SEO slugs) is deliberately left as a Phase 4 slot: that catalogue is
 * authoritative data owned by 3best-core, and duplicating it into a theme to
 * make this page look finished sooner would create exactly the second source of
 * truth the migration is meant to avoid.
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
			<span class="tb-eyebrow"><?php esc_html_e( 'Services', '3best' ); ?></span>
			<h1><?php esc_html_e( 'Every service we cover', '3best' ); ?></h1>
			<p class="tb-page-hero__lead">
				<?php esc_html_e( 'Choose the service you need and tell us where you need it. Each service below opens a request for professionals working in your area.', '3best' ); ?>
			</p>
		</div>

		<div class="tb-services__search">
			<?php threebest_search_form( 'services' ); ?>
		</div>
	</div>
</section>

<section class="tb-page-body">
	<div class="container">
		<?php foreach ( threebest_service_categories() as $threebest_category ) : ?>
			<div class="tb-services__category" id="<?php echo esc_attr( $threebest_category['slug'] ); ?>">
				<div class="tb-services__category-head">
					<span class="tb-services__category-icon" aria-hidden="true">
						<?php echo threebest_icon( $threebest_category['icon'], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					</span>
					<div>
						<h2><?php echo esc_html( $threebest_category['name'] ); ?></h2>
						<p class="tb-services__category-description">
							<?php echo esc_html( $threebest_category['description'] ); ?>
						</p>
					</div>
				</div>

				<?php
				/**
				 * Renders the services within a category.
				 *
				 * PHASE 4 SLOT. 3best-core hooks this with the authoritative
				 * catalogue; the markup it will produce is already styled
				 * (.tb-services__grid / __service / __sub-list).
				 *
				 * @param array $category The category being rendered.
				 */
				do_action( 'threebest_category_services', $threebest_category );
				?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
<?php
get_footer();
