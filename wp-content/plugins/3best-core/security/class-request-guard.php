<?php
/**
 * REST request authorisation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Security;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a REST request is allowed to reach its handler.
 *
 * Four access policies cover everything this application needs. Each route
 * declares exactly one, so no endpoint can be registered without an explicit
 * answer to "who may call this".
 *
 * ---------------------------------------------------------------------------
 * ACCESS_PUBLIC          anyone, no token
 * ---------------------------------------------------------------------------
 * For endpoints that are safe to call from anywhere and hold no state — a
 * read-only lookup, say. Protection is throttling alone. Nothing in this
 * project uses it yet; it exists so that choosing it is a deliberate act.
 *
 * ---------------------------------------------------------------------------
 * ACCESS_PUBLIC_NONCE    anyone, valid wp_rest nonce required   <- consumer default
 * ---------------------------------------------------------------------------
 * The policy every public consumer endpoint will use: location search, OTP
 * request and verify, lead submission. No WordPress login is involved, so it
 * does NOT rest on a capability check — an anonymous visitor filling in the
 * lead form has no capabilities at all, and gating these on manage_options
 * would make the site unusable.
 *
 * Two things must be understood about what the nonce does here:
 *
 *  - It is CSRF protection, not authentication. For a logged-out visitor
 *    wp_create_nonce() is tied to user 0, so the token is not a secret and not
 *    an identity. It stops another origin driving the endpoint with a visitor's
 *    browser; it does not stop a script calling it directly. Throttling, and
 *    from Phase 5 the single-use verification token, are what limit abuse.
 *  - It expires, and a cached page can serve a stale one. That failure is given
 *    its own error code, invalid_nonce, so a client can fetch a fresh token and
 *    retry once instead of showing a dead form. Phase 9 decides whether the
 *    nonce is fetched from an uncached endpoint or the policy is relaxed in
 *    favour of throttling.
 *
 * ---------------------------------------------------------------------------
 * ACCESS_AUTHENTICATED   signed-in WordPress user
 * ---------------------------------------------------------------------------
 * Any logged-in user. WordPress core already requires a valid wp_rest nonce for
 * cookie-authenticated requests, so CSRF is covered before this check runs.
 *
 * ---------------------------------------------------------------------------
 * ACCESS_CAPABILITY      signed-in user holding a named capability
 * ---------------------------------------------------------------------------
 * Administrative endpoints. Defaults to manage_options.
 *
 * ---------------------------------------------------------------------------
 *
 * The callback returns WP_Error rather than throwing, because that is what
 * WordPress expects from a permission_callback. The router reshapes those
 * errors into the standard envelope on the way out, so a caller sees one
 * response format whether the refusal came from here or from a handler.
 */
final class Request_Guard {

	public const ACCESS_PUBLIC        = 'public';
	public const ACCESS_PUBLIC_NONCE  = 'public_nonce';
	public const ACCESS_AUTHENTICATED = 'authenticated';
	public const ACCESS_CAPABILITY    = 'capability';

	/**
	 * The action every nonce in this plugin is created and verified against.
	 *
	 * wp_rest is WordPress's own REST action, so wp_create_nonce( 'wp_rest' )
	 * and the X-WP-Nonce header behave exactly as any WordPress developer
	 * expects, and core's own cookie check agrees with ours.
	 */
	public const NONCE_ACTION = 'wp_rest';

	/**
	 * Every policy this guard understands.
	 *
	 * @return string[]
	 */
	public static function policies(): array {
		return array(
			self::ACCESS_PUBLIC,
			self::ACCESS_PUBLIC_NONCE,
			self::ACCESS_AUTHENTICATED,
			self::ACCESS_CAPABILITY,
		);
	}

	/**
	 * Authorises a request against a route's declared policy.
	 *
	 * @param WP_REST_Request $request    Incoming request.
	 * @param string          $access     One of the ACCESS_* constants.
	 * @param string          $capability Capability required by ACCESS_CAPABILITY.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request, string $access, string $capability = 'manage_options' ) {
		switch ( $access ) {
			case self::ACCESS_PUBLIC:
				return true;

			case self::ACCESS_PUBLIC_NONCE:
				return self::check_nonce( $request );

			case self::ACCESS_AUTHENTICATED:
				if ( ! is_user_logged_in() ) {
					return self::error( 'not_authenticated', __( 'You need to be signed in to do that.', '3best-core' ), 401 );
				}

				return true;

			case self::ACCESS_CAPABILITY:
				if ( ! is_user_logged_in() ) {
					return self::error( 'not_authenticated', __( 'You need to be signed in to do that.', '3best-core' ), 401 );
				}

				if ( ! current_user_can( $capability ) ) {
					return self::error( 'forbidden', __( 'You do not have permission to do that.', '3best-core' ), 403 );
				}

				return true;
		}

		// An unrecognised policy is a programming error. Refuse rather than
		// guess — failing closed is the only safe default here.
		return self::error( 'forbidden', __( 'You do not have permission to do that.', '3best-core' ), 403 );
	}

	/**
	 * Verifies the wp_rest nonce from the header or a request parameter.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private static function check_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$param = $request->get_param( '_wpnonce' );
			$nonce = is_string( $param ) ? $param : '';
		}

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return self::error(
				'invalid_nonce',
				__( 'Your session has expired. Please refresh the page and try again.', '3best-core' ),
				403
			);
		}

		return true;
	}

	/**
	 * Builds a WP_Error carrying the status the router will use.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Safe, consumer-facing message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
