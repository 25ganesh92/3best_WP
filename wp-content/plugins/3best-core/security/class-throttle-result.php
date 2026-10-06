<?php
/**
 * Outcome of a throttle check.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * What a throttle check decided, and the numbers behind it.
 *
 * Returned rather than thrown so the caller chooses what a refusal means. Most
 * endpoints will turn `!allowed` into a 429, but Phase 5 may want to record a
 * blocked verification attempt before refusing it, and Phase 9 may want to
 * count without enforcing while limits are tuned.
 */
final class Throttle_Result {

	/**
	 * @param bool   $allowed     Whether the request may proceed.
	 * @param int    $count       Hits recorded in the current window.
	 * @param int    $limit       Hits permitted per window; 0 means unlimited.
	 * @param int    $retry_after Seconds until the window resets.
	 * @param string $bucket      Which limit was applied.
	 */
	private function __construct(
		public readonly bool $allowed,
		public readonly int $count,
		public readonly int $limit,
		public readonly int $retry_after,
		public readonly string $bucket
	) {}

	/**
	 * A request that may proceed.
	 *
	 * @param int    $count       Hits recorded.
	 * @param int    $limit       Limit applied.
	 * @param int    $retry_after Seconds until reset.
	 * @param string $bucket      Bucket name.
	 */
	public static function allow( int $count, int $limit, int $retry_after, string $bucket ): self {
		return new self( true, $count, $limit, $retry_after, $bucket );
	}

	/**
	 * A request that has exceeded its limit.
	 *
	 * @param int    $count       Hits recorded.
	 * @param int    $limit       Limit applied.
	 * @param int    $retry_after Seconds until reset.
	 * @param string $bucket      Bucket name.
	 */
	public static function deny( int $count, int $limit, int $retry_after, string $bucket ): self {
		return new self( false, $count, $limit, $retry_after, $bucket );
	}

	/**
	 * A check that was not enforced, because no limit is configured.
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function unlimited( string $bucket ): self {
		return new self( true, 0, 0, 0, $bucket );
	}

	/**
	 * Hits left in the window. 0 when unlimited.
	 */
	public function remaining(): int {
		if ( $this->limit <= 0 ) {
			return 0;
		}

		return max( 0, $this->limit - $this->count );
	}
}
