<?php
/**
 * Internal error type that maps safely onto the public response envelope.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The one exception every request path throws when it cannot continue.
 *
 * It carries two separate things on purpose:
 *
 *   - a PUBLIC message, written for the person using the site, which is the
 *     only text that ever reaches an API consumer, and
 *   - a PRIVATE context array, which goes to the PII-safe logger and never
 *     leaves the server.
 *
 * That split is the whole point. A database error, a provider's raw response, a
 * filesystem path or a stack trace is diagnostic detail; putting any of it in a
 * response tells an attacker about the stack and tells a real user nothing they
 * can act on. So detail goes to the log, and the caller gets a sentence they
 * can actually do something with.
 *
 * The named constructors below fix the status code for each failure kind, so
 * the same situation cannot end up as a 400 in one endpoint and a 500 in the
 * next.
 */
final class Api_Exception extends \RuntimeException {

	/**
	 * HTTP status for the response.
	 */
	private int $status;

	/**
	 * Machine-readable code, so a client can branch without parsing prose.
	 */
	private string $error_code;

	/**
	 * Field-level messages, when the failure was validation.
	 */
	private Field_Errors $field_errors;

	/**
	 * Diagnostic detail. Logged, never returned.
	 *
	 * @var array<string,mixed>
	 */
	private array $log_context;

	/**
	 * Use the named constructors rather than `new`.
	 *
	 * @param string               $public_message Safe message for the consumer.
	 * @param int                  $status         HTTP status.
	 * @param string               $error_code     Machine-readable code.
	 * @param Field_Errors|null    $field_errors   Field-level messages.
	 * @param array<string,mixed>  $log_context    Private diagnostic detail.
	 * @param \Throwable|null      $previous       Underlying error, if any.
	 */
	private function __construct(
		string $public_message,
		int $status,
		string $error_code,
		?Field_Errors $field_errors = null,
		array $log_context = array(),
		?\Throwable $previous = null
	) {
		parent::__construct( $public_message, 0, $previous );

		$this->status       = $status;
		$this->error_code   = $error_code;
		$this->field_errors = $field_errors ?? Field_Errors::from();
		$this->log_context  = $log_context;
	}

	/**
	 * 400 — the submission failed validation.
	 *
	 * @param Field_Errors $field_errors Per-field messages.
	 * @param string       $message      Summary message.
	 */
	public static function validation( Field_Errors $field_errors, string $message = '' ): self {
		return new self(
			'' !== $message
				? $message
				: __( 'Some details need attention before we can continue.', '3best-core' ),
			400,
			'validation_failed',
			$field_errors
		);
	}

	/**
	 * 400 — the request itself was malformed.
	 *
	 * @param string              $message     Safe message.
	 * @param array<string,mixed> $log_context Private detail.
	 */
	public static function bad_request( string $message = '', array $log_context = array() ): self {
		return new self(
			'' !== $message ? $message : __( 'That request could not be understood.', '3best-core' ),
			400,
			'bad_request',
			null,
			$log_context
		);
	}

	/**
	 * 401 — the caller is not signed in.
	 */
	public static function unauthorized(): self {
		return new self(
			__( 'You need to be signed in to do that.', '3best-core' ),
			401,
			'not_authenticated'
		);
	}

	/**
	 * 403 — the caller is known but not permitted.
	 *
	 * @param string              $message     Safe message.
	 * @param array<string,mixed> $log_context Private detail.
	 */
	public static function forbidden( string $message = '', array $log_context = array() ): self {
		return new self(
			'' !== $message ? $message : __( 'You do not have permission to do that.', '3best-core' ),
			403,
			'forbidden',
			null,
			$log_context
		);
	}

	/**
	 * 403 — the request carried a missing or expired security token.
	 *
	 * Given its own code because it is recoverable in a way the other 403s are
	 * not: a client that sees invalid_nonce should fetch a fresh token and
	 * retry once. A page served from cache with a stale nonce lands here.
	 */
	public static function invalid_nonce(): self {
		return new self(
			__( 'Your session has expired. Please refresh the page and try again.', '3best-core' ),
			403,
			'invalid_nonce'
		);
	}

	/**
	 * 404 — nothing to act on.
	 *
	 * @param string $message Safe message.
	 */
	public static function not_found( string $message = '' ): self {
		return new self(
			'' !== $message ? $message : __( 'That could not be found.', '3best-core' ),
			404,
			'not_found'
		);
	}

	/**
	 * 429 — the caller is going too fast.
	 *
	 * @param int    $retry_after Seconds until the window resets.
	 * @param string $message     Safe message.
	 */
	public static function throttled( int $retry_after = 0, string $message = '' ): self {
		return new self(
			'' !== $message
				? $message
				: __( 'Too many requests. Please wait a moment and try again.', '3best-core' ),
			429,
			'rate_limited',
			null,
			array( 'retry_after' => $retry_after )
		);
	}

	/**
	 * 413 — the request body is larger than this API will look at.
	 *
	 * The message names no limit and echoes no input. Telling a caller the
	 * exact cap only helps someone calibrating against it, and the number is
	 * not something a legitimate client ever needs to know.
	 */
	public static function payload_too_large(): self {
		return new self(
			__( 'That request was too large.', '3best-core' ),
			413,
			'payload_too_large'
		);
	}

	/**
	 * 502 — an upstream service failed.
	 *
	 * The upstream's own message is diagnostic and stays in the log: it can
	 * name hosts, keys or internals the consumer has no business seeing.
	 *
	 * @param string              $message     Safe message.
	 * @param array<string,mixed> $log_context Private detail.
	 * @param \Throwable|null     $previous    Underlying error.
	 */
	public static function upstream( string $message = '', array $log_context = array(), ?\Throwable $previous = null ): self {
		return new self(
			'' !== $message
				? $message
				: __( 'That service is unavailable right now. Please try again.', '3best-core' ),
			502,
			'upstream_unavailable',
			null,
			$log_context,
			$previous
		);
	}

	/**
	 * 500 — something broke on our side.
	 *
	 * The public message is deliberately fixed and uninformative. Whatever
	 * actually happened belongs in $log_context.
	 *
	 * @param array<string,mixed> $log_context Private detail.
	 * @param \Throwable|null     $previous    Underlying error.
	 */
	public static function internal( array $log_context = array(), ?\Throwable $previous = null ): self {
		return new self(
			__( 'Something went wrong on our end. Please try again.', '3best-core' ),
			500,
			'internal_error',
			null,
			$log_context,
			$previous
		);
	}

	/**
	 * HTTP status for the response.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Machine-readable error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Field-level messages.
	 */
	public function field_errors(): Field_Errors {
		return $this->field_errors;
	}

	/**
	 * Private diagnostic detail, for the logger only.
	 *
	 * @return array<string,mixed>
	 */
	public function log_context(): array {
		return $this->log_context;
	}

	/**
	 * Whether this failure is worth writing to the log.
	 *
	 * A 400 or a 404 is the system working correctly and would only add noise.
	 * A 5xx, or anything wrapping a real exception, is not.
	 */
	public function should_log(): bool {
		return $this->status >= 500 || null !== $this->getPrevious();
	}
}
