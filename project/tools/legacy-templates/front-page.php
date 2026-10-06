<?php
/**
 * Homepage.
 *
 * Ported from app/page.tsx: hero, how it works, categories, value proposition.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

$threebest_steps = array(
	array(
		'number' => '01',
		'icon'   => 'search',
		'title'  => __( 'Tell Us What You Need', '3best' ),
		'text'   => __( 'Choose a service and enter your location.', '3best' ),
	),
	array(
		'number' => '02',
		'icon'   => 'clipboard',
		'title'  => __( 'Tell Us About Your Project', '3best' ),
		'text'   => __( 'Answer a few quick questions and provide your contact details.', '3best' ),
	),
	array(
		'number' => '03',
		'icon'   => 'users',
		'title'  => __( 'Get Your 3 Best Matches', '3best' ),
		'text'   => __( 'We’ll help connect you with professionals who can help.', '3best' ),
	),
);

$threebest_value_points = array(
	array(
		'title' => __( 'Local professionals', '3best' ),
		'text'  => __( 'We focus on the service you need and the place you need it.', '3best' ),
	),
	array(
		'title' => __( 'Quick and easy request process', '3best' ),
		'text'  => __( 'Two questions to start, then a short form. No calling around.', '3best' ),
	),
	array(
		'title' => __( 'No obligation to get started', '3best' ),
		'text'  => __( 'Submitting a request is free and you decide what happens next.', '3best' ),
	),
);

$threebest_reassurance = array(
	__( 'Free to submit', '3best' ),
	__( 'No obligation', '3best' ),
	__( 'Takes about a minute', '3best' ),
);
?>

<section class="tb-home-hero" id="find">
	<div class="container">
		<div class="tb-home-hero__grid">
			<div class="tb-home-hero__content">
				<span class="tb-pill-eyebrow"><?php esc_html_e( 'Local professionals, made simple', '3best' ); ?></span>
				<h1 class="tb-home-hero__title"><?php esc_html_e( 'Find Your 3 Best Local Professionals', '3best' ); ?></h1>
				<p class="tb-home-hero__lead">
					<?php esc_html_e( 'Tell us what service you need and where you need it. We’ll help you find qualified professionals in your area.', '3best' ); ?>
				</p>

				<?php threebest_search_form(); ?>

				<ul class="tb-reassurance">
					<?php foreach ( $threebest_reassurance as $threebest_item ) : ?>
						<li class="tb-reassurance__item">
							<?php echo threebest_icon( 'check', 15, 'tb-reassurance__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
							<?php echo esc_html( $threebest_item ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="tb-home-hero__media">
				<?php get_template_part( 'template-parts/hero-carousel' ); ?>
			</div>
		</div>
	</div>
</section>

<section class="tb-section" aria-labelledby="how-it-works">
	<div class="container">
		<div class="tb-reveal tb-section__head tb-section__head--center">
			<span class="tb-eyebrow"><?php esc_html_e( 'How it works', '3best' ); ?></span>
			<h2 id="how-it-works"><?php esc_html_e( 'Three steps, start to finish', '3best' ); ?></h2>
			<p class="tb-section__lead"><?php esc_html_e( 'The whole process is designed to take a couple of minutes.', '3best' ); ?></p>
		</div>

		<ol class="tb-steps">
			<?php foreach ( $threebest_steps as $threebest_index => $threebest_step ) : ?>
				<li class="tb-reveal tb-steps__item" style="transition-delay:<?php echo (int) ( $threebest_index * 90 ); ?>ms">
					<div class="tb-steps__head">
						<span class="tb-steps__icon" aria-hidden="true">
							<?php echo threebest_icon( $threebest_step['icon'], 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
						</span>
						<span class="tb-steps__number" aria-hidden="true"><?php echo esc_html( $threebest_step['number'] ); ?></span>
					</div>
					<h3 class="tb-steps__title"><?php echo esc_html( $threebest_step['title'] ); ?></h3>
					<p class="tb-steps__text"><?php echo esc_html( $threebest_step['text'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</section>

<section class="tb-section tb-section--alt" aria-labelledby="popular-categories">
	<div class="container">
		<div class="tb-reveal tb-section__head tb-section__head--center">
			<span class="tb-eyebrow"><?php esc_html_e( 'Popular service categories', '3best' ); ?></span>
			<h2 id="popular-categories"><?php esc_html_e( 'What can we help you with?', '3best' ); ?></h2>
			<p class="tb-section__lead"><?php esc_html_e( 'Browse the categories we cover, or search for exactly what you need above.', '3best' ); ?></p>
		</div>

		<div class="tb-categories">
			<?php foreach ( threebest_service_categories() as $threebest_index => $threebest_category ) : ?>
				<div class="tb-reveal" style="transition-delay:<?php echo (int) ( $threebest_index * 80 ); ?>ms">
					<?php
					get_template_part(
						'template-parts/service-card',
						null,
						array(
							'title'        => $threebest_category['name'],
							'description'  => $threebest_category['description'],
							'href'         => home_url( '/services/#' . $threebest_category['slug'] ),
							'action_label' => __( 'Explore Services', '3best' ),
							'icon'         => $threebest_category['icon'],
							'image'        => 'category-' . $threebest_category['slug'],
						)
					);
					?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="tb-section" aria-labelledby="value-proposition">
	<div class="container">
		<div class="tb-value">
			<div class="tb-reveal">
				<span class="tb-eyebrow"><?php esc_html_e( 'Why 3Best', '3best' ); ?></span>
				<h2 class="tb-value__title" id="value-proposition">
					<?php esc_html_e( 'One Simple Request.', '3best' ); ?><br>
					<?php esc_html_e( 'Three Great Options.', '3best' ); ?>
				</h2>
				<p class="tb-value__text">
					<?php esc_html_e( 'Instead of searching, comparing and calling one professional at a time, tell us once what you need. We take it from there.', '3best' ); ?>
				</p>

				<ul class="tb-value__list">
					<?php foreach ( $threebest_value_points as $threebest_point ) : ?>
						<li class="tb-value__item">
							<span class="tb-value__icon" aria-hidden="true">
								<?php echo threebest_icon( 'check', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
							</span>
							<span>
								<span class="tb-value__item-title"><?php echo esc_html( $threebest_point['title'] ); ?></span>
								<span class="tb-value__item-text"><?php echo esc_html( $threebest_point['text'] ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="tb-value__cta">
					<?php threebest_button_link( home_url( '/#find' ), __( 'Find My 3 Best', '3best' ), array( 'size' => 'lg' ) ); ?>
				</div>
			</div>

			<div class="tb-reveal tb-value__media" style="transition-delay:100ms">
				<?php
				threebest_media(
					'page-why-3best',
					array(
						'ratio'  => 'landscape',
						'radius' => 'xl',
					)
				);
				?>
			</div>
		</div>
	</div>
</section>

<?php
get_footer();
