<?php
/**
 * Lead delivery queue.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

use ThreeBest\Core\Leads\Lead_Repository;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Moves stored leads to the delivery provider, and records what happened.
 *
 * PERSIST FIRST, DELIVER SECOND. By the time this class runs, the lead is
 * already durable. Nothing here can lose one: every path — provider missing,
 * timeout, 500, malformed response, PHP exception — ends with the row still
 * present and its state explicable. The worst outcome is a lead that has not
 * been delivered *yet*.
 *
 * ---------------------------------------------------------------------------
 * STATE MACHINE  (uses only the existing delivery_status values)
 * ---------------------------------------------------------------------------
 *
 *            ┌──────────────────── retryable, attempts < max ───────────┐
 *            ▼                                                          │
 *   pending ──claim──▶ processing ──2xx──▶ delivered                    │
 *                          │                                            │
 *                          ├── retryable ───────────────────────────────┘
 *                          │   (back to pending, backoff from attempts)
 *                          │
 *                          └── permanent, or attempts exhausted ──▶ failed
 *
 * `failed` means "stop trying", never "discard". A failed lead keeps every
 * field and can be requeued by an operator once the cause is fixed.
 *
 * ---------------------------------------------------------------------------
 * NO SCHEMA CHANGE
 * ---------------------------------------------------------------------------
 * There is no next_attempt_at column, and one was not added. The next eligible
 * moment is derived from delivery_last_attempt_at plus a backoff chosen by
 * delivery_attempts, evaluated in the WHERE clause. The existing
 * (delivery_status, delivery_attempts, created_at) index covers the selection.
 *
 * ---------------------------------------------------------------------------
 * DELIVERY IS AT-LEAST-ONCE, NOT EXACTLY-ONCE
 * ---------------------------------------------------------------------------
 * If a request times out, the lead may or may not have arrived — HTTP cannot
 * tell us. Retrying risks a duplicate; not retrying risks losing the lead for
 * good. We retry, because a duplicate is a nuisance and a lost lead is revenue.
 * Every payload carries the same stable leadId precisely so the receiving Zap
 * can deduplicate. This is a genuine limitation and is not claimed otherwise.
 */
final class Delivery_Queue {

	/**
	 * Backoff before the next attempt, in seconds, indexed by attempts already made.
	 *
	 * 1 min, 5 min, 15 min, 1 hr, then 3 hr for anything beyond. Deliberately
	 * conservative: a Zapier outage should not be met with a tight loop.
	 *
	 * @var array<int,int>
	 */
	private const BACKOFF = array(
		1 => 60,
		2 => 300,
		3 => 900,
		4 => 3600,
	);

	private const BACKOFF_MAX = 10800;

	/**
	 * How long a row may sit in `processing` before it is assumed abandoned.
	 *
	 * A PHP fatal, a killed cron or a host restart can leave a row claimed and
	 * never finished. Without this it would be stuck forever — invisible, and
	 * never delivered.
	 */
	private const STALE_PROCESSING_SECONDS = 900;

	/**
	 * Default leads per run.
	 */
	private const DEFAULT_BATCH = 10;

	private static ?Delivery_Provider $provider = null;

	/**
	 * Processes one bounded batch.
	 *
	 * @param int|null $batch_size Override for the configured size.
	 * @return array<string,int> Counts by outcome.
	 */
	public static function process( ?int $batch_size = null ): array {
		$summary = array(
			'claimed'   => 0,
			'delivered' => 0,
			'retrying'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		// An operator must turn delivery on deliberately. Until then leads
		// accumulate as pending, which is the safe state.
		if ( ! self::is_enabled() ) {
			$summary['skipped'] = 1;

			return $summary;
		}

		self::reclaim_stale();

		$batch = $batch_size ?? self::batch_size();
		$ids   = Lead_Repository::find_deliverable( $batch, self::max_attempts(), self::backoff_sql_case() );

		if ( array() === $ids ) {
			return $summary;
		}

		$provider = self::provider();

		foreach ( $ids as $id ) {
			// Atomic claim. Two workers racing on the same row: exactly one
			// sees an affected row of 1, the other moves on.
			if ( ! Lead_Repository::claim_for_delivery( $id ) ) {
				continue;
			}

			++$summary['claimed'];

			$lead = Lead_Repository::find( $id );

			if ( null === $lead ) {
				// Claimed a row that has since vanished. Nothing to deliver and
				// nothing to update.
				continue;
			}

			$outcome = self::deliver_one( $lead, $provider );
			++$summary[ $outcome ];
		}

		Logger::log( 'Delivery queue run complete.', $summary );

		return $summary;
	}

	/**
	 * Delivers one claimed lead and records the result.
	 *
	 * @param array<string,mixed> $lead     Claimed row.
	 * @param Delivery_Provider   $provider Active provider.
	 * @return string 'delivered', 'retrying' or 'failed'.
	 */
	private static function deliver_one( array $lead, Delivery_Provider $provider ): string {
		$id       = (int) $lead['id'];
		$lead_uid = (string) $lead['lead_uid'];
		$attempts = (int) $lead['delivery_attempts'];

		try {
			$result = $provider->send( Delivery_Payload::build( $lead ) );
		} catch ( \Throwable $error ) {
			/*
			 * A provider must not throw, but if one ever does, the lead goes
			 * back to pending rather than being abandoned mid-flight. An
			 * unhandled exception here would otherwise strand the row in
			 * `processing` until the stale sweep found it.
			 */
			Logger::error(
				'Delivery provider threw.',
				array(
					'lead_uid' => $lead_uid,
					'attempt'  => $attempts,
					'type'     => get_debug_type( $error ),
					'where'    => basename( $error->getFile() ) . ':' . $error->getLine(),
				)
			);

			Lead_Repository::mark_retrying( $id, 'provider_exception' );

			return 'retrying';
		}

		if ( $result->is_delivered() ) {
			Lead_Repository::mark_delivered( $id );

			Logger::log(
				'Lead delivered.',
				array(
					'lead_uid' => $lead_uid,
					'attempt'  => $attempts,
					'status'   => $result->http_status,
				)
			);

			return 'delivered';
		}

		$exhausted = $attempts >= self::max_attempts();
		$retrying  = $result->should_retry() && ! $exhausted;

		Logger::warning(
			$retrying ? 'Lead delivery failed; will retry.' : 'Lead delivery failed; giving up.',
			array_merge(
				$result->log_context,
				array(
					'lead_uid'  => $lead_uid,
					'attempt'   => $attempts,
					'max'       => self::max_attempts(),
					'outcome'   => $result->outcome,
					'reason'    => $result->reason,
					'exhausted' => $exhausted,
				)
			)
		);

		if ( $retrying ) {
			Lead_Repository::mark_retrying( $id, $result->reason );

			return 'retrying';
		}

		Lead_Repository::mark_failed(
			$id,
			$exhausted && $result->should_retry()
				? 'attempts_exhausted: ' . $result->reason
				: $result->reason
		);

		return 'failed';
	}

	/**
	 * Returns rows abandoned mid-flight to the pending pool.
	 *
	 * Their attempt was already counted at claim time, so a crash cannot buy an
	 * extra try.
	 */
	private static function reclaim_stale(): int {
		$reclaimed = Lead_Repository::reclaim_stale_processing( self::STALE_PROCESSING_SECONDS );

		if ( $reclaimed > 0 ) {
			Logger::warning( 'Reclaimed stale processing leads.', array( 'count' => $reclaimed ) );
		}

		return $reclaimed;
	}

	/**
	 * Requeues a lead an operator wants retried.
	 *
	 * Resets the attempt counter, because an operator requeues after fixing the
	 * cause — a corrected webhook URL, a repaired Zap — and the old attempts
	 * say nothing about the new configuration.
	 *
	 * @param int $id Lead id.
	 */
	public static function requeue( int $id ): bool {
		return Lead_Repository::requeue( $id );
	}

	/**
	 * A SQL CASE expression giving the backoff in seconds for a row.
	 *
	 * Built entirely from integer constants in this class; no request value
	 * reaches it.
	 */
	private static function backoff_sql_case(): string {
		$cases = '';

		foreach ( self::BACKOFF as $attempts => $seconds ) {
			$cases .= sprintf( ' WHEN %d THEN %d', (int) $attempts, (int) $seconds );
		}

		return 'CASE delivery_attempts' . $cases . ' ELSE ' . (int) self::BACKOFF_MAX . ' END';
	}

	/**
	 * Whether delivery is switched on.
	 */
	public static function is_enabled(): bool {
		return (bool) Settings::get( 'delivery_enabled', false );
	}

	/**
	 * Maximum attempts before a lead is marked failed.
	 */
	public static function max_attempts(): int {
		return max( 1, (int) Settings::get( 'delivery_max_attempts', 5 ) );
	}

	/**
	 * Leads processed per run.
	 */
	public static function batch_size(): int {
		$size = (int) Settings::get( 'delivery_batch_size', self::DEFAULT_BATCH );

		// Bounded at both ends: a cron run must finish well inside a normal
		// max_execution_time, even if every delivery hits the HTTP timeout.
		return max( 1, min( 50, $size ) );
	}

	/**
	 * The active provider.
	 */
	public static function provider(): Delivery_Provider {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		/**
		 * Filters the delivery provider.
		 *
		 * @param Delivery_Provider|null $provider Null to use the default.
		 */
		$filtered = apply_filters( 'threebest_core_delivery_provider', null );

		self::$provider = $filtered instanceof Delivery_Provider ? $filtered : new Zapier_Provider();

		return self::$provider;
	}

	/**
	 * Replaces the provider. Test seam.
	 *
	 * @param Delivery_Provider|null $provider Provider, or null to reset.
	 */
	public static function set_provider( ?Delivery_Provider $provider ): void {
		self::$provider = $provider;
	}
}
