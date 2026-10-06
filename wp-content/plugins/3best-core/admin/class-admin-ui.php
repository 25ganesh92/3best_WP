<?php
/**
 * Shared rendering helpers for the admin screens.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small presentation helpers the Phase 8 screens share.
 *
 * Presentation only — no queries, no writes, no decisions. It exists so three
 * screens do not each carry their own copy of "render a definition row" and
 * "render a green or red badge".
 *
 * EVERY METHOD HERE ESCAPES ITS OWN OUTPUT. A caller passing a raw database
 * value, a settings value or a lead field must get safe markup back without
 * having to remember to escape first, because the one place that forgets is the
 * one that matters. Where a caller legitimately needs to pass markup it has
 * already built and escaped — a badge inside a row — that is `row_html()`, and
 * the name says so.
 */
final class Admin_Ui {

	/**
	 * A label/value row, with the value escaped.
	 *
	 * @param string $label Row label.
	 * @param string $value Raw value; escaped here.
	 */
	public static function row( string $label, string $value ): void {
		self::row_html( $label, esc_html( $value ) );
	}

	/**
	 * A label/value row whose value is already-escaped markup.
	 *
	 * @param string $label Row label.
	 * @param string $html  Pre-escaped markup, e.g. from badge().
	 */
	public static function row_html( string $label, string $html ): void {
		echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is pre-escaped by contract.
	}

	/**
	 * A coloured state badge.
	 *
	 * @param bool   $good Whether the state is healthy.
	 * @param string $text Raw label; escaped here.
	 */
	public static function badge( bool $good, string $text ): string {
		return sprintf(
			'<strong style="color:%s">%s</strong>',
			$good ? '#1a7f37' : '#b32d2e',
			esc_html( $text )
		);
	}

	/**
	 * A dismissible admin notice.
	 *
	 * @param string $type    One of success, error, warning, info.
	 * @param string $message Raw message; escaped here.
	 */
	public static function notice( string $type, string $message ): void {
		$allowed = array( 'success', 'error', 'warning', 'info' );
		$type    = in_array( $type, $allowed, true ) ? $type : 'info';

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * The admin URL for one of our screens.
	 *
	 * @param string              $slug Menu slug.
	 * @param array<string,mixed> $args Extra query arguments.
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => $slug ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A form that posts to admin-post.php with a nonce already in place.
	 *
	 * Opening tag only; the caller adds fields and closes the form. Every write
	 * in Phase 8 goes through admin-post.php rather than handling its own POST
	 * on a render hook, so the capability check and the nonce live in one place
	 * and a refresh cannot replay the action.
	 *
	 * @param string $action Action name, used for both the hook and the nonce.
	 * @param string $nonce  Nonce action; defaults to $action.
	 */
	public static function open_form( string $action, string $nonce = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( '' !== $nonce ? $nonce : $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	}

	/**
	 * Renders a <select> from a value => label map.
	 *
	 * @param string                $name     Field name.
	 * @param array<string,string>  $options  Value => label.
	 * @param string                $selected Currently selected value.
	 * @param string                $blank    Label for an empty first option, or '' to omit it.
	 */
	public static function select( string $name, array $options, string $selected = '', string $blank = '' ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';

		if ( '' !== $blank ) {
			echo '<option value="">' . esc_html( $blank ) . '</option>';
		}

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( (string) $value, $selected, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Formats a stored UTC datetime in the site's timezone.
	 *
	 * Rows are written with gmdate(), so they must be read back as UTC. Passing
	 * a stored string straight to wp_date() would silently shift it.
	 *
	 * @param string|null $gmt_datetime Stored "Y-m-d H:i:s" in UTC, or null.
	 */
	public static function local_time( ?string $gmt_datetime ): string {
		if ( null === $gmt_datetime || '' === $gmt_datetime || '0000-00-00 00:00:00' === $gmt_datetime ) {
			return '—';
		}

		$timestamp = strtotime( $gmt_datetime . ' UTC' );

		return false === $timestamp ? '—' : wp_date( 'Y-m-d H:i', $timestamp );
	}
}
