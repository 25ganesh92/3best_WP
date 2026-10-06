<?php
/**
 * Document head and site header.
 *
 * Ported from app/layout.tsx and components/Header.
 *
 * @package ThreeBest\Theme
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$threebest_nav = array(
	'/services/'     => __( 'Services', '3best' ),
	'/how-it-works/' => __( 'How It Works', '3best' ),
	'/about/'        => __( 'About Us', '3best' ),
);

/**
 * Whether a navigation path is the current page.
 *
 * Mirrors the React header's pathname.startsWith() active state.
 *
 * @param string $path Site-relative path.
 */
function threebest_nav_is_active( string $path ): bool {
	$current = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$current = (string) wp_parse_url( $current, PHP_URL_PATH );
	$target  = (string) wp_parse_url( home_url( $path ), PHP_URL_PATH );

	return '' !== $target && 0 === strpos( untrailingslashit( $current ), untrailingslashit( $target ) );
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#1a56db">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', '3best' ); ?></a>

<header class="tb-header">
	<div class="container tb-header__inner">
		<a class="tb-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( '3Best — home', '3best' ); ?>">
			<span class="tb-logo__mark">3</span><span>Best</span>
		</a>

		<nav aria-label="<?php esc_attr_e( 'Main', '3best' ); ?>">
			<?php if ( has_nav_menu( 'primary' ) ) : ?>
				<?php
				// Once the client assigns a menu, it takes over from the fixed links.
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'tb-nav',
						'depth'          => 1,
						'link_before'    => '',
					)
				);
				?>
			<?php else : ?>
				<ul class="tb-nav">
					<?php foreach ( $threebest_nav as $threebest_path => $threebest_label ) : ?>
						<li>
							<a
								class="tb-nav__link<?php echo threebest_nav_is_active( $threebest_path ) ? ' is-active' : ''; ?>"
								href="<?php echo esc_url( home_url( $threebest_path ) ); ?>"
							><?php echo esc_html( $threebest_label ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</nav>

		<div class="tb-header__actions">
			<?php
			threebest_button_link(
				threebest_option( 'threebest_header_cta_url' ),
				threebest_option( 'threebest_header_cta_text' ),
				array( 'class' => 'tb-desktop-cta' )
			);
			?>

			<button
				type="button"
				class="tb-menu-button"
				aria-expanded="false"
				aria-controls="tb-mobile-navigation"
				aria-label="<?php esc_attr_e( 'Open menu', '3best' ); ?>"
				data-tb-menu-toggle
			>
				<?php
				echo threebest_icon( 'menu', 24, 'tb-menu-button__open' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
				echo threebest_icon( 'close', 24, 'tb-menu-button__close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG.
				?>
			</button>
		</div>
	</div>

	<div class="tb-mobile-nav" id="tb-mobile-navigation" hidden>
		<div class="container">
			<ul class="tb-mobile-nav__list">
				<?php foreach ( $threebest_nav as $threebest_path => $threebest_label ) : ?>
					<li>
						<a class="tb-mobile-nav__link" href="<?php echo esc_url( home_url( $threebest_path ) ); ?>">
							<?php echo esc_html( $threebest_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			threebest_button_link(
				threebest_option( 'threebest_header_cta_url' ),
				threebest_option( 'threebest_header_cta_text' ),
				array(
					'size'  => 'lg',
					'full'  => true,
					'class' => 'tb-mobile-nav__cta',
				)
			);
			?>
		</div>
	</div>
</header>

<main id="main">
