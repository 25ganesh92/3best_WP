<?php
/**
 * Deactivation routine.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

use ThreeBest\Core\Delivery\Delivery_Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated.
 *
 * Deliberately non-destructive. Deactivation is routinely used to diagnose a
 * conflict, so it must never cost data: no table is dropped, no option is
 * deleted, no lead is touched. Data removal is an explicit, opt-in uninstall
 * decision instead — see uninstall.php.
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		// A deactivated plugin must not leave an orphaned cron entry behind.
		Delivery_Scheduler::unschedule();

		// Clears the rewrite rules this plugin will register from Phase 4, so a
		// deactivated plugin cannot leave a route claiming URLs it no longer serves.
		flush_rewrite_rules();

		Logger::log( 'Plugin deactivated. No data was removed.' );
	}
}
