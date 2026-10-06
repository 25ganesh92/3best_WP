<?php
/**
 * Site footer.
 *
 * Ported from components/Footer.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$threebest_footer_nav = array(
	'/services/'     => __( 'Services', '3best' ),
	'/how-it-works/' => __( 'How It Works', '3best' ),
	'/about/'        => __( 'About Us', '3best' ),
	'/contact/'      => __( 'Contact', '3best' ),
);

$threebest_footer_legal = array(
	'/privacy-policy/'   => __( 'Privacy Policy', '3best' ),
	'/terms-of-service/' => __( 'Terms of Service', '3best' ),
);
?>
</main>

<footer class="tb-footer">
	<div class="container">
		<div class="tb-footer__grid">
			<div class="tb-footer__brand">
				<a class="tb-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<span class="tb-logo__mark">3</span><span>Best</span>
				</a>
				<p class="tb-footer__about">
					<?php echo esc_html( threebest_option( 'threebest_footer_about' ) ); ?>
				</p>
			</div>

			<div>
				<h2 class="tb-footer__column-title"><?php echo esc_html( threebest_option( 'threebest_footer_nav_title' ) ); ?></h2>
				<ul class="tb-footer__list">
					<?php foreach ( $threebest_footer_nav as $threebest_path => $threebest_label ) : ?>
						<li>
							<a class="tb-footer__link" href="<?php echo esc_url( home_url( $threebest_path ) ); ?>">
								<?php echo esc_html( $threebest_label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div>
				<h2 class="tb-footer__column-title"><?php echo esc_html( threebest_option( 'threebest_footer_legal_title' ) ); ?></h2>
				<ul class="tb-footer__list">
					<?php foreach ( $threebest_footer_legal as $threebest_path => $threebest_label ) : ?>
						<li>
							<a class="tb-footer__link" href="<?php echo esc_url( home_url( $threebest_path ) ); ?>">
								<?php echo esc_html( $threebest_label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="tb-footer__bottom">
			<span>
				<?php
				printf(
					/* translators: %s: current year */
					esc_html__( '© %s 3Best. All rights reserved.', '3best' ),
					esc_html( wp_date( 'Y' ) )
				);
				?>
			</span>
			<span class="tb-footer__note">
				<?php echo esc_html( threebest_option( 'threebest_footer_note' ) ); ?>
			</span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
