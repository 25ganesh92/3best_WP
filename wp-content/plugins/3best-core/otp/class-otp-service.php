<?php
/**
 * Phone verification lifecycle.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Otp;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Field_Errors;
use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Leads\Lead_Validator;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Schema;
use ThreeBest\Core\Security\Throttle;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the verification lifecycle; the provider only sends and checks.
 *
 * The split matters: Twilio knows whether a code is correct, but only we know
 * whether this number has already been sent five codes today, whether the
 * window has closed, how many guesses are left, and whether a verification has
 * already been spent on a lead. Those are our rules, they live in our table,
 * and they hold whichever provider is in use.
 *
 * Lifecycle, as stored in wp_threebest_otp_verifications:
 *
 *   pending   a code has been requested and the provider accepted it
 *   verified  the code was checked and a single-use token was issued
 *   locked    too many wrong guesses
 *   expired   the window closed (derived from expires_at, not a status write)
 *   consumed  the token was spent on a lead (token_consumed_at)
 *
 * NO PLAINTEXT CODE IS EVER STORED. With Twilio Verify there is nothing to
 * store — Twilio holds the code. With a provider that generates codes locally
 * we hold only an HMAC. Same for the verification token.
 *
 * Parameters are the confirmed decision 5 values: 6 digits, 10-minute validity,
 * 30-second resend cooldown, 5 verification attempts, 5 sends per number per day.
 */
final class Otp_Service {

	/**
	 * How long a verification token stays usable once issued.
	 */
	private const TOKEN_TTL_FALLBACK = 900;

	/*
	 * ONE MESSAGE FOR EVERY "not now" OUTCOME.
	 *
	 * The cooldown, the per-number daily cap and the atomic counters behind
	 * them all answer with this sentence. Earlier wording said things like
	 * "too many codes for this number today", which quietly answered a question
	 * nobody should be able to ask: has anyone been requesting codes for this
	 * number? Distinct messages would let a caller probe a list of numbers and
	 * learn which ones are in use on the site.
	 *
	 * Enforcement is unchanged and no limit is relaxed — only the words are
	 * uniform. A residual timing signal remains; see the Phase 9 report.
	 */
	private static function wait_message(): string {
		return __( 'Please wait a little before requesting another code for this number.', '3best-core' );
	}

	/**
	 * Requests a verification code.
	 *
	 * @param string $phone_input Raw phone from the form.
	 * @param bool   $consent     Whether the consent box was ticked.
	 * @return array{otp_uid:string,expires_in:int,resend_after:int,masked:string,sms_sent:bool}
	 *
	 * @throws Api_Exception On validation, throttling or provider failure.
	 */
	public static function request( string $phone_input, bool $consent ): array {
		/*
		 * CONSENT IS CHECKED BEFORE ANYTHING ELSE HAPPENS. Not after validation,
		 * not alongside it — first. The whole point of the checkbox is that no
		 * message is sent without it, so there must be no path that reaches a
		 * provider call with consent false.
		 */
		if ( ! $consent ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'consent' => Consent::error_message() ) )
			);
		}

		$phone = Lead_Validator::normalize_phone( $phone_input );

		if ( '' === $phone ) {
			throw Api_Exception::validation(
				Field_Errors::from(
					array( 'mobile' => __( 'Enter a valid 10-digit US mobile number.', '3best-core' ) )
				)
			);
		}

		/*
		 * TWO LAYERS, DELIBERATELY.
		 *
		 * The atomic counters run first. They are a single INSERT ... ON
		 * DUPLICATE KEY UPDATE, so two simultaneous requests for the same
		 * number cannot both be told they are the first — which is exactly what
		 * the read-then-act database checks below can be talked into under
		 * concurrency, and what would turn "5 messages a day" into more.
		 *
		 * The database checks then run anyway. They read the rows that actually
		 * exist, so they remain authoritative and survive anything that clears
		 * the counter table. Neither layer is weakened by the other being
		 * there; both enforce the same confirmed decision 5 numbers.
		 */
		self::guard_resend_rate( $phone );
		self::guard_daily_rate( $phone );

		self::guard_resend_cooldown( $phone );
		$sent_today = self::guard_daily_send_cap( $phone );

		$provider = Otp_Provider_Factory::get();

		// A provider that generates its own code gets none from us, and we store
		// no hash. Only a locally generating provider produces something to hash.
		$code      = $provider instanceof Dev_Otp_Provider ? self::generate_code() : '';
		$code_hash = '' !== $code ? self::hash( $code ) : '';

		$result = $provider->request( Lead_Validator::to_e164( $phone ), $code );

		if ( ! $result->is_ok() ) {
			Logger::warning(
				'OTP request failed at the provider.',
				array_merge( $result->log_context, array( 'status' => $result->status ) )
			);

			if ( Otp_Result::RATE_LIMITED === $result->status ) {
				throw Api_Exception::throttled(
					60,
					__( 'Too many verification attempts. Please wait a few minutes and try again.', '3best-core' )
				);
			}

			if ( Otp_Result::INVALID_PHONE === $result->status ) {
				throw Api_Exception::validation(
					Field_Errors::from(
						array( 'mobile' => __( 'We could not send a code to that number. Please check it and try again.', '3best-core' ) )
					)
				);
			}

			throw Api_Exception::upstream(
				__( 'We could not send a verification code right now. Please try again.', '3best-core' )
			);
		}

		$ttl     = max( 60, (int) Settings::get( 'otp_ttl_seconds', 600 ) );
		$otp_uid = self::uid();
		$now     = gmdate( 'Y-m-d H:i:s' );

		self::insert(
			array(
				'otp_uid'      => $otp_uid,
				'phone'        => $phone,
				'code_hash'    => $code_hash,
				'status'       => 'pending',
				'attempts'     => 0,
				'max_attempts' => max( 1, (int) Settings::get( 'otp_max_attempts', 5 ) ),
				'send_count'   => $sent_today + 1,
				'consent'      => 1,
				'provider'     => $provider->name(),
				'provider_ref' => $result->reference,
				'ip'           => Sanitizer::client_ip(),
				'user_agent'   => Sanitizer::user_agent(),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		Logger::log(
			'OTP requested.',
			array(
				'otp_uid'  => $otp_uid,
				'provider' => $provider->name(),
				'sms_sent' => $provider->sends_sms(),
			)
		);

		return array(
			'otp_uid'      => $otp_uid,
			'expires_in'   => $ttl,
			'resend_after' => max( 0, (int) Settings::get( 'otp_resend_cooldown', 30 ) ),
			'masked'       => Lead_Validator::mask_phone( $phone ),
			// False in development, so the UI can say so rather than claiming an
			// SMS is on its way when none was sent.
			'sms_sent'     => $provider->sends_sms(),
		);
	}

	/**
	 * Checks a code and, on success, issues a single-use verification token.
	 *
	 * @param string $otp_uid Handle returned by request().
	 * @param string $code    The code the user entered.
	 * @return array{token:string,expires_in:int}
	 *
	 * @throws Api_Exception On validation, expiry, lockout or provider failure.
	 */
	public static function verify( string $otp_uid, string $code ): array {
		global $wpdb;

		$code = preg_replace( '/\D+/', '', $code ) ?? '';

		if ( '' === $code ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'code' => __( 'Enter the code we sent you.', '3best-core' ) ) )
			);
		}

		$row = self::find( $otp_uid );

		if ( null === $row ) {
			throw Api_Exception::validation(
				Field_Errors::from(
					array( 'code' => __( 'That verification has expired. Please request a new code.', '3best-core' ) )
				)
			);
		}

		if ( 'locked' === $row['status'] ) {
			throw Api_Exception::throttled(
				0,
				__( 'Too many incorrect codes. Please request a new one.', '3best-core' )
			);
		}

		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			self::update( $otp_uid, array( 'status' => 'expired' ) );

			throw Api_Exception::validation(
				Field_Errors::from(
					array( 'code' => __( 'That code has expired. Please request a new one.', '3best-core' ) )
				)
			);
		}

		$attempts = (int) $row['attempts'] + 1;
		$max      = (int) $row['max_attempts'];

		// The attempt is recorded BEFORE it is checked, so an abandoned or
		// crashed request still counts against the cap.
		self::update( $otp_uid, array( 'attempts' => $attempts ) );

		if ( $attempts > $max ) {
			self::update( $otp_uid, array( 'status' => 'locked' ) );

			throw Api_Exception::throttled(
				0,
				__( 'Too many incorrect codes. Please request a new one.', '3best-core' )
			);
		}

		$provider = Otp_Provider_Factory::get();
		$phone    = (string) $row['phone'];

		// A locally generated code is checked against our hash. A provider that
		// owns the code is asked.
		if ( '' !== $row['code_hash'] ) {
			$correct = hash_equals( (string) $row['code_hash'], self::hash( $code ) );
			$result  = $correct
				? Otp_Result::verified( $provider->name() )
				: Otp_Result::failure( $provider->name(), Otp_Result::FAILED );
		} else {
			$result = $provider->verify( Lead_Validator::to_e164( $phone ), $code );
		}

		if ( Otp_Result::VERIFIED !== $result->status ) {
			if ( Otp_Result::EXPIRED === $result->status ) {
				self::update( $otp_uid, array( 'status' => 'expired' ) );

				throw Api_Exception::validation(
					Field_Errors::from(
						array( 'code' => __( 'That code has expired. Please request a new one.', '3best-core' ) )
					)
				);
			}

			if ( Otp_Result::RATE_LIMITED === $result->status ) {
				throw Api_Exception::throttled( 60 );
			}

			$remaining = max( 0, $max - $attempts );

			throw Api_Exception::validation(
				Field_Errors::from(
					array(
						'code' => $remaining > 0
							? __( 'That code is not correct. Please check the code and try again.', '3best-core' )
							: __( 'Too many incorrect codes. Please request a new one.', '3best-core' ),
					)
				)
			);
		}

		/*
		 * The token is what the lead endpoint will demand. It is random, stored
		 * hashed, single-use, short-lived and bound to this phone number — so a
		 * client cannot assert "verified" on its own, and cannot replay a
		 * verification onto a second lead.
		 */
		$token     = bin2hex( random_bytes( 32 ) );
		$token_ttl = max( 60, (int) Settings::get( 'otp_token_ttl_seconds', self::TOKEN_TTL_FALLBACK ) );

		self::update(
			$otp_uid,
			array(
				'status'            => 'verified',
				'verify_token_hash' => self::hash( $token ),
				'token_expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $token_ttl ),
				'provider_ref'      => '' !== $result->reference ? $result->reference : (string) $row['provider_ref'],
			)
		);

		Logger::log( 'OTP verified.', array( 'otp_uid' => $otp_uid, 'provider' => $provider->name() ) );

		return array(
			'token'      => $token,
			'expires_in' => $token_ttl,
		);
	}

	/**
	 * Spends a verification token for a specific phone number.
	 *
	 * Every condition is part of ONE conditional UPDATE: the token must hash to
	 * a stored value, the row must be verified, the token must be unexpired and
	 * unspent, and it must belong to the number on the lead. The statement
	 * reports how many rows it changed, and only a 1 means the verification was
	 * both valid and successfully claimed.
	 *
	 * Doing it in one statement matters for two separate reasons:
	 *
	 *  - Single use. Two concurrent submissions cannot both succeed, because the
	 *    second finds token_consumed_at already set and changes nothing.
	 *  - A rejected submission must not burn a good verification. Checking the
	 *    phone number afterwards would consume the token first and then refuse
	 *    the lead, leaving a legitimate user verified but unable to submit.
	 *
	 * @param string $token          Token issued by verify().
	 * @param string $expected_phone The normalised number on the lead.
	 */
	public static function consume_token( string $token, string $expected_phone ): bool {
		global $wpdb;

		if ( '' === $token || 1 !== preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return false;
		}

		if ( '' === $expected_phone ) {
			return false;
		}

		$table = Schema::table( Schema::OTP_VERIFICATIONS );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$claimed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET token_consumed_at = %s, updated_at = %s
				 WHERE verify_token_hash = %s
				   AND phone = %s
				   AND status = 'verified'
				   AND token_consumed_at IS NULL
				   AND token_expires_at > %s",
				$now,
				$now,
				self::hash( $token ),
				$expected_phone,
				$now
			)
		);

		if ( 1 !== $claimed ) {
			// Deliberately not distinguishing replay from mismatch from expiry
			// in the log: all three are "this submission is not verified", and
			// a finer message would help someone probing the endpoint.
			Logger::warning( 'Verification token could not be claimed.' );

			return false;
		}

		return true;
	}

	/**
	 * Atomic per-number resend cooldown.
	 *
	 * @param string $phone Normalised 10-digit number.
	 *
	 * @throws Api_Exception When still cooling down.
	 */
	private static function guard_resend_rate( string $phone ): void {
		$result = Throttle::check( 'otp_resend_phone', 'phone:' . $phone );

		if ( ! $result->allowed ) {
			throw Api_Exception::throttled( $result->retry_after, self::wait_message() );
		}
	}

	/**
	 * Atomic per-number daily send cap.
	 *
	 * @param string $phone Normalised 10-digit number.
	 *
	 * @throws Api_Exception When the cap is reached.
	 */
	private static function guard_daily_rate( string $phone ): void {
		$result = Throttle::check( 'otp_request_phone', 'phone:' . $phone );

		if ( ! $result->allowed ) {
			Logger::warning( 'OTP per-number rate limit reached.', array( 'bucket' => 'otp_request_phone' ) );

			throw Api_Exception::throttled( 0, self::wait_message() );
		}
	}

	/**
	 * Refuses a resend inside the cooldown window.
	 *
	 * @param string $phone Normalised 10-digit number.
	 *
	 * @throws Api_Exception When still cooling down.
	 */
	private static function guard_resend_cooldown( string $phone ): void {
		global $wpdb;

		$cooldown = max( 0, (int) Settings::get( 'otp_resend_cooldown', 30 ) );

		if ( 0 === $cooldown ) {
			return;
		}

		$table = Schema::table( Schema::OTP_VERIFICATIONS );

		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM `{$table}` WHERE phone = %s ORDER BY id DESC LIMIT 1",
				$phone
			)
		);

		if ( null === $last ) {
			return;
		}

		$elapsed = time() - (int) strtotime( $last . ' UTC' );

		if ( $elapsed < $cooldown ) {
			throw Api_Exception::throttled( $cooldown - $elapsed, self::wait_message() );
		}
	}

	/**
	 * Refuses a send past the daily cap, and returns today's count.
	 *
	 * Per NUMBER, not per IP: the cost and the nuisance both land on whoever
	 * owns the phone, so that is what has to be protected.
	 *
	 * @param string $phone Normalised 10-digit number.
	 *
	 * @throws Api_Exception When the cap is reached.
	 */
	private static function guard_daily_send_cap( string $phone ): int {
		global $wpdb;

		$cap = max( 1, (int) Settings::get( 'otp_max_sends_per_day', 5 ) );

		$table = Schema::table( Schema::OTP_VERIFICATIONS );

		$sent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE phone = %s AND created_at >= %s",
				$phone,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);

		if ( $sent >= $cap ) {
			Logger::warning( 'OTP daily send cap reached.', array( 'sent' => $sent, 'cap' => $cap ) );

			throw Api_Exception::throttled( 0, self::wait_message() );
		}

		return $sent;
	}

	/**
	 * Finds a verification row by its public handle.
	 *
	 * @param string $otp_uid Handle.
	 * @return array<string,mixed>|null
	 */
	private static function find( string $otp_uid ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $otp_uid ) ) {
			return null;
		}

		$table = Schema::table( Schema::OTP_VERIFICATIONS );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE otp_uid = %s LIMIT 1", $otp_uid ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a verification row.
	 *
	 * @param array<string,mixed> $data Column values.
	 */
	private static function insert( array $data ): void {
		global $wpdb;

		$wpdb->insert( Schema::table( Schema::OTP_VERIFICATIONS ), $data );
	}

	/**
	 * Updates a verification row.
	 *
	 * @param string              $otp_uid Handle.
	 * @param array<string,mixed> $data    Column values.
	 */
	private static function update( string $otp_uid, array $data ): void {
		global $wpdb;

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$wpdb->update( Schema::table( Schema::OTP_VERIFICATIONS ), $data, array( 'otp_uid' => $otp_uid ) );
	}

	/**
	 * A cryptographically random numeric code of the configured length.
	 */
	private static function generate_code(): string {
		$length = max( 4, min( 10, (int) Settings::get( 'otp_length', 6 ) ) );
		$code   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}

		return $code;
	}

	/**
	 * Keyed hash used for codes and tokens alike.
	 *
	 * Keyed with a site salt, so a stolen database alone does not allow an
	 * offline attack against the small numeric space a code occupies.
	 *
	 * @param string $value Secret value.
	 */
	private static function hash( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'secure_auth' ) );
	}

	/**
	 * A public, non-guessable handle for a verification.
	 */
	private static function uid(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}
