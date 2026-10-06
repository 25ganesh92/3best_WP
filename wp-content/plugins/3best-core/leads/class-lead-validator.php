<?php
/**
 * Lead field validation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Leads;

use ThreeBest\Core\Field_Errors;

defined( 'ABSPATH' ) || exit;

/**
 * The rules that decide whether a submitted lead is acceptable.
 *
 * Ported from src/lib/validation.ts, messages included verbatim, so a person
 * filling in the WordPress form sees exactly what the React form told them.
 *
 * VALIDATION IS NOT SANITISATION. Sanitizer makes a value safe to handle;
 * this decides whether the safe value is acceptable, and produces a
 * field-level message when it is not. The split matters because sanitisation
 * must never change meaning, while normalisation here deliberately does —
 * normalize_phone() drops a leading country code, which is a decision about
 * what the number means, not about what is safe to store.
 *
 * The server is authoritative. The browser runs the same rules for immediate
 * feedback, but nothing reaching the database has been validated only there.
 */
final class Lead_Validator {

	/**
	 * Matches the React EMAIL_PATTERN exactly.
	 */
	private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i';

	/**
	 * Normalises a US number to 10 digits, tolerating a leading country code.
	 *
	 * Returns '' when the input cannot be a valid US mobile number.
	 *
	 * @param string $value Raw or sanitised phone input.
	 */
	public static function normalize_phone( string $value ): string {
		$digits = preg_replace( '/\D+/', '', $value ) ?? '';

		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 !== strlen( $digits ) ) {
			return '';
		}

		// Neither the area code nor the exchange code may begin with 0 or 1.
		if ( 1 === preg_match( '/^[01]/', $digits ) || 1 === preg_match( '/^[01]/', substr( $digits, 3 ) ) ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Whether a value is a usable US mobile number.
	 *
	 * @param string $value Phone input.
	 */
	public static function is_valid_phone( string $value ): bool {
		return '' !== self::normalize_phone( $value );
	}

	/**
	 * E.164 form for the SMS provider. '' when the number is not valid.
	 *
	 * @param string $value Phone input.
	 */
	public static function to_e164( string $value ): string {
		$digits = self::normalize_phone( $value );

		return '' !== $digits ? '+1' . $digits : '';
	}

	/**
	 * Masks a number for display on the verification screen.
	 *
	 * "2015551234" -> "(•••) •••-1234"
	 *
	 * @param string $value Phone input.
	 */
	public static function mask_phone( string $value ): string {
		$digits = self::normalize_phone( $value );

		return '' !== $digits ? '(•••) •••-' . substr( $digits, 6 ) : '';
	}

	/**
	 * Whether a value is a plausible email address.
	 *
	 * @param string $value Email input.
	 */
	public static function is_valid_email( string $value ): bool {
		return 1 === preg_match( self::EMAIL_PATTERN, trim( $value ) );
	}

	/**
	 * A deliberately forgiving street-address check.
	 *
	 * Enough characters and at least one number. Confirmed decision 14 keeps
	 * this lightweight for now; the architecture allows Google Address
	 * Validation to replace it later without touching the form.
	 *
	 * @param string $value Address input.
	 */
	public static function is_valid_address( string $value ): bool {
		$trimmed = trim( $value );

		return strlen( $trimmed ) >= 8 && 1 === preg_match( '/\d/', $trimmed );
	}

	/**
	 * Validates the editable lead fields.
	 *
	 * Shared by the OTP request step and the final submission, so a field that
	 * blocks submission also blocks sending an SMS — there is no point paying
	 * for a message to accompany a lead that cannot be accepted.
	 *
	 * @param array<string,string> $values subcategory_id, full_name, email, phone, address.
	 */
	public static function validate_fields( array $values ): Field_Errors {
		$errors = Field_Errors::from();

		$get = static function ( string $key ) use ( $values ): string {
			return isset( $values[ $key ] ) && is_string( $values[ $key ] ) ? trim( $values[ $key ] ) : '';
		};

		if ( '' === $get( 'subcategory_id' ) ) {
			$errors->add( 'subcategory', __( 'Please choose the service you need.', '3best-core' ) );
		}

		$name = $get( 'full_name' );

		if ( '' === $name || strlen( $name ) < 2 ) {
			$errors->add( 'fullName', __( 'Please enter your full name.', '3best-core' ) );
		}

		$email = $get( 'email' );

		if ( '' === $email ) {
			$errors->add( 'email', __( 'Please enter your email address.', '3best-core' ) );
		} elseif ( ! self::is_valid_email( $email ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address.', '3best-core' ) );
		}

		$phone = $get( 'phone' );

		if ( '' === $phone ) {
			$errors->add( 'mobile', __( 'Please enter your mobile number.', '3best-core' ) );
		} elseif ( ! self::is_valid_phone( $phone ) ) {
			$errors->add( 'mobile', __( 'Enter a valid 10-digit US mobile number.', '3best-core' ) );
		}

		$address = $get( 'address' );

		if ( '' === $address ) {
			$errors->add( 'address', __( 'Please enter your full address.', '3best-core' ) );
		} elseif ( ! self::is_valid_address( $address ) ) {
			$errors->add( 'address', __( 'Include a street number, city, state and ZIP code.', '3best-core' ) );
		}

		return $errors;
	}
}
