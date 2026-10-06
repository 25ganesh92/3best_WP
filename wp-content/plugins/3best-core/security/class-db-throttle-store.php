<?php
/**
 * Atomic, table-backed throttle counters.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

use ThreeBest\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The production throttle store: one table, one atomic statement per hit.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Phase 2 shipped Transient_Throttle_Store and wrote down exactly why it could
 * not be the final answer:
 *
 *   1. hit() reads a count, adds one, writes it back. Two simultaneous requests
 *      both read 4 and both write 5, so a concurrent burst slips past a limit.
 *      In front of /otp/request that is not an abstract concern — it is extra
 *      SMS messages, billed, to a number the sender does not own.
 *   2. Without a persistent object cache, transients live in wp_options, so a
 *      hot endpoint writes to the options table on every single request.
 *
 * Both are fixed by giving counters their own table with counter_key as the
 * PRIMARY KEY, and incrementing in ONE statement:
 *
 *     INSERT ... VALUES (key, 1, expiry)
 *     ON DUPLICATE KEY UPDATE
 *         hits = LAST_INSERT_ID( IF( expires_at <= NOW, 1, hits + 1 ) ),
 *         expires_at = IF( expires_at <= NOW, VALUES(expires_at), expires_at )
 *
 * InnoDB serialises concurrent writers on the primary-key row, so the increment
 * cannot be lost. LAST_INSERT_ID( expr ) smuggles the resulting value back on
 * the same connection, so the count that is returned is the count that was
 * actually stored — not a value re-read in a second query that a concurrent
 * request could have changed in between.
 *
 * The window is FIXED, not sliding: expires_at is only rewritten when the old
 * window has already lapsed, so continuous traffic cannot push the reset
 * forward forever and turn a 5-per-hour limit into a permanent lockout.
 *
 * No readable identifier is stored. The key arrives already HMAC'd by Throttle,
 * so this table holds opaque digests — no IP address, no phone number.
 */
final class Db_Throttle_Store implements Throttle_Store {

	/**
	 * How often expired rows are swept, in seconds.
	 */
	private const GC_INTERVAL = 3600;

	private const GC_OPTION = 'threebest_throttle_gc';

	/**
	 * Whether this store can be used on this installation.
	 */
	public static function is_available(): bool {
		return Schema::table_exists( Schema::THROTTLE );
	}

	/**
	 * Reduces a caller's key to the fixed width the column expects.
	 *
	 * Throttle hands over "bucket:<64 hex>", which is longer than char(64). The
	 * first version of this class stored that directly, and MySQL — not in
	 * strict mode — silently truncated it. The INSERT then matched the
	 * truncated row and kept counting correctly, while every SELECT compared
	 * the full-length literal against the truncated value and matched nothing:
	 * peek() and seconds_remaining() quietly returned zero, so every rate-limit
	 * refusal told the caller to retry after 0 seconds.
	 *
	 * Hashing here makes the width exact by construction, the way the transient
	 * store already does, instead of depending on the caller never exceeding it.
	 *
	 * @param string $key Caller's opaque key.
	 */
	private function column_key( string $key ): string {
		return hash( 'sha256', $key );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key            Opaque counter key.
	 * @param int    $window_seconds Length of the window.
	 */
	public function hit( string $key, int $window_seconds ): int {
		global $wpdb;

		$window_seconds = max( 1, $window_seconds );
		$key            = $this->column_key( $key );
		$table          = Schema::table( Schema::THROTTLE );
		$now            = gmdate( 'Y-m-d H:i:s' );
		$expires        = gmdate( 'Y-m-d H:i:s', time() + $window_seconds );

		/*
		 * $wpdb->query() returns affected rows, not our value, so the count is
		 * read back with LAST_INSERT_ID() — per-connection state, unaffected by
		 * anything another request does.
		 */
		$sql = $wpdb->prepare(
			"INSERT INTO `{$table}` (counter_key, hits, expires_at)
			 VALUES (%s, 1, %s)
			 ON DUPLICATE KEY UPDATE
			     hits = LAST_INSERT_ID( IF( expires_at <= %s, 1, hits + 1 ) ),
			     expires_at = IF( expires_at <= %s, VALUES(expires_at), expires_at )",
			$key,
			$expires,
			$now,
			$now
		);

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( false === $result ) {
			// Surfaced to Throttle, which decides whether this bucket fails
			// open or closed. Swallowing it here would silently disable the
			// limit, which is the one outcome that must never happen quietly.
			throw new Throttle_Unavailable( 'Throttle counter could not be written.' );
		}

		/*
		 * A fresh INSERT does not set LAST_INSERT_ID() for a table with no
		 * AUTO_INCREMENT column, so affected-rows tells us which branch ran:
		 * 1 means the row was inserted, and the count is 1.
		 */
		if ( 1 === (int) $result ) {
			$this->maybe_collect_garbage();

			return 1;
		}

		$count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		$this->maybe_collect_garbage();

		return max( 1, $count );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function peek( string $key ): int {
		global $wpdb;

		$key   = $this->column_key( $key );
		$table = Schema::table( Schema::THROTTLE );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM `{$table}` WHERE counter_key = %s AND expires_at > %s",
				$key,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return null === $count ? 0 : (int) $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function seconds_remaining( string $key ): int {
		global $wpdb;

		$key   = $this->column_key( $key );
		$table = Schema::table( Schema::THROTTLE );

		$expires = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT expires_at FROM `{$table}` WHERE counter_key = %s",
				$key
			)
		);

		if ( null === $expires ) {
			return 0;
		}

		return max( 0, (int) strtotime( (string) $expires . ' UTC' ) - time() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Opaque counter key.
	 */
	public function clear( string $key ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::THROTTLE ), array( 'counter_key' => $this->column_key( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes lapsed rows, at most once an hour.
	 *
	 * Opportunistic rather than scheduled: the table is only written by request
	 * traffic, so the sweep can ride along with it and the plugin does not need
	 * another cron event. The timestamp is kept in an option with autoload off.
	 */
	private function maybe_collect_garbage(): void {
		global $wpdb;

		$last = (int) get_option( self::GC_OPTION, 0 );

		if ( time() - $last < self::GC_INTERVAL ) {
			return;
		}

		update_option( self::GC_OPTION, time(), false );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM `' . Schema::table( Schema::THROTTLE ) . '` WHERE expires_at <= %s',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
