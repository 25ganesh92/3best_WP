<?php
/**
 * Landing page part: form.
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
<section class="tb-section tb-landing-form" aria-labelledby="request-form">
	<div class="container">
		<h2 id="request-form" class="sr-only">
			<?php
			printf(
				/* translators: 1: service name, 2: place label */
				esc_html__( 'Request %1$s in %2$s', '3best' ),
				esc_html( $tb_service['name'] ),
				esc_html( $tb_label )
			);
			?>
		</h2>

		<div class="tb-landing-form__wrap">
			<?php
			/*
			 * The Phase 5 lead form, unchanged. Its context comes from the
			 * server-resolved slugs; the browser cannot change which service or
			 * location a submission belongs to, because the endpoint resolves
			 * both again from the posted slugs and rejects anything that does
			 * not match a canonical record.
			 */
			do_action( 'threebest_lead_form', (string) $tb_service['slug'], $tb_location->slug );
			?>
		</div>
	</div>
</section>
