<?php
/**
 * Delivery queue scheduling.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

use ThreeBest\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the queue on a schedule.
 *
 * Five minutes, ten leads per run. That is a deliberate pair: the batch is
 * bounded so a run finishes well inside a normal max_execution_time even if
 * every delivery hits the 15-second HTTP timeout (10 x 15s = 150s worst case),
 * and the interval is long enough that a Zapier outage is not met with a tight
 * loop. A backlog drains at up to 120 leads an hour, which is far beyond any
 * realistic volume for this site.
 *
 * DUPLICATE EVENTS ARE THE CLASSIC WP-CRON BUG. wp_schedule_event happily
 * registers the same hook twice, and then every run processes in parallel. So
 * scheduling always checks wp_next_scheduled first, and runs once on activation
 * rather than on every request.
 *
 * A note on WP-Cron itself: it fires on page loads, so a site with no traffic
 * does not deliver. That is a hosting decision, not a code one — the production
 * checklist already carries "real cron via cPanel with DISABLE_WP_CRON". This
 * class works either way, because a real cron simply calls wp-cron.php.
 */
final class Delivery_Scheduler {

	/**
	 * The scheduled action.
	 */
	public const HOOK = 'threebest_process_delivery_queue';

	/**
	 * Our custom interval name.
	 */
	public const INTERVAL = 'threebest_five_minutes';

	private const INTERVAL_SECONDS = 300;

	/**
	 * Registers the interval, the handler and the schedule check.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_interval' ) );
		add_action( self::HOOK, array( self::class, 'run' ) );

		// Self-healing: if the event is ever lost — a failed activation, a
		// cleared cron option — it is restored on the next admin request.
		add_action( 'admin_init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Adds the five-minute interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_interval( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::INTERVAL ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Every five minutes (3Best delivery queue)', '3best-core' ),
		);

		return $schedules;
	}

	/**
	 * Schedules the event if it is not already scheduled.
	 *
	 * The wp_next_scheduled guard is what prevents duplicate events.
	 */
	public static function ensure_scheduled(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + self::INTERVAL_SECONDS, self::INTERVAL, self::HOOK );

		Logger::log( 'Delivery queue scheduled.', array( 'interval' => self::INTERVAL ) );
	}

	/**
	 * Removes the scheduled event.
	 *
	 * Called on deactivation so a disabled plugin leaves no orphaned cron entry.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The scheduled handler.
	 *
	 * Wrapped so an unexpected error cannot take down a cron run that may be
	 * carrying other plugins' events too.
	 */
	public static function run(): void {
		try {
			Delivery_Queue::process();
		} catch ( \Throwable $error ) {
			Logger::error(
				'Delivery queue run failed.',
				array(
					'type'  => get_debug_type( $error ),
					'where' => basename( $error->getFile() ) . ':' . $error->getLine(),
				)
			);
		}
	}

	/**
	 * When the next run is due, or 0 when not scheduled.
	 */
	public static function next_run(): int {
		$next = wp_next_scheduled( self::HOOK );

		return false !== $next ? (int) $next : 0;
	}

	/**
	 * How many events are registered for the hook.
	 *
	 * Should always be 1. Anything else means duplicates, which is exactly the
	 * failure this class is built to avoid, so it is surfaced in the admin.
	 */
	public static function scheduled_count(): int {
		$cron  = _get_cron_array();
		$count = 0;

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		foreach ( $cron as $events ) {
			if ( isset( $events[ self::HOOK ] ) && is_array( $events[ self::HOOK ] ) ) {
				$count += count( $events[ self::HOOK ] );
			}
		}

		return $count;
	}
}
