<?php
/**
 * Transient-backed throttle counters.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The default throttle store: WordPress transients.
 *
 * Chosen for Phase 2 because it works everywhere with no new dependency. It is
 * deliberately the simplest thing that satisfies the interface, not the final
 * answer, and it has two known weaknesses that Phase 9 must address:
 *
 *  1. Not atomic. Read-then-write means two simultaneous requests can both read
 *     the same count and both write count+1, so a determined burst can exceed
 *     the limit slightly. Fine as a seam; not fine as the only defence against
 *     a paid SMS endpoint.
 *  2. Without a persistent object cache, transients live in wp_options, so a
 *     hot endpoint writes to the options table on every request.
 *
 * Both are recorded here rather than left to be rediscovered. The window is
 * fixed rather than sliding: the expiry is set once when the window opens and
 * is not extended by later hits.
 */
final class Transient_Throttle_Store implements Throttle_Store {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'tb_thr_';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key            Opaque counter key.
	 * @param int    $window_seconds Length of the window.
	 */
	public function hit( string $key, int $window_seconds ): int {
		$window_seconds = max( 1, $window_seconds );
		$name           = $this->transient_name( $key );
		$now            = time();
		$entry          = get_transient( $name );

		if ( is_array( $entry ) && isset( $entry['count'], $entry['expires'] ) && $entry['expires'] > $now ) {
			$entry['count'] = (int) $entry['count'] + 1;
			$remaining      = (int) $entry['expires'] - $now;

			// Re-saved with the ORIGINAL remaining time so the window stays
			// fixed. Passing $window_seconds here would let continuous traffic
			// push the reset forward indefinitely.
			set_transient( $name, $entry, $remaining );

			return (int) $entry['count'];
		}

		$entry = array(
			'count'   => 1,
			'expires' => $now + $window_seconds,
		);

		set_transient( $name, $entry, $window_seconds );

		return 1;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function peek( string $key ): int {
		$entry = get_transient( $this->transient_name( $key ) );

		if ( ! is_array( $entry ) || ! isset( $entry['count'], $entry['expires'] ) ) {
			return 0;
		}

		return $entry['expires'] > time() ? (int) $entry['count'] : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function seconds_remaining( string $key ): int {
		$entry = get_transient( $this->transient_name( $key ) );

		if ( ! is_array( $entry ) || ! isset( $entry['expires'] ) ) {
			return 0;
		}

		return max( 0, (int) $entry['expires'] - time() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function clear( string $key ): void {
		delete_transient( $this->transient_name( $key ) );
	}

	/**
	 * Hashes a key into a transient name.
	 *
	 * Hashing does two jobs: it keeps the name inside the 172-character option
	 * limit whatever the caller passes, and it keeps raw identifiers — an IP, a
	 * phone number — out of the options table in readable form.
	 *
	 * @param string $key Opaque counter key.
	 */
	private function transient_name( string $key ): string {
		return self::PREFIX . hash( 'sha256', $key );
	}
}
