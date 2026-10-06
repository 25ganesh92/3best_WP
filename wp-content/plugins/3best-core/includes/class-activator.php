<?php
/**
 * Activation routine.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

use ThreeBest\Core\Delivery\Delivery_Scheduler;
use ThreeBest\Core\Seo\Rewrites;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once, when the plugin is activated.
 *
 * Everything here is idempotent: activating, deactivating and reactivating
 * leaves the database in the same state and destroys nothing.
 */
final class Activator {

	/**
	 * The approved indexable combinations at launch (confirmed decision 8).
	 *
	 * These are seeded so the admin-controlled indexable set starts from the
	 * 14 combinations already approved in the React implementation, rather than
	 * from an empty table. Seeding is additive and never overwrites a row an
	 * operator has since edited.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private const SEED_COMBINATIONS = array(
		array( 'plumber', 'new-jersey' ),
		array( 'plumber', 'newark-nj' ),
		array( 'plumber', 'jersey-city-nj' ),
		array( 'hvac', 'dallas-tx' ),
		array( 'hvac', 'houston-tx' ),
		array( 'electrician', 'newark-nj' ),
		array( 'electrician', 'new-jersey' ),
		array( 'roofing', 'miami-fl' ),
		array( 'roofing', 'orlando-fl' ),
		array( 'tax-preparer', 'new-york-ny' ),
		array( 'accountant', 'chicago-il' ),
		array( 'real-estate-agent', 'austin-tx' ),
		array( 'lawyer', 'boston-ma' ),
		array( 'it-services', 'seattle-wa' ),
	);

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		if ( ! Requirements::are_met() ) {
			deactivate_plugins( THREEBEST_CORE_BASENAME );

			wp_die(
				esc_html( implode( ' ', Requirements::failures() ) ),
				esc_html__( '3Best Core cannot be activated', '3best-core' ),
				array( 'back_link' => true )
			);
		}

		Schema::install();
		self::seed_combinations();
		self::seed_settings();

		update_option( 'threebest_core_version', THREEBEST_CORE_VERSION, false );
		update_option( 'threebest_activated_at', gmdate( 'Y-m-d H:i:s' ), false );

		// Scheduled once, here, rather than on every request. The scheduler's
		// own wp_next_scheduled guard makes this safe to repeat.
		Delivery_Scheduler::ensure_scheduled();

		/*
		 * The landing-page rule must exist before the flush, or the rebuilt
		 * rule set would not contain it. Activation can run after `init` has
		 * already fired, so it is added explicitly here rather than relied upon.
		 */
		Rewrites::add_rule();
		flush_rewrite_rules();

		Logger::log(
			'Plugin activated.',
			array(
				'plugin_version' => THREEBEST_CORE_VERSION,
				'schema_version' => Schema::SCHEMA_VERSION,
			)
		);
	}

	/**
	 * Inserts the approved combinations that are not already present.
	 *
	 * Existing rows are left untouched, so an operator's later edits survive a
	 * reactivation.
	 */
	private static function seed_combinations(): void {
		global $wpdb;

		if ( ! Schema::table_exists( Schema::SERVICE_LOCATIONS ) ) {
			return;
		}

		$table = Schema::table( Schema::SERVICE_LOCATIONS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		foreach ( self::SEED_COMBINATIONS as $pair ) {
			list( $service_slug, $location_slug ) = $pair;

			$combo_slug = $service_slug . '-in-' . $location_slug;

			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE combo_slug = %s",
					$combo_slug
				)
			);

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'combo_slug'    => $combo_slug,
					'service_slug'  => $service_slug,
					'location_slug' => $location_slug,
					'is_indexable'  => 1,
					'status'        => 'active',
					'notes'         => 'Seeded from the approved launch set.',
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Writes the default settings option if it does not exist yet.
	 *
	 * Only non-secret defaults are stored. Secrets are never written to the
	 * database by this plugin.
	 */
	private static function seed_settings(): void {
		if ( false !== get_option( Settings::OPTION, false ) ) {
			return;
		}

		$defaults = Settings::defaults();

		foreach ( array_keys( $defaults ) as $key ) {
			if ( Settings::is_secret( $key ) ) {
				unset( $defaults[ $key ] );
			}
		}

		add_option( Settings::OPTION, $defaults, '', true );
	}
}
