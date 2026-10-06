<?php
/**
 * Shared input sanitisation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitisation utilities shared by every input path.
 *
 * SANITISATION IS NOT VALIDATION. This class makes a value safe to handle and
 * store; it does not decide whether the value is acceptable. The separation is
 * deliberate and later phases depend on it:
 *
 *   sanitise  ->  strip what must never be stored (control characters, tags,
 *                 formatting), cap length, normalise case where case carries no
 *                 meaning. Always returns a value of the right type.
 *   validate  ->  decide whether the sanitised value is acceptable, and produce
 *                 a field-level error when it is not. Phase 5.
 *   business  ->  decide what the accepted value means. Phase 4 onward.
 *
 * Two rules hold throughout:
 *
 *  1. Nothing here invents data. An unusable value becomes '' (or 0/false) so
 *     validation can reject it. sanitize_email('garbage') returns '' rather
 *     than something that merely looks like an address.
 *  2. Nothing here changes meaning. Formatting characters are removed; digits,
 *     words and order are not rewritten. Turning '07102-1234' into '07102', or
 *     a US number into ten digits, is normalisation that alters what the user
 *     supplied, so it belongs to validation and stays out of this class.
 */
final class Sanitizer {

	/**
	 * Single-line text.
	 *
	 * sanitize_text_field strips tags, control characters and extra whitespace.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Maximum length in characters; 0 for no cap.
	 */
	public static function text( $value, int $max = 0 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = sanitize_text_field( (string) $value );

		return $max > 0 ? self::cap( $clean, $max ) : $clean;
	}

	/**
	 * Multi-line text, newlines preserved.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Maximum length in characters; 0 for no cap.
	 */
	public static function textarea( $value, int $max = 0 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = sanitize_textarea_field( (string) $value );

		return $max > 0 ? self::cap( $clean, $max ) : $clean;
	}

	/**
	 * Email address.
	 *
	 * Returns '' when the input cannot be an address. It is never repaired into
	 * one — validation decides whether '' is acceptable.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function email( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::cap( sanitize_email( (string) $value ), 190 );
	}

	/**
	 * Telephone number, reduced to its digits.
	 *
	 * Formatting characters — spaces, brackets, dashes, dots, a leading plus —
	 * carry no information, so removing them is sanitisation. Deciding that ten
	 * digits make a valid US mobile, or stripping a leading country code, is
	 * normalisation and belongs to validation in Phase 5.
	 *
	 * Capped at 15 digits, the E.164 maximum.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function phone( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( preg_replace( '/\D+/', '', (string) $value ) ?? '', 0, 15 );
	}

	/**
	 * US postal code, in the form the user supplied it.
	 *
	 * Keeps digits and a single separating hyphen, so both '07102' and
	 * '07102-1234' survive intact. ZIP+4 is deliberately NOT shortened to five
	 * digits here: that discards information the user gave us.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function zip( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = preg_replace( '/[^0-9-]+/', '', (string) $value ) ?? '';
		$clean = preg_replace( '/-{2,}/', '-', $clean ) ?? '';

		return trim( substr( $clean, 0, 10 ), '-' );
	}

	/**
	 * URL-safe slug.
	 *
	 * sanitize_title() folds accents through WordPress core's remove_accents(),
	 * which is pure PHP. This is why ext-intl is not a runtime dependency.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Maximum length in characters.
	 */
	public static function slug( $value, int $max = 191 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::cap( sanitize_title( (string) $value ), $max );
	}

	/**
	 * Configuration identifier: a service id, subcategory id or location id.
	 *
	 * Services and the curated location catalogue are configuration, so their
	 * identifiers are stable lowercase slugs such as 'plumbing',
	 * 'plumbing-water-heater-repair' or 'newark-nj'. Anything outside
	 * [a-z0-9_-] is dropped rather than transliterated, because an identifier
	 * that needed transliterating was not one of ours.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Maximum length in characters.
	 */
	public static function identifier( $value, int $max = 96 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = strtolower( (string) $value );
		$clean = preg_replace( '/[^a-z0-9_-]+/', '', $clean ) ?? '';

		return self::cap( $clean, $max );
	}

	/**
	 * Street address as a single line.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function address( $value ): string {
		return self::text( $value, 255 );
	}

	/**
	 * Two-letter USPS state code, uppercased.
	 *
	 * Case carries no meaning in a state code, so normalising it is safe.
	 * Whether the result is a real state is a validation question answered
	 * against the state table in Phase 4.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function state_code( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = strtoupper( preg_replace( '/[^a-zA-Z]+/', '', (string) $value ) ?? '' );

		return substr( $clean, 0, 2 );
	}

	/**
	 * Free-text search query.
	 *
	 * Mirrors the sanitiser in the React location-search route: control
	 * characters become spaces, runs of whitespace collapse, and the whole
	 * thing is capped so an oversized query cannot reach a provider.
	 *
	 * @param mixed $value Raw input.
	 * @param int   $max   Maximum length in characters.
	 */
	public static function search_query( $value, int $max = 120 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$clean = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? '';
		$clean = preg_replace( '/\s+/u', ' ', $clean ) ?? '';

		return self::cap( trim( $clean ), $max );
	}

	/**
	 * Absolute URL, safe to store or request.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::cap( esc_url_raw( (string) $value ), 255 );
	}

	/**
	 * Site-relative path, such as the landing page a lead came from.
	 *
	 * Only the path, query and fragment are kept, so an absolute URL pointing
	 * at another host cannot be recorded as if it were ours.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function path( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$parts = wp_parse_url( (string) $value );

		if ( false === $parts || ! is_array( $parts ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( '' === $path ) {
			return '';
		}

		if ( isset( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}

		return self::cap( sanitize_text_field( $path ), 255 );
	}

	/**
	 * One value from a fixed set.
	 *
	 * @param mixed    $value    Raw input.
	 * @param string[] $allowed  Permitted values.
	 * @param string   $fallback Returned when the value is not permitted.
	 */
	public static function choice( $value, array $allowed, string $fallback = '' ): string {
		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		$clean = (string) $value;

		return in_array( $clean, $allowed, true ) ? $clean : $fallback;
	}

	/**
	 * Integer, optionally clamped.
	 *
	 * @param mixed    $value Raw input.
	 * @param int|null $min   Lower bound, or null.
	 * @param int|null $max   Upper bound, or null.
	 */
	public static function integer( $value, ?int $min = null, ?int $max = null ): int {
		$clean = is_scalar( $value ) ? (int) $value : 0;

		if ( null !== $min && $clean < $min ) {
			$clean = $min;
		}

		if ( null !== $max && $clean > $max ) {
			$clean = $max;
		}

		return $clean;
	}

	/**
	 * Boolean, tolerating the string forms a browser sends.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function boolean( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE ) ?? false;
	}

	/**
	 * Geographic coordinate as a bounded decimal string.
	 *
	 * Kept as a string rather than a float so it survives a round trip without
	 * a precision surprise. Out-of-range input becomes '' for validation to
	 * reject, rather than being clamped to a plausible-looking coordinate.
	 *
	 * @param mixed $value Raw input.
	 * @param float $limit Absolute bound: 90 for latitude, 180 for longitude.
	 */
	public static function coordinate( $value, float $limit ): string {
		if ( ! is_scalar( $value ) || '' === (string) $value ) {
			return '';
		}

		$clean = (string) $value;

		if ( ! is_numeric( $clean ) ) {
			return '';
		}

		$number = (float) $clean;

		if ( $number < -$limit || $number > $limit ) {
			return '';
		}

		return $clean;
	}

	/**
	 * The requesting client's IP address.
	 *
	 * ---------------------------------------------------------------------
	 * THIS VALUE IS A SECURITY CONTROL, NOT A STATISTIC
	 * ---------------------------------------------------------------------
	 * It is the identity every per-IP rate limit counts against, so whoever
	 * controls it controls their own throttling budget. A forged header worth
	 * one line of curl would hand an attacker an unlimited number of identities
	 * and switch the limiter off.
	 *
	 * So REMOTE_ADDR is the only thing trusted by default, and that default
	 * needs no configuration and cannot be spoofed.
	 *
	 * Behind a reverse proxy — Cloudflare, a load balancer, some cPanel
	 * front-ends — REMOTE_ADDR is the proxy, which makes every visitor share a
	 * single counter and lets one abuser exhaust everyone's allowance. That is
	 * a real deployment, so there is a way to handle it, and it is OPT-IN:
	 *
	 *     define( 'THREEBEST_TRUSTED_PROXIES', '192.0.2.10, 198.51.100.0/24' );
	 *
	 * A forwarded address is honoured ONLY when the connecting address is one
	 * of those, and only the right-most entry of X-Forwarded-For that is not
	 * itself a trusted proxy is used — the left-hand entries are whatever the
	 * client chose to send and are never believed. Define nothing and no header
	 * is read at all.
	 *
	 * Phase 9 settled on opt-in rather than auto-detection because getting this
	 * wrong is silent: the limiter keeps returning answers, they are simply
	 * worthless.
	 */
	public static function client_ip(): string {
		$remote = self::valid_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );

		if ( '' === $remote ) {
			return '';
		}

		$trusted = self::trusted_proxies();

		if ( array() === $trusted || ! self::ip_matches_any( $remote, $trusted ) ) {
			return $remote;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) : '';

		if ( ! is_string( $forwarded ) || '' === $forwarded ) {
			return $remote;
		}

		// Right to left: the right-most entry was added by the nearest proxy
		// and is the last one we have any reason to believe.
		$candidates = array_reverse( array_map( 'trim', explode( ',', $forwarded ) ) );

		foreach ( $candidates as $candidate ) {
			$ip = self::valid_ip( $candidate );

			if ( '' === $ip ) {
				// A malformed entry means the chain is not what we think it is.
				return $remote;
			}

			if ( ! self::ip_matches_any( $ip, $trusted ) ) {
				return $ip;
			}
		}

		return $remote;
	}

	/**
	 * The configured trusted proxy list.
	 *
	 * @return string[]
	 */
	private static function trusted_proxies(): array {
		if ( ! defined( 'THREEBEST_TRUSTED_PROXIES' ) ) {
			return array();
		}

		$raw = THREEBEST_TRUSTED_PROXIES;

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', $raw ) ) );
	}

	/**
	 * Whether an address matches any entry, each a literal IP or a CIDR range.
	 *
	 * @param string   $ip      Address to test.
	 * @param string[] $entries Trusted entries.
	 */
	private static function ip_matches_any( string $ip, array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( $ip === $entry ) {
				return true;
			}

			if ( false !== strpos( $entry, '/' ) && self::ip_in_cidr( $ip, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * CIDR membership, for IPv4 and IPv6.
	 *
	 * Compares packed binary addresses bit by bit, so it does not care which
	 * family it was handed as long as both sides agree.
	 *
	 * @param string $ip   Address to test.
	 * @param string $cidr Range in "address/bits" form.
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );

		if ( ! ctype_digit( (string) $bits ) ) {
			return false;
		}

		$packed_ip     = @inet_pton( $ip );
		$packed_subnet = @inet_pton( (string) $subnet );

		if ( false === $packed_ip || false === $packed_subnet ) {
			return false;
		}

		if ( strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
			return false;
		}

		$bits = (int) $bits;

		if ( $bits < 0 || $bits > strlen( $packed_ip ) * 8 ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$spare_bits  = $bits % 8;

		if ( $whole_bytes > 0 && 0 !== strncmp( $packed_ip, $packed_subnet, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $spare_bits ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $spare_bits ) ) - 1 ) & 0xFF;

		return ( ord( $packed_ip[ $whole_bytes ] ) & $mask ) === ( ord( $packed_subnet[ $whole_bytes ] ) & $mask );
	}

	/**
	 * Returns a valid IP address, or '' when it is not one.
	 *
	 * @param mixed $value Candidate.
	 */
	private static function valid_ip( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$valid = filter_var( $value, FILTER_VALIDATE_IP );

		return is_string( $valid ) ? $valid : '';
	}

	/**
	 * The requesting client's user agent, capped for storage.
	 */
	public static function user_agent(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return is_string( $agent ) ? self::text( $agent, 255 ) : '';
	}

	/**
	 * Multibyte-safe length cap.
	 *
	 * @param string $value Value to cap.
	 * @param int    $max   Maximum length in characters.
	 */
	private static function cap( string $value, int $max ): string {
		if ( $max <= 0 ) {
			return $value;
		}

		return function_exists( 'mb_substr' )
			? mb_substr( $value, 0, $max )
			: substr( $value, 0, $max );
	}
}
