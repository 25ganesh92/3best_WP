<?php
/**
 * Runtime requirements gate.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the host can actually run this plugin before any of it loads.
 *
 * The local environment passed Phase 0, but the production cPanel host has not
 * been verified. Failing loudly and inertly on activation is far better than
 * fataling mid-request on a missing extension.
 */
final class Requirements {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.7';

	/**
	 * Extensions the application genuinely depends on.
	 *
	 * intl is deliberately absent: slug folding uses WordPress core's
	 * remove_accents(), which is pure PHP. intl must never become a hard
	 * runtime dependency.
	 */
	private const REQUIRED_EXTENSIONS = array( 'curl', 'mbstring', 'json', 'openssl' );

	/**
	 * Whether every requirement is satisfied.
	 */
	public static function are_met(): bool {
		return array() === self::failures();
	}

	/**
	 * Unsatisfied requirements, as human-readable strings.
	 *
	 * @return string[]
	 */
	public static function failures(): array {
		$failures = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'PHP %1$s or newer is required. This server runs PHP %2$s.', '3best-core' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		if ( version_compare( get_bloginfo( 'version' ), self::MIN_WP, '<' ) ) {
			$failures[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'WordPress %1$s or newer is required. This site runs WordPress %2$s.', '3best-core' ),
				self::MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		foreach ( self::REQUIRED_EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$failures[] = sprintf(
					/* translators: %s: PHP extension name */
					__( 'The PHP extension "%s" is required but not loaded.', '3best-core' ),
					$extension
				);
			}
		}

		return $failures;
	}

	/**
	 * Optional capabilities. Reported for visibility; never required.
	 *
	 * @return array<string,bool>
	 */
	public static function optional(): array {
		return array(
			'intl'    => extension_loaded( 'intl' ),
			'gd'      => extension_loaded( 'gd' ),
			'imagick' => extension_loaded( 'imagick' ),
		);
	}

	/**
	 * Admin notice listing why the plugin is inert.
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( '3Best Core is inactive: this server does not meet its requirements.', '3best-core' );
		echo '</strong></p><ul style="list-style:disc;margin-left:20px">';

		foreach ( self::failures() as $failure ) {
			echo '<li>' . esc_html( $failure ) . '</li>';
		}

		echo '</ul></div>';
	}
}
