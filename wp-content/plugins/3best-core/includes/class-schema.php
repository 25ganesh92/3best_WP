<?php
/**
 * Custom table definitions and versioned migration.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every custom table the plugin creates, and the migration that keeps
 * them current.
 *
 * Only three tables exist, and each earns its place:
 *
 *  - leads             ~35 typed columns, PII, indexed queries, unbounded growth.
 *  - otp_verifications high-churn security state needing TTL, attempt caps and
 *                      throttle counters. Phase 0 confirmed no persistent object
 *                      cache, so transients are not a safe home for these.
 *  - service_locations sparse overrides plus the admin-controlled indexable set.
 *                      A row exists only where a decision has been recorded,
 *                      never one per renderable combination.
 *
 * Services and the curated location catalogue are NOT tables: their slugs are
 * the SEO surface and are curated rather than derived, so they live in plugin
 * configuration where they cannot be edited into breaking published URLs.
 *
 * Migration contract:
 *  - repeatable   dbDelta is idempotent; running install() twice is a no-op.
 *  - versioned    SCHEMA_VERSION is compared against a stored option.
 *  - safe         additive only; no destructive statement is ever issued here.
 *  - automatic    upgrades run on admin_init, so a plugin update that changes
 *                 the schema does not require a manual reactivation.
 */
final class Schema {

	/**
	 * Bump when a table definition changes. Semantic: MAJOR.MINOR.PATCH.
	 *
	 * History:
	 *   1.0.0  initial schema.
	 *   1.1.0  consent_version widened varchar(20) -> varchar(32). The agreed
	 *          value "phone-verification-v1" is 21 characters, so the original
	 *          column silently rejected every lead. Widened rather than
	 *          shortening the identifier, because the version string is quoted
	 *          in stored consent records and must stay readable.
	 *   1.2.0  added the throttle counter table. Phase 2 shipped rate limiting
	 *          on transients and recorded two known weaknesses: the read-then-
	 *          write is not atomic, so a concurrent burst can exceed a limit,
	 *          and without a persistent object cache every counter write hits
	 *          wp_options. Neither is acceptable in front of an endpoint that
	 *          spends money on SMS, so Phase 9 gives counters their own table
	 *          with a single-statement atomic increment.
	 */
	public const SCHEMA_VERSION = '1.2.0';

	public const VERSION_OPTION = 'threebest_schema_version';

	/**
	 * Logical table names, without prefix.
	 */
	public const LEADS             = 'leads';
	public const OTP_VERIFICATIONS = 'otp_verifications';
	public const SERVICE_LOCATIONS = 'service_locations';
	public const THROTTLE          = 'throttle';

	/**
	 * Fully qualified table name for a logical name.
	 *
	 * Always resolved through $wpdb->prefix, so a host using a non-default
	 * table prefix works untouched. The prefix is never hard-coded.
	 *
	 * @param string $name One of the class constants above.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'threebest_' . $name;
	}

	/**
	 * Every logical table this plugin owns.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array( self::LEADS, self::OTP_VERIFICATIONS, self::SERVICE_LOCATIONS, self::THROTTLE );
	}

	/**
	 * Schema version currently installed, or '' when never installed.
	 */
	public static function installed_version(): string {
		return (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Whether the stored version is behind the code's version.
	 */
	public static function needs_upgrade(): bool {
		return version_compare( self::installed_version(), self::SCHEMA_VERSION, '<' );
	}

	/**
	 * Runs the migration only when the stored version is behind.
	 *
	 * Hooked on admin_init so an upgraded plugin migrates itself without a
	 * manual deactivate/reactivate cycle, and without costing a front-end
	 * request anything.
	 */
	public static function maybe_upgrade(): void {
		if ( ! self::needs_upgrade() ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates or updates every table, then records the version.
	 *
	 * dbDelta issues only CREATE TABLE and additive ALTER TABLE statements; it
	 * never drops a column or a table.
	 *
	 * @return array<string,array<string>> dbDelta's per-table report.
	 */
	public static function install(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$report = array();

		foreach ( self::definitions() as $name => $sql ) {
			$report[ $name ] = dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );

		Logger::log(
			'Schema migration completed.',
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'tables'         => array_keys( $report ),
			)
		);

		return $report;
	}

	/**
	 * Whether a table physically exists.
	 *
	 * @param string $name Logical table name.
	 */
	public static function table_exists( string $name ): bool {
		global $wpdb;

		$table = self::table( $name );

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $found === $table;
	}

	/**
	 * Read-only status for the admin screen and for automated verification.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function status(): array {
		global $wpdb;

		$status = array();

		foreach ( self::table_names() as $name ) {
			$table  = self::table( $name );
			$exists = self::table_exists( $name );

			// Identifiers here are built from class constants and $wpdb->prefix,
			// never from request input; no user value reaches these strings.
			$status[ $name ] = array(
				'table'   => $table,
				'exists'  => $exists,
				'rows'    => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) : 0,
				'columns' => $exists ? count( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) ) : 0,
				'indexes' => $exists ? count( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`" ) ) : 0,
			);
		}

		return $status;
	}

	/**
	 * CREATE TABLE statements, keyed by logical table name.
	 *
	 * Formatting follows dbDelta's parser strictly: one field per line, two
	 * spaces after PRIMARY KEY, KEY rather than INDEX, and every key named.
	 *
	 * Design notes that are easy to lose later:
	 *
	 *  - datetime columns are NOT NULL with no default rather than the legacy
	 *    zero date. The server's sql_mode includes NO_ZERO_DATE, and every
	 *    write sets these explicitly in UTC.
	 *  - Indexed varchars are capped at 191 characters so the schema stays
	 *    portable to hosts still limited to a 767-byte index prefix.
	 *  - service_id and location_id are varchar, not bigint: services and the
	 *    curated location catalogue are configuration, so their identifiers are
	 *    stable slugs such as 'plumbing' and 'newark-nj' rather than
	 *    auto-increment keys.
	 *  - No FOREIGN KEY constraints. The referenced entities are config-backed
	 *    rather than tables, dbDelta cannot manage constraints, and WordPress
	 *    core does not use them. Referential integrity is enforced in the
	 *    repository layer from Phase 4 onward.
	 *
	 * @return array<string,string>
	 */
	private static function definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$leads = self::table( self::LEADS );
		$otp   = self::table( self::OTP_VERIFICATIONS );
		$combo = self::table( self::SERVICE_LOCATIONS );
		$throt = self::table( self::THROTTLE );

		$definitions = array();

		/*
		 * Leads.
		 *
		 * The column set mirrors the React LeadPayload, plus the consent fields
		 * required by confirmed decision 4 and the delivery state required by
		 * confirmed decision 6 (persist first, deliver second).
		 *
		 * Consent is stored as four facts rather than one boolean: a bare flag
		 * is not defensible. The wording shown and the moment it was shown are
		 * what make the record meaningful.
		 */
		$definitions[ self::LEADS ] = "CREATE TABLE {$leads} (
	id bigint(20) unsigned NOT NULL auto_increment,
	lead_uid varchar(32) NOT NULL,
	status varchar(20) NOT NULL default 'new',
	service_id varchar(64) NOT NULL default '',
	service_slug varchar(64) NOT NULL default '',
	subcategory_id varchar(96) NOT NULL default '',
	location_id varchar(96) NOT NULL default '',
	location_slug varchar(96) NOT NULL default '',
	location_type varchar(20) NOT NULL default '',
	source_type varchar(20) NOT NULL default '',
	city varchar(96) NOT NULL default '',
	state_code char(2) NOT NULL default '',
	zip varchar(10) NOT NULL default '',
	latitude decimal(10,7) default NULL,
	longitude decimal(10,7) default NULL,
	full_name varchar(150) NOT NULL default '',
	email varchar(190) NOT NULL default '',
	phone varchar(20) NOT NULL default '',
	address varchar(255) NOT NULL default '',
	phone_verified tinyint(1) unsigned NOT NULL default 0,
	phone_verified_at datetime default NULL,
	consent tinyint(1) unsigned NOT NULL default 0,
	consent_version varchar(32) NOT NULL default '',
	consent_text text,
	consent_at datetime default NULL,
	consent_ip varchar(45) NOT NULL default '',
	consent_user_agent varchar(255) NOT NULL default '',
	source_page varchar(255) NOT NULL default '',
	referrer varchar(255) NOT NULL default '',
	utm_source varchar(100) NOT NULL default '',
	utm_medium varchar(100) NOT NULL default '',
	utm_campaign varchar(100) NOT NULL default '',
	utm_term varchar(100) NOT NULL default '',
	utm_content varchar(100) NOT NULL default '',
	delivery_status varchar(20) NOT NULL default 'pending',
	delivery_attempts smallint(5) unsigned NOT NULL default 0,
	delivery_last_attempt_at datetime default NULL,
	delivery_completed_at datetime default NULL,
	delivery_last_error varchar(255) NOT NULL default '',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY lead_uid (lead_uid),
	KEY created_at (created_at),
	KEY status_created (status,created_at),
	KEY delivery_queue (delivery_status,delivery_attempts,created_at),
	KEY email (email),
	KEY phone (phone),
	KEY location_created (location_slug,created_at),
	KEY service_created (service_slug,created_at)
) {$charset_collate};";

		/*
		 * Phone verification.
		 *
		 * The plaintext code is never stored: code_hash holds an HMAC. The
		 * verification token issued on success is likewise stored hashed and
		 * single-use, and POST /leads will require it from Phase 5.
		 *
		 * phone_created exists specifically so the per-number send cap from
		 * confirmed decision 5 (5 sends per number per day) is an index scan
		 * rather than a table scan.
		 */
		$definitions[ self::OTP_VERIFICATIONS ] = "CREATE TABLE {$otp} (
	id bigint(20) unsigned NOT NULL auto_increment,
	otp_uid varchar(32) NOT NULL,
	phone varchar(20) NOT NULL default '',
	code_hash char(64) NOT NULL default '',
	status varchar(20) NOT NULL default 'pending',
	attempts tinyint(3) unsigned NOT NULL default 0,
	max_attempts tinyint(3) unsigned NOT NULL default 5,
	send_count smallint(5) unsigned NOT NULL default 1,
	consent tinyint(1) unsigned NOT NULL default 0,
	provider varchar(32) NOT NULL default '',
	provider_ref varchar(64) NOT NULL default '',
	verify_token_hash char(64) NOT NULL default '',
	token_expires_at datetime default NULL,
	token_consumed_at datetime default NULL,
	ip varchar(45) NOT NULL default '',
	user_agent varchar(255) NOT NULL default '',
	expires_at datetime NOT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY otp_uid (otp_uid),
	KEY phone_created (phone,created_at),
	KEY expires_at (expires_at),
	KEY status_created (status,created_at),
	KEY verify_token_hash (verify_token_hash)
) {$charset_collate};";

		/*
		 * Service/location combinations.
		 *
		 * Sparse: one row per approved or overridden combination, never one per
		 * renderable combination. Confirmed decision 8 makes the indexable set
		 * admin-controlled, which is what requires persistence at all.
		 *
		 * combo_slug is the natural key and matches the URL exactly, so the
		 * UNIQUE constraint is what prevents two rows ever claiming one URL.
		 */
		$definitions[ self::SERVICE_LOCATIONS ] = "CREATE TABLE {$combo} (
	id bigint(20) unsigned NOT NULL auto_increment,
	combo_slug varchar(191) NOT NULL,
	service_slug varchar(64) NOT NULL default '',
	location_slug varchar(96) NOT NULL default '',
	is_indexable tinyint(1) unsigned NOT NULL default 0,
	status varchar(20) NOT NULL default 'active',
	seo_title varchar(255) NOT NULL default '',
	seo_description varchar(320) NOT NULL default '',
	content_override longtext,
	sitemap_priority decimal(2,1) default NULL,
	notes varchar(255) NOT NULL default '',
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY combo_slug (combo_slug),
	KEY indexable_status (is_indexable,status),
	KEY service_location (service_slug,location_slug)
) {$charset_collate};";

		/*
		 * Throttle counters.
		 *
		 * counter_key is the PRIMARY KEY, which is what makes the increment
		 * atomic: one INSERT ... ON DUPLICATE KEY UPDATE statement, serialised
		 * by the row lock, instead of the read-then-write the transient store
		 * has to do. See Db_Throttle_Store.
		 *
		 * The key is already an HMAC of bucket plus identity, so this table
		 * holds no IP address, phone number or anything else readable — only
		 * opaque 64-character digests.
		 *
		 * char(64) rather than varchar: every key is exactly a sha-256 hex
		 * digest, and a fixed-width PRIMARY KEY keeps the index compact.
		 */
		$definitions[ self::THROTTLE ] = "CREATE TABLE {$throt} (
	counter_key char(64) NOT NULL,
	hits int(10) unsigned NOT NULL default 0,
	expires_at datetime NOT NULL,
	PRIMARY KEY  (counter_key),
	KEY expires_at (expires_at)
) {$charset_collate};";

		return $definitions;
	}
}
