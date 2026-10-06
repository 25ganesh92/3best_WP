<?php
/**
 * Generic page.
 *
 * Serves Contact, Privacy Policy and Terms of Service. Ported from the React
 * PageShell layout: hero with eyebrow / title / lead, then prose.
 *
 * The eyebrow and lead come from post meta when set, so the client can edit
 * them alongside the body without a developer.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

$threebest_legal_slugs = array( 'privacy-policy', 'terms-of-service' );
$threebest_slug        = (string) get_post_field( 'post_name', get_the_ID() );

$threebest_eyebrow = (string) get_post_meta( get_the_ID(), '_threebest_eyebrow', true );
$threebest_lead    = (string) get_post_meta( get_the_ID(), '_threebest_lead', true );

if ( '' === $threebest_eyebrow ) {
	$threebest_eyebrow = in_array( $threebest_slug, $threebest_legal_slugs, true )
		? __( 'Legal', '3best' )
		: get_the_title();
}
?>
<section class="tb-page-hero">
	<div class="container">
		<div class="tb-page-hero__inner">
			<span class="tb-eyebrow"><?php echo esc_html( $threebest_eyebrow ); ?></span>
			<h1><?php the_title(); ?></h1>
			<?php if ( '' !== $threebest_lead ) : ?>
				<p class="tb-page-hero__lead"><?php echo esc_html( $threebest_lead ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>

<section class="tb-page-body">
	<div class="container">
		<div class="tb-prose">
			<?php
			while ( have_posts() ) :
				the_post();
				the_content();
			endwhile;
			?>

			<div class="tb-cta">
				<?php threebest_button_link( home_url( '/#find' ), __( 'Find My 3 Best', '3best' ), array( 'size' => 'lg' ) ); ?>
			</div>
		</div>
	</div>
</section>
<?php
get_footer();
