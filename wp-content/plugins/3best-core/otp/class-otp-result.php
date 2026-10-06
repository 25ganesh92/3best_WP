<?php
/**
 * Outcome of an OTP provider call.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Otp;

defined( 'ABSPATH' ) || exit;

/**
 * What a provider decided, in our vocabulary rather than theirs.
 *
 * The lifecycle is explicit so the lead flow can branch on meaning instead of
 * on a provider's status strings. Twilio says "pending"/"approved"/"canceled";
 * another provider will say something else; neither reaches the caller.
 */
final class Otp_Result {

	/** The provider accepted the request and a code is on its way. */
	public const SENT = 'sent';

	/** The code was correct. */
	public const VERIFIED = 'verified';

	/** The code was wrong. */
	public const FAILED = 'failed';

	/** No live verification exists for this number any more. */
	public const EXPIRED = 'expired';

	/** The provider refused on rate-limit grounds. */
	public const RATE_LIMITED = 'rate_limited';

	/** The provider rejected the phone number itself. */
	public const INVALID_PHONE = 'invalid_phone';

	/**
	 * @param string              $status      One of the constants above.
	 * @param string              $provider    Provider name.
	 * @param string              $reference   Provider's own id, for support. Never a code.
	 * @param array<string,mixed> $log_context Diagnostic detail. Logged, never returned.
	 */
	private function __construct(
		public readonly string $status,
		public readonly string $provider,
		public readonly string $reference = '',
		public readonly array $log_context = array()
	) {}

	/**
	 * @param string              $provider  Provider name.
	 * @param string              $reference Provider reference.
	 * @param array<string,mixed> $context   Diagnostic detail.
	 */
	public static function sent( string $provider, string $reference = '', array $context = array() ): self {
		return new self( self::SENT, $provider, $reference, $context );
	}

	/**
	 * @param string              $provider  Provider name.
	 * @param string              $reference Provider reference.
	 * @param array<string,mixed> $context   Diagnostic detail.
	 */
	public static function verified( string $provider, string $reference = '', array $context = array() ): self {
		return new self( self::VERIFIED, $provider, $reference, $context );
	}

	/**
	 * @param string              $provider Provider name.
	 * @param string              $status   One of the failure constants.
	 * @param array<string,mixed> $context  Diagnostic detail.
	 */
	public static function failure( string $provider, string $status, array $context = array() ): self {
		return new self( $status, $provider, '', $context );
	}

	/**
	 * Whether the call succeeded in the sense the caller asked for.
	 */
	public function is_ok(): bool {
		return self::SENT === $this->status || self::VERIFIED === $this->status;
	}
}
