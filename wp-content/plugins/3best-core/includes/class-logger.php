<?php
/**
 * PII-safe structured logging.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only logging entry point.
 *
 * This exists because of a specific finding in the migration audit: the React
 * API route logs a complete lead — name, email, phone and street address — to
 * the console on every submission. Ported literally to PHP that becomes lead
 * PII sitting in a web-readable error log.
 *
 * So redaction is not advisory here. Any context key that names personal data
 * is replaced before the line is written, and callers cannot opt out. Secrets
 * are redacted on the same list.
 */
final class Logger {

	/**
	 * Context keys whose values are never written.
	 *
	 * Matched case-insensitively as substrings, so 'consent_user_agent' and
	 * 'billing_email' are caught without needing to be listed.
	 *
	 * @var string[]
	 */
	private const REDACTED_KEYS = array(
		'name',
		'email',
		'phone',
		'mobile',
		'address',
		'zip',
		'postcode',
		'ip',
		'user_agent',
		'consent_text',
		'code',
		'otp',
		'token',
		'secret',
		'password',
		'auth',
		'key',
		'sid',
		'webhook',
		'signature',
	);

	private const REDACTED = '[redacted]';

	/**
	 * Writes a structured line to the PHP error log.
	 *
	 * Silent unless WP_DEBUG_LOG is on, so production stays quiet by default.
	 * The threebest_core_force_logging filter allows a deliberate override for
	 * diagnosing a live incident.
	 *
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Structured context; redacted first.
	 * @param string              $level   debug|info|warning|error.
	 */
	public static function log( string $message, array $context = array(), string $level = 'info' ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$line = sprintf(
			'[3Best][%s] %s',
			strtoupper( $level ),
			$message
		);

		if ( array() !== $context ) {
			$encoded = wp_json_encode( self::redact( $context ) );
			$line   .= ' ' . ( false === $encoded ? '{"context":"unencodable"}' : $encoded );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * Convenience wrapper for error level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( $message, $context, 'error' );
	}

	/**
	 * Convenience wrapper for warning level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( $message, $context, 'warning' );
	}

	/**
	 * Whether logging should write anything at all.
	 */
	private static function is_enabled(): bool {
		$enabled = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;

		/**
		 * Filters whether 3Best logging is active.
		 *
		 * @param bool $enabled Current state.
		 */
		return (bool) apply_filters( 'threebest_core_force_logging', $enabled );
	}

	/**
	 * Recursively replaces the value of any key that names personal data.
	 *
	 * @param array<string,mixed> $context Raw context.
	 * @return array<string,mixed>
	 */
	private static function redact( array $context ): array {
		$safe = array();

		foreach ( $context as $key => $value ) {
			$key_name = is_string( $key ) ? strtolower( $key ) : (string) $key;

			if ( self::is_redacted_key( $key_name ) ) {
				$safe[ $key ] = self::REDACTED;
				continue;
			}

			if ( is_array( $value ) ) {
				$safe[ $key ] = self::redact( $value );
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$safe[ $key ] = $value;
				continue;
			}

			$safe[ $key ] = '[' . get_debug_type( $value ) . ']';
		}

		return $safe;
	}

	/**
	 * Whether a key name matches the redaction list.
	 *
	 * @param string $key_name Lowercased key.
	 */
	private static function is_redacted_key( string $key_name ): bool {
		foreach ( self::REDACTED_KEYS as $needle ) {
			if ( false !== strpos( $key_name, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
