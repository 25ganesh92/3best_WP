<?php
/**
 * SMS verification consent.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Leads;

use ThreeBest\Core\Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * The wording the user agreed to, and the record of them agreeing.
 *
 * Consent is stored as four facts rather than one boolean, because a bare flag
 * is not defensible. What makes the record meaningful is the exact wording that
 * was on screen, the moment it was shown, and where the request came from.
 *
 * VERSIONING IS THE POINT. The wording and the version are bound together here
 * and nowhere else. If the text ever changes, a NEW version constant is added
 * and the old one stays — historical consent records keep pointing at the words
 * those people actually saw. Editing TEXT in place without changing VERSION
 * would silently rewrite history, which is exactly what a consent record exists
 * to prevent.
 */
final class Consent {

	/**
	 * Current consent version (confirmed decision 3).
	 */
	public const VERSION = 'phone-verification-v1';

	/**
	 * The exact wording shown beside the checkbox (confirmed decision 4).
	 *
	 * Must stay character-for-character identical to what the form renders.
	 */
	public const TEXT = 'I understand that I’ll receive a verification code by text message to confirm my phone number before my request is submitted.';

	/**
	 * Historical wording, keyed by version.
	 *
	 * Nothing is ever removed. A stored lead citing an old version must always
	 * be resolvable back to the words that were on screen.
	 *
	 * @var array<string,string>
	 */
	private const ARCHIVE = array(
		self::VERSION => self::TEXT,
	);

	/**
	 * The message shown when the box is not ticked.
	 */
	public static function error_message(): string {
		return __( 'Please confirm that you understand the phone verification step.', '3best-core' );
	}

	/**
	 * The current wording, translated for display.
	 *
	 * The stored record keeps the canonical English of TEXT, so a lead captured
	 * in another locale still cites one unambiguous version.
	 */
	public static function display_text(): string {
		return __( 'I understand that I’ll receive a verification code by text message to confirm my phone number before my request is submitted.', '3best-core' );
	}

	/**
	 * The wording a given version presented.
	 *
	 * @param string $version Consent version.
	 */
	public static function text_for( string $version ): string {
		return self::ARCHIVE[ $version ] ?? '';
	}

	/**
	 * Builds the stored consent record for an accepted consent.
	 *
	 * Called only once consent has actually been given; it never fabricates a
	 * record for an unticked box.
	 *
	 * @return array{consent:int,consent_version:string,consent_text:string,consent_at:string,consent_ip:string,consent_user_agent:string}
	 */
	public static function record(): array {
		return array(
			'consent'            => 1,
			'consent_version'    => self::VERSION,
			// The wording is stored alongside the version, so the record stands
			// on its own even if this file is lost or refactored later.
			'consent_text'       => self::TEXT,
			'consent_at'         => gmdate( 'Y-m-d H:i:s' ),
			'consent_ip'         => Sanitizer::client_ip(),
			'consent_user_agent' => Sanitizer::user_agent(),
		);
	}
}
