<?php
/**
 * Storage contract for rate-limit counters.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Where throttle counters live.
 *
 * This interface is the seam. Endpoints call Throttle, Throttle calls a store,
 * and nothing above this line knows whether counters sit in transients, in a
 * custom table, in Redis, or at the edge.
 *
 * That matters because the storage answer is not yet decided. Phase 0 found no
 * persistent object cache on the local stack and the production cPanel host is
 * still unverified, so the durable choice belongs to Phase 9. Implementing
 * against this interface now means the decision changes one class later instead
 * of every endpoint.
 */
interface Throttle_Store {

	/**
	 * Records one hit against a key and returns the new count for the window.
	 *
	 * The first hit starts the window; subsequent hits inside it increment the
	 * same counter without extending its expiry, so a window is fixed rather
	 * than sliding forward under sustained traffic.
	 *
	 * @param string $key            Opaque counter key.
	 * @param int    $window_seconds Length of the window.
	 * @return int Count including this hit.
	 */
	public function hit( string $key, int $window_seconds ): int;

	/**
	 * Current count for a key without recording a hit.
	 *
	 * @param string $key Opaque counter key.
	 */
	public function peek( string $key ): int;

	/**
	 * Seconds remaining in the current window, or 0 when no window is open.
	 *
	 * @param string $key Opaque counter key.
	 */
	public function seconds_remaining( string $key ): int;

	/**
	 * Clears a counter.
	 *
	 * @param string $key Opaque counter key.
	 */
	public function clear( string $key ): void;
}
