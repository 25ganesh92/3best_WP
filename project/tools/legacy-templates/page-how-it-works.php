<?php
/**
 * How It Works page.
 *
 * Ported from app/how-it-works/page.tsx.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

$threebest_steps = array(
	array(
		'title' => __( 'Tell us what you need', '3best' ),
		'text'  => __( 'Start on the homepage by choosing a service and entering a city, state or ZIP code. That takes you to a page built specifically for that service in that place.', '3best' ),
	),
	array(
		'title' => __( 'Choose the type of work', '3best' ),
		'text'  => __( 'Every service has a handful of common request types — for plumbing that might be emergency work, drain cleaning or a water heater. Pick the one closest to your project.', '3best' ),
	),
	array(
		'title' => __( 'Add your details', '3best' ),
		'text'  => __( 'Your name, email, mobile number and the address where the work is needed. Nothing else. The address matters because it is what determines which professionals can actually help.', '3best' ),
	),
	array(
		'title' => __( 'Verify your phone', '3best' ),
		'text'  => __( 'We send a six-digit code to your mobile. Verifying it confirms your request is genuine, which is what makes it worth a professional’s time.', '3best' ),
	),
	array(
		'title' => __( 'Submit your request', '3best' ),
		'text'  => __( 'That is it. Your request is submitted and we work on finding your three best matches. There is no cost and no obligation.', '3best' ),
	),
);
?>
<section class="tb-page-hero">
	<div class="container">
		<div class="tb-page-hero__grid">
			<div class="tb-page-hero__inner">
				<span class="tb-eyebrow"><?php esc_html_e( 'How it works', '3best' ); ?></span>
				<h1><?php esc_html_e( 'From “I need help” to a submitted request', '3best' ); ?></h1>
				<p class="tb-page-hero__lead">
					<?php esc_html_e( 'The whole process is built around two questions: what do you need, and where do you need it.', '3best' ); ?>
				</p>
			</div>

			<div class="tb-page-hero__media">
				<?php
				threebest_media(
					'page-how-it-works',
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

<section class="tb-page-body">
	<div class="container">
		<ol class="tb-process">
			<?php foreach ( $threebest_steps as $threebest_index => $threebest_step ) : ?>
				<li class="tb-reveal tb-process__item" style="transition-delay:<?php echo (int) ( $threebest_index * 60 ); ?>ms">
					<span class="tb-process__number" aria-hidden="true"><?php echo (int) ( $threebest_index + 1 ); ?></span>
					<div>
						<h2 class="tb-process__title"><?php echo esc_html( $threebest_step['title'] ); ?></h2>
						<p class="tb-process__text"><?php echo esc_html( $threebest_step['text'] ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>

		<div class="tb-prose" style="margin-top:40px">
			<p class="tb-note">
				<?php esc_html_e( 'This is a concept demo. Phone verification, lead storage and lead delivery are simulated so the full journey can be reviewed before the production integrations are connected.', '3best' ); ?>
			</p>

			<div class="tb-cta">
				<?php threebest_button_link( home_url( '/#find' ), __( 'Find My 3 Best', '3best' ), array( 'size' => 'lg' ) ); ?>
			</div>
		</div>
	</div>
</section>
<?php
get_footer();
