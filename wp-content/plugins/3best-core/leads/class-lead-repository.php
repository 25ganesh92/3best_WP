<?php
/**
 * Lead persistence.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Leads;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes verified leads to wp_threebest_leads.
 *
 * Confirmed decision 6 is persist first, deliver second, and this is the
 * "first" half. A stored lead is the durable record; Zapier delivery in Phase 6
 * reads delivery_status from here and can fail, retry or be replayed without
 * the lead ever being at risk.
 *
 * Rows land with delivery_status = 'pending' and are not dispatched by anything
 * in this phase. Nothing here calls a webhook.
 */
final class Lead_Repository {

	/**
	 * Inserts a verified lead.
	 *
	 * @param array<string,mixed> $lead     Validated lead fields.
	 * @param array<string,mixed> $service  Canonical service record.
	 * @param array<string,mixed> $subcat   Canonical subcategory record.
	 * @param Location            $location Canonical location.
	 * @param array<string,mixed> $context  source_page, referrer, utm_*.
	 * @return array{id:int,lead_uid:string}
	 *
	 * @throws Api_Exception When the write fails.
	 */
	public static function insert(
		array $lead,
		array $service,
		array $subcat,
		Location $location,
		array $context
	): array {
		global $wpdb;

		$now      = gmdate( 'Y-m-d H:i:s' );
		$lead_uid = self::generate_uid();
		$consent  = Consent::record();

		$row = array(
			'lead_uid'                 => $lead_uid,
			'status'                   => 'new',

			'service_id'               => (string) $service['id'],
			'service_slug'             => (string) $service['slug'],
			'subcategory_id'           => (string) $subcat['id'],

			'location_id'              => $location->id,
			'location_slug'            => $location->slug,
			'location_type'            => $location->location_type,
			'source_type'              => $location->source_type,
			'city'                     => $location->city,
			'state_code'               => $location->state_code,
			// The ZIP the user actually searched with, kept for lead routing —
			// never part of the URL.
			'zip'                      => $location->zip,
			'latitude'                 => '' !== $location->latitude ? $location->latitude : null,
			'longitude'                => '' !== $location->longitude ? $location->longitude : null,

			'full_name'                => (string) $lead['full_name'],
			'email'                    => (string) $lead['email'],
			// Normalised 10 digits, as validated.
			'phone'                    => (string) $lead['phone'],
			'address'                  => (string) $lead['address'],

			// Only ever written after Otp_Service consumed a real token.
			'phone_verified'           => 1,
			'phone_verified_at'        => $now,

			'consent'                  => $consent['consent'],
			'consent_version'          => $consent['consent_version'],
			'consent_text'             => $consent['consent_text'],
			'consent_at'               => $consent['consent_at'],
			'consent_ip'               => $consent['consent_ip'],
			'consent_user_agent'       => $consent['consent_user_agent'],

			'source_page'              => (string) ( $context['source_page'] ?? '' ),
			'referrer'                 => (string) ( $context['referrer'] ?? '' ),
			'utm_source'               => (string) ( $context['utm_source'] ?? '' ),
			'utm_medium'               => (string) ( $context['utm_medium'] ?? '' ),
			'utm_campaign'             => (string) ( $context['utm_campaign'] ?? '' ),
			'utm_term'                 => (string) ( $context['utm_term'] ?? '' ),
			'utm_content'              => (string) ( $context['utm_content'] ?? '' ),

			// Phase 6 picks these up. Nothing in Phase 5 dispatches.
			'delivery_status'          => 'pending',
			'delivery_attempts'        => 0,

			'created_at'               => $now,
			'updated_at'               => $now,
		);

		$written = $wpdb->insert( Schema::table( Schema::LEADS ), $row );

		if ( false === $written ) {
			/*
			 * $wpdb->last_error can name the table, the columns and the database
			 * user. It goes to the log; the caller gets a fixed sentence.
			 */
			Logger::error(
				'Lead insert failed.',
				array(
					'lead_uid' => $lead_uid,
					'db_error' => $wpdb->last_error,
				)
			);

			throw Api_Exception::internal( array( 'stage' => 'lead_insert' ) );
		}

		Logger::log(
			'Lead stored.',
			array(
				'lead_uid'      => $lead_uid,
				'service_slug'  => $row['service_slug'],
				'location_slug' => $row['location_slug'],
			)
		);

		return array(
			'id'       => (int) $wpdb->insert_id,
			'lead_uid' => $lead_uid,
		);
	}

	/**
	 * One lead by id.
	 *
	 * @param int $id Lead id.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Ids of leads eligible for a delivery attempt right now.
	 *
	 * Eligible means: pending, under the attempt cap, and either never tried or
	 * past its backoff window. The backoff is expressed as a SQL CASE built
	 * from integer constants in Delivery_Queue, which is how the policy is
	 * applied without a next_attempt_at column.
	 *
	 * Ordered oldest first so a backlog drains fairly, and covered by the
	 * existing (delivery_status, delivery_attempts, created_at) index.
	 *
	 * @param int    $limit        Batch size.
	 * @param int    $max_attempts Attempt cap.
	 * @param string $backoff_case SQL CASE expression yielding seconds.
	 * @return int[]
	 */
	public static function find_deliverable( int $limit, int $max_attempts, string $backoff_case ): array {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		/*
		 * $backoff_case is assembled from integer constants, never from input.
		 * Everything that could carry a value is bound.
		 */
		$sql = $wpdb->prepare(
			"SELECT id FROM `{$table}`
			 WHERE delivery_status = 'pending'
			   AND delivery_attempts < %d
			   AND (
			         delivery_last_attempt_at IS NULL
			         OR delivery_last_attempt_at <= DATE_SUB( %s, INTERVAL ( {$backoff_case} ) SECOND )
			       )
			 ORDER BY created_at ASC
			 LIMIT %d",
			$max_attempts,
			$now,
			$limit
		);

		$ids = $wpdb->get_col( $sql );

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Claims a lead for delivery.
	 *
	 * The guard is `delivery_status = 'pending'` inside the UPDATE, so of two
	 * workers reaching the same row exactly one changes it. The attempt counter
	 * increments HERE rather than after the response: a request that never
	 * returns — a fatal, a killed process — must still count, or a persistently
	 * crashing lead would be retried forever.
	 *
	 * @param int $id Lead id.
	 * @return bool True when this caller owns the row.
	 */
	public static function claim_for_delivery( int $id ): bool {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET delivery_status = 'processing',
				     delivery_attempts = delivery_attempts + 1,
				     delivery_last_attempt_at = %s,
				     updated_at = %s
				 WHERE id = %d AND delivery_status = 'pending'",
				$now,
				$now,
				$id
			)
		);

		return 1 === $claimed;
	}

	/**
	 * Marks a lead delivered.
	 *
	 * @param int $id Lead id.
	 */
	public static function mark_delivered( int $id ): bool {
		return self::update_delivery(
			$id,
			array(
				'delivery_status'       => 'delivered',
				'delivery_completed_at' => gmdate( 'Y-m-d H:i:s' ),
				'delivery_last_error'   => '',
			)
		);
	}

	/**
	 * Returns a lead to the pending pool for a later attempt.
	 *
	 * @param int    $id     Lead id.
	 * @param string $reason Short, safe failure summary.
	 */
	public static function mark_retrying( int $id, string $reason ): bool {
		return self::update_delivery(
			$id,
			array(
				'delivery_status'     => 'pending',
				'delivery_last_error' => self::reason( $reason ),
			)
		);
	}

	/**
	 * Marks a lead as not worth retrying.
	 *
	 * The row keeps every field. "Failed" means delivery stopped, never that
	 * the lead was discarded — an operator can fix the cause and requeue it.
	 *
	 * @param int    $id     Lead id.
	 * @param string $reason Short, safe failure summary.
	 */
	public static function mark_failed( int $id, string $reason ): bool {
		return self::update_delivery(
			$id,
			array(
				'delivery_status'     => 'failed',
				'delivery_last_error' => self::reason( $reason ),
			)
		);
	}

	/**
	 * Puts a lead back in the queue with a clean slate.
	 *
	 * @param int $id Lead id.
	 */
	public static function requeue( int $id ): bool {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET delivery_status = 'pending',
				     delivery_attempts = 0,
				     delivery_last_attempt_at = NULL,
				     delivery_last_error = '',
				     updated_at = %s
				 WHERE id = %d AND delivery_status <> 'delivered'",
				$now,
				$id
			)
		);
	}

	/**
	 * Frees rows stuck in `processing`.
	 *
	 * A fatal error, a killed cron or a host restart can leave a row claimed
	 * and never resolved. Without this it would never be delivered and never be
	 * visible as a problem.
	 *
	 * @param int $stale_seconds How long counts as abandoned.
	 * @return int Rows reclaimed.
	 */
	public static function reclaim_stale_processing( int $stale_seconds ): int {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET delivery_status = 'pending',
				     delivery_last_error = 'reclaimed_stale_processing',
				     updated_at = %s
				 WHERE delivery_status = 'processing'
				   AND delivery_last_attempt_at IS NOT NULL
				   AND delivery_last_attempt_at <= DATE_SUB( %s, INTERVAL %d SECOND )",
				$now,
				$now,
				max( 60, $stale_seconds )
			)
		);
	}

	/**
	 * Counts of leads by delivery status, for the admin screen.
	 *
	 * @return array<string,int>
	 */
	public static function delivery_counts(): array {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );

		$rows = $wpdb->get_results(
			"SELECT delivery_status, COUNT(*) AS total FROM `{$table}` GROUP BY delivery_status",
			ARRAY_A
		);

		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'delivered'  => 0,
			'failed'     => 0,
		);

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['delivery_status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * The most recent leads, with delivery state only.
	 *
	 * Deliberately returns NO personal data: the operational question is
	 * whether delivery is working, and that needs an identifier, a date and a
	 * status — not a name, an email or an address.
	 *
	 * @param int $limit How many rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_delivery_state( int $limit = 20 ): array {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, lead_uid, created_at, delivery_status, delivery_attempts,
				        delivery_last_attempt_at, delivery_completed_at, delivery_last_error
				 FROM `{$table}`
				 ORDER BY id DESC
				 LIMIT %d",
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A page of leads for the admin list.
	 *
	 * NO CONTACT DETAILS ARE SELECTED HERE. The list screen shows a reference,
	 * what was asked for, where, and the delivery state; a name, email, phone
	 * or address is only read when an administrator deliberately opens one
	 * lead, and that path goes through find(). Selecting the whole row for a
	 * list would put twenty people's contact details into one page render, and
	 * into the browser history of whoever glanced at it.
	 *
	 * @param int                  $limit   Rows per page.
	 * @param int                  $offset  Rows to skip.
	 * @param array<string,string> $filters Optional 'service', 'delivery_status', 'verified' ('1'|'0').
	 * @return array<int,array<string,mixed>>
	 */
	public static function page( int $limit, int $offset, array $filters = array() ): array {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );

		list( $where, $params ) = self::list_filter_clause( $filters );

		$params[] = max( 1, min( 100, $limit ) );
		$params[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, lead_uid, service_slug, subcategory_id, location_slug, city, state_code,
				        phone_verified, consent_version, source_page, created_at,
				        delivery_status, delivery_attempts, delivery_last_error
				 FROM `{$table}`
				 {$where}
				 ORDER BY id DESC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many leads match a filter.
	 *
	 * @param array<string,string> $filters Same shape as page().
	 */
	public static function count( array $filters = array() ): int {
		global $wpdb;

		$table = Schema::table( Schema::LEADS );

		list( $where, $params ) = self::list_filter_clause( $filters );

		if ( array() === $params ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` {$where}", $params )
		);
	}

	/**
	 * Builds the admin list WHERE clause.
	 *
	 * Only the three columns the screen offers can be filtered, each bound.
	 * Nothing here accepts a column name or an operator from a request.
	 *
	 * @param array<string,string> $filters Raw filter map.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function list_filter_clause( array $filters ): array {
		$clauses = array();
		$params  = array();

		if ( isset( $filters['service'] ) && '' !== $filters['service'] ) {
			$clauses[] = 'service_slug = %s';
			$params[]  = $filters['service'];
		}

		if ( isset( $filters['delivery_status'] ) && '' !== $filters['delivery_status'] ) {
			$clauses[] = 'delivery_status = %s';
			$params[]  = $filters['delivery_status'];
		}

		if ( isset( $filters['verified'] ) && '' !== $filters['verified'] ) {
			$clauses[] = 'phone_verified = %d';
			$params[]  = '1' === $filters['verified'] ? 1 : 0;
		}

		return array(
			array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			$params,
		);
	}

	/**
	 * Applies a delivery state change.
	 *
	 * @param int                 $id   Lead id.
	 * @param array<string,mixed> $data Columns to set.
	 */
	private static function update_delivery( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		return false !== $wpdb->update( Schema::table( Schema::LEADS ), $data, array( 'id' => $id ) );
	}

	/**
	 * Caps a failure reason to the column width.
	 *
	 * Reasons are provider-supplied short codes such as "http_503"; this is a
	 * guard, not a sanitiser, and no response body ever reaches it.
	 *
	 * @param string $reason Raw reason.
	 */
	private static function reason( string $reason ): string {
		return substr( trim( $reason ), 0, 255 );
	}

	/**
	 * Builds a public reference for a lead.
	 *
	 * Matches the React format ("3B-XXXX-XXXX") so a reference quoted by a
	 * customer is recognisable across both implementations. It is a display
	 * reference, not a secret: the row is found by its indexed unique column.
	 */
	private static function generate_uid(): string {
		return sprintf(
			'3B-%s-%s',
			strtoupper( base_convert( (string) time(), 10, 36 ) ),
			strtoupper( substr( bin2hex( random_bytes( 3 ) ), 0, 4 ) )
		);
	}
}
