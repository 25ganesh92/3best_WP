<?php
/**
 * Rate-limit facade.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

use ThreeBest\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The single call site for rate limiting: Throttle::check( $bucket, $id ).
 *
 * Endpoints name a bucket ('location_search', 'otp_request', …) and an
 * identity (an IP, a phone number). They never choose a limit, never touch a
 * counter and never know where counters live. That is what makes Phase 9 able
 * to change policy and storage without editing a single endpoint.
 *
 * Phase 2 scope, stated plainly: this is a working seam with provisional
 * limits, not the finished protection. Limits below are conservative
 * placeholders sized to stop obvious abuse without getting in the way during
 * development. Phase 9 owns the real numbers, per-phone and per-IP layering for
 * verification, atomic counting, and the decision about whether transients are
 * durable enough for a paid SMS endpoint.
 *
 * Buckets are deliberately declared here rather than invented at call sites, so
 * the full set of limited surfaces is visible in one place.
 */
final class Throttle {

	/**
	 * Limits: bucket => [ requests, window seconds ].
	 *
	 * A limit of 0 disables enforcement for that bucket.
	 *
	 * Phase 9 settled these. Every public surface is limited on at least two
	 * independent identities, so defeating one does not defeat the control:
	 * the network address the request came from, and the resource being spent
	 * (a phone number, a verification record).
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	private const DEFAULT_LIMITS = array(
		// Read-only search. Generous: a debounced autocomplete is chatty by
		// design, and nothing here costs money or writes a row.
		'location_search'   => array( 60, 60 ),

		// Codes requested from one network address, whatever numbers they name.
		// This is the limit that stops one script walking a list of phone
		// numbers; the per-number limits below cannot see across numbers.
		'otp_request'       => array( 5, 3600 ),

		// Codes sent to ONE number per day. Confirmed decision 5. Enforced here
		// atomically as well as in Otp_Service against the stored rows, so a
		// concurrent burst cannot slip past the read-then-act database check.
		'otp_request_phone' => array( 5, 86400 ),

		// The 30-second resend cooldown from confirmed decision 5, as an atomic
		// counter: one request per number per cooldown window.
		'otp_resend_phone'  => array( 1, 30 ),

		// Code guesses from one network address, across all verifications.
		'otp_verify'        => array( 10, 900 ),

		// Guesses against ONE verification record. Aligned with the 5-attempt
		// cap Otp_Service enforces on the row itself, so rotating IP addresses
		// buys no extra attempts against a single code.
		'otp_verify_code'   => array( 5, 900 ),

		// Lead submission. Also requires an unspent verification token, so this
		// is the outer limit rather than the only one.
		'lead_submit'       => array( 10, 3600 ),

		// Fallback for any bucket not named above.
		'default'           => array( 120, 60 ),
	);

	/**
	 * Buckets that stay USABLE when the counter store itself is broken.
	 *
	 * Confirmed decision for Phase 9, §15. A store failure is a storage
	 * problem, not a licence to abuse — but it also must not take the site
	 * down. So the split is by consequence:
	 *
	 *   FAIL OPEN   read-only lookups that cost nothing and write nothing.
	 *               Losing the limit for the duration of an outage is cheaper
	 *               than an autocomplete that refuses to work.
	 *
	 *   FAIL CLOSED everything that spends money, writes a row, or guesses a
	 *               secret. If the limiter cannot be trusted, the answer is
	 *               "try again shortly", never "help yourself".
	 *
	 * Anything not listed here fails closed, so adding a bucket without
	 * thinking about it gets the safe behaviour by default.
	 *
	 * @var string[]
	 */
	private const FAIL_OPEN_BUCKETS = array(
		'location_search',
	);

	/**
	 * Identity used when the caller cannot be identified.
	 */
	private const UNIDENTIFIED = '__unidentified__';

	private static ?Throttle_Store $store = null;

	/**
	 * Checks and records a request against a bucket.
	 *
	 * @param string $bucket     Bucket name; see DEFAULT_LIMITS.
	 * @param string $identifier Who is being limited — an IP, a phone number.
	 * @param bool   $record     False to test without recording a hit.
	 */
	public static function check( string $bucket, string $identifier, bool $record = true ): Throttle_Result {
		list( $limit, $window ) = self::limits_for( $bucket );

		if ( $limit <= 0 ) {
			return Throttle_Result::unlimited( $bucket );
		}

		/*
		 * An empty identity must not mean "unlimited". If the client IP cannot
		 * be determined, every such caller shares one counter instead of
		 * escaping the limit entirely — failing closed rather than open.
		 */
		$key = self::key( $bucket, '' !== $identifier ? $identifier : self::UNIDENTIFIED );

		try {
			$store = self::store();
			$count = $record ? $store->hit( $key, $window ) : $store->peek( $key );
			$after = $store->seconds_remaining( $key );
		} catch ( \Throwable $error ) {
			return self::on_store_failure( $bucket, $limit, $error );
		}

		return $count > $limit
			? Throttle_Result::deny( $count, $limit, $after, $bucket )
			: Throttle_Result::allow( $count, $limit, $after, $bucket );
	}

	/**
	 * Decides what a broken counter store means for this bucket.
	 *
	 * Logged every time, at error level, with the bucket and the decision but
	 * never the identity being limited. A limiter that has stopped working is
	 * an incident whichever way it is resolved, and it must be visible.
	 *
	 * @param string     $bucket Bucket name.
	 * @param int        $limit  Configured limit, for the result object.
	 * @param \Throwable $error  What went wrong.
	 */
	private static function on_store_failure( string $bucket, int $limit, \Throwable $error ): Throttle_Result {
		$fails_open = in_array( $bucket, self::FAIL_OPEN_BUCKETS, true );

		Logger::error(
			'Throttle store unavailable.',
			array(
				'bucket'   => $bucket,
				'decision' => $fails_open ? 'allowed' : 'denied',
				'type'     => get_debug_type( $error ),
				'detail'   => $error->getMessage(),
			)
		);

		if ( $fails_open ) {
			return Throttle_Result::allow( 0, $limit, 0, $bucket );
		}

		// 30 seconds: long enough that a retry storm does not pile onto a
		// struggling database, short enough that a transient fault is not felt
		// as an outage by someone filling in the form.
		return Throttle_Result::deny( $limit + 1, $limit, 30, $bucket );
	}

	/**
	 * Clears a counter, e.g. after a successful verification.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $identifier Identity.
	 */
	public static function reset( string $bucket, string $identifier ): void {
		try {
			self::store()->clear( self::key( $bucket, '' !== $identifier ? $identifier : self::UNIDENTIFIED ) );
		} catch ( \Throwable $error ) {
			// Clearing a counter is a courtesy, not a control. Failing to do it
			// leaves the caller limited for the rest of the window, which is
			// the safe direction, so this never propagates.
			Logger::warning( 'Throttle counter could not be cleared.', array( 'bucket' => $bucket ) );
		}
	}

	/**
	 * Whether a bucket stays usable when the counter store is broken.
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function fails_open( string $bucket ): bool {
		return in_array( $bucket, self::FAIL_OPEN_BUCKETS, true );
	}

	/**
	 * Every declared bucket, for the admin screen and for tests.
	 *
	 * @return string[]
	 */
	public static function buckets(): array {
		return array_keys( self::DEFAULT_LIMITS );
	}

	/**
	 * Limits for a bucket, after filtering.
	 *
	 * @param string $bucket Bucket name.
	 * @return array{0:int,1:int} [ requests, window seconds ]
	 */
	public static function limits_for( string $bucket ): array {
		$limits = self::DEFAULT_LIMITS[ $bucket ] ?? self::DEFAULT_LIMITS['default'];

		/**
		 * Filters the limit applied to a bucket.
		 *
		 * The hook Phase 9 uses to install real policy without touching any
		 * endpoint or this class.
		 *
		 * @param array{0:int,1:int} $limits [ requests, window seconds ].
		 * @param string             $bucket Bucket name.
		 */
		$limits = apply_filters( 'threebest_core_throttle_limits', $limits, $bucket );

		if ( ! is_array( $limits ) || 2 !== count( $limits ) ) {
			return self::DEFAULT_LIMITS['default'];
		}

		return array( max( 0, (int) $limits[0] ), max( 1, (int) $limits[1] ) );
	}

	/**
	 * The active store.
	 */
	public static function store(): Throttle_Store {
		if ( null === self::$store ) {
			/**
			 * Filters the throttle store implementation.
			 *
			 * The seam for swapping transients for a custom table, an object
			 * cache or an external limiter in Phase 9.
			 *
			 * @param Throttle_Store $store Default store.
			 */
			/*
			 * The table-backed store is the default wherever its table exists,
			 * because it is the only one of the two that counts atomically.
			 * Transients remain the fallback for an installation whose schema
			 * has not been upgraded yet, so a pending migration degrades the
			 * limiter rather than disabling it.
			 */
			$default = Db_Throttle_Store::is_available()
				? new Db_Throttle_Store()
				: new Transient_Throttle_Store();

			$store = apply_filters( 'threebest_core_throttle_store', $default );

			self::$store = $store instanceof Throttle_Store ? $store : new Transient_Throttle_Store();
		}

		return self::$store;
	}

	/**
	 * Replaces the store. Test seam.
	 *
	 * @param Throttle_Store|null $store Store, or null to reset to the default.
	 */
	public static function set_store( ?Throttle_Store $store ): void {
		self::$store = $store;
	}

	/**
	 * Builds the counter key.
	 *
	 * The identifier is hashed with a site-specific salt, so a counter cannot
	 * be guessed from outside and no raw IP or phone number is written in
	 * readable form.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $identifier Identity.
	 */
	private static function key( string $bucket, string $identifier ): string {
		return $bucket . ':' . hash_hmac( 'sha256', $identifier, wp_salt( 'nonce' ) );
	}
}
