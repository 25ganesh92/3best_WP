<?php
/**
 * Outcome of one delivery attempt.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * What a delivery attempt decided, in our vocabulary rather than HTTP's.
 *
 * The queue never inspects a status code. It asks one question — should this be
 * tried again? — and the provider answers it, because only the provider knows
 * what its own responses mean.
 *
 * Three outcomes, and the distinction between the last two is the whole point
 * of the retry policy:
 *
 *   DELIVERED   the endpoint accepted it.
 *   RETRYABLE   something temporary. Try again later.
 *   PERMANENT   trying again will not help. Stop, but keep the lead.
 */
final class Delivery_Result {

	public const DELIVERED = 'delivered';
	public const RETRYABLE = 'retryable';
	public const PERMANENT = 'permanent';

	/**
	 * @param string              $outcome     One of the constants above.
	 * @param string              $reason      Short, safe, non-PII summary for storage.
	 * @param int                 $http_status HTTP status, or 0 when none.
	 * @param array<string,mixed> $log_context Diagnostic detail. Logged, never stored or returned.
	 */
	private function __construct(
		public readonly string $outcome,
		public readonly string $reason = '',
		public readonly int $http_status = 0,
		public readonly array $log_context = array()
	) {}

	/**
	 * @param int $http_status HTTP status.
	 */
	public static function delivered( int $http_status = 200 ): self {
		return new self( self::DELIVERED, '', $http_status );
	}

	/**
	 * @param string              $reason      Safe summary.
	 * @param int                 $http_status HTTP status, 0 for transport failures.
	 * @param array<string,mixed> $context     Diagnostic detail.
	 */
	public static function retryable( string $reason, int $http_status = 0, array $context = array() ): self {
		return new self( self::RETRYABLE, $reason, $http_status, $context );
	}

	/**
	 * @param string              $reason      Safe summary.
	 * @param int                 $http_status HTTP status.
	 * @param array<string,mixed> $context     Diagnostic detail.
	 */
	public static function permanent( string $reason, int $http_status = 0, array $context = array() ): self {
		return new self( self::PERMANENT, $reason, $http_status, $context );
	}

	/**
	 * Whether the endpoint accepted the lead.
	 */
	public function is_delivered(): bool {
		return self::DELIVERED === $this->outcome;
	}

	/**
	 * Whether another attempt is worth making.
	 */
	public function should_retry(): bool {
		return self::RETRYABLE === $this->outcome;
	}
}
