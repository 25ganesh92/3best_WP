<?php
/**
 * About page.
 *
 * Ported from app/about/page.tsx.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();
?>
<section class="tb-page-hero">
	<div class="container">
		<div class="tb-page-hero__grid">
			<div class="tb-page-hero__inner">
				<span class="tb-eyebrow"><?php esc_html_e( 'About us', '3best' ); ?></span>
				<h1><?php esc_html_e( 'Three good options beat a hundred search results', '3best' ); ?></h1>
				<p class="tb-page-hero__lead">
					<?php esc_html_e( '3Best exists to make the first step of hiring a professional as short as possible.', '3best' ); ?>
				</p>
			</div>

			<div class="tb-page-hero__media">
				<?php
				threebest_media(
					'page-about',
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
		<div class="tb-prose">
			<p>
				<?php esc_html_e( 'Finding a professional in the United States usually means opening a dozen tabs, comparing listings that all look the same, and calling several businesses before anyone picks up. Most people stop halfway.', '3best' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'We think that first step should take a minute. Choose the service you need, tell us where you need it, describe the job in a sentence or two, and we take it from there.', '3best' ); ?>
			</p>
		</div>

		<div class="tb-reveal tb-split" style="margin-top:56px">
			<div class="tb-prose">
				<h2><?php esc_html_e( 'What we ask for, and why', '3best' ); ?></h2>
				<ul>
					<li>
						<strong><?php esc_html_e( 'Service and location', '3best' ); ?></strong>
						<?php esc_html_e( '— so we know who could realistically help.', '3best' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'The type of work', '3best' ); ?></strong>
						<?php esc_html_e( '— an emergency repair and a planned installation are very different requests.', '3best' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Your contact details and address', '3best' ); ?></strong>
						<?php esc_html_e( '— so a professional can reach you about the right property.', '3best' ); ?>
					</li>
				</ul>
				<p>
					<?php esc_html_e( 'We verify your mobile number before submitting a request. It keeps the requests genuine, which is what makes them worth responding to.', '3best' ); ?>
				</p>
			</div>

			<div class="tb-split__media">
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

		<div class="tb-prose" style="margin-top:48px">
			<p class="tb-note">
				<?php esc_html_e( '3Best is temporary branding for this concept demo. The name, logo and copy are placeholders and are designed to be replaced.', '3best' ); ?>
			</p>

			<div class="tb-cta">
				<?php threebest_button_link( home_url( '/#find' ), __( 'Find My 3 Best', '3best' ), array( 'size' => 'lg' ) ); ?>
			</div>
		</div>
	</div>
</section>
<?php
get_footer();
