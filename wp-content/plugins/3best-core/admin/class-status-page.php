<?php
/**
 * Read-only status screen.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

use ThreeBest\Core\Requirements;
use ThreeBest\Core\Schema;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The overview screen: is this installation healthy, and is it configured.
 *
 * Read-only by construction. Everything that changes state lives on one of the
 * Phase 8 screens — Leads, SEO Pages, Settings — each of which carries its own
 * capability check and nonce. Keeping the screen an operator lands on free of
 * write controls means a misdirected click here cannot change anything.
 *
 * The delivery panel is rendered by Delivery_Admin, which owns the one action
 * reachable from this page (running the queue by hand) along with its nonce.
 */
final class Status_Page {

	public const MENU_SLUG  = 'threebest-core';
	public const CAPABILITY = 'manage_options';

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', '3best-core' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '3Best Core', '3best-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Installation health and integration status. Use Leads, SEO Pages and Settings to make changes.', '3best-core' ) . '</p>';

		self::render_versions();
		self::render_tables();
		Delivery_Admin::render();
		self::render_environment();
		self::render_configuration();

		echo '</div>';
	}

	/**
	 * Plugin and schema versions.
	 */
	private static function render_versions(): void {
		$installed = Schema::installed_version();

		echo '<h2>' . esc_html__( 'Versions', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		self::row(
			__( 'Plugin version', '3best-core' ),
			esc_html( THREEBEST_CORE_VERSION )
		);

		self::row(
			__( 'Schema version (code)', '3best-core' ),
			esc_html( Schema::SCHEMA_VERSION )
		);

		self::row(
			__( 'Schema version (installed)', '3best-core' ),
			'' === $installed
				? self::badge( false, __( 'not installed', '3best-core' ) )
				: esc_html( $installed )
		);

		self::row(
			__( 'Migration state', '3best-core' ),
			Schema::needs_upgrade()
				? self::badge( false, __( 'upgrade pending', '3best-core' ) )
				: self::badge( true, __( 'up to date', '3best-core' ) )
		);

		echo '</tbody></table>';
	}

	/**
	 * Custom table state.
	 */
	private static function render_tables(): void {
		echo '<h2>' . esc_html__( 'Database tables', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'Table', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'State', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Columns', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Indexes', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Rows', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Schema::status() as $status ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $status['table'] ) . '</code></td>';
			echo '<td>' . self::badge(
				(bool) $status['exists'],
				$status['exists'] ? __( 'present', '3best-core' ) : __( 'missing', '3best-core' )
			) . '</td>';
			echo '<td>' . esc_html( (string) $status['columns'] ) . '</td>';
			echo '<td>' . esc_html( (string) $status['indexes'] ) . '</td>';
			echo '<td>' . esc_html( (string) $status['rows'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Host capabilities.
	 */
	private static function render_environment(): void {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Environment', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		self::row( __( 'PHP', '3best-core' ), esc_html( PHP_VERSION ) );
		self::row( __( 'WordPress', '3best-core' ), esc_html( get_bloginfo( 'version' ) ) );
		self::row( __( 'Database', '3best-core' ), esc_html( (string) $wpdb->db_version() ) );
		self::row( __( 'Table prefix', '3best-core' ), '<code>' . esc_html( $wpdb->prefix ) . '</code>' );

		foreach ( array( 'curl', 'mbstring', 'json', 'openssl' ) as $extension ) {
			self::row(
				/* translators: %s: PHP extension name */
				sprintf( __( 'Extension: %s', '3best-core' ), $extension ),
				self::badge( extension_loaded( $extension ), extension_loaded( $extension ) ? __( 'loaded', '3best-core' ) : __( 'missing (required)', '3best-core' ) )
			);
		}

		foreach ( Requirements::optional() as $extension => $loaded ) {
			self::row(
				/* translators: %s: PHP extension name */
				sprintf( __( 'Extension: %s (optional)', '3best-core' ), $extension ),
				$loaded
					? self::badge( true, __( 'loaded', '3best-core' ) )
					: '<span style="color:#666">' . esc_html__( 'not loaded — not required', '3best-core' ) . '</span>'
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'intl is intentionally optional: slug folding uses WordPress core’s remove_accents(), which is pure PHP.', '3best-core' ) . '</p>';
	}

	/**
	 * Which integrations are configured — never what they are configured with.
	 */
	private static function render_configuration(): void {
		echo '<h2>' . esc_html__( 'Integration configuration', '3best-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Credentials belong in wp-config.php. This screen reports only whether a value is present, never the value itself.', '3best-core' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'Setting', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Constant', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'State', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Settings::constant_keys() as $key ) {
			$configured = Settings::is_configured( $key );
			$source     = Settings::is_from_constant( $key )
				? __( 'wp-config.php', '3best-core' )
				: __( 'default / option', '3best-core' );

			echo '<tr>';
			echo '<td>' . esc_html( $key ) . '</td>';
			echo '<td><code>' . esc_html( Settings::constant_name( $key ) ) . '</code></td>';
			echo '<td>';

			if ( $configured ) {
				echo self::badge( true, __( 'configured', '3best-core' ) );
				echo ' <span style="color:#666">(' . esc_html( $source ) . ')</span>';
			} else {
				echo '<span style="color:#666">' . esc_html__( 'not set — required from a later phase', '3best-core' ) . '</span>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * One label/value row.
	 *
	 * @param string $label Row label, plain text.
	 * @param string $value Row value, already escaped by the caller.
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:240px">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value is escaped at each call site.
	}

	/**
	 * Small coloured state badge. Returns escaped markup.
	 *
	 * @param bool   $good  Whether the state is good.
	 * @param string $label Badge text, plain.
	 */
	private static function badge( bool $good, string $label ): string {
		return sprintf(
			'<span style="color:%s;font-weight:600">%s</span>',
			$good ? '#1a7f37' : '#b32d2e',
			esc_html( $label )
		);
	}
}
