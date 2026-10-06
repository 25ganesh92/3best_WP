<?php
/**
 * REST route registration and envelope normalisation.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Field_Errors;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every controller, and guarantees one response shape.
 *
 * The second job is the interesting one. A request can fail before a handler is
 * ever reached — an unknown route, a parameter rejected by the argument schema,
 * a nonce refused by the permission callback — and WordPress renders those in
 * its own shape:
 *
 *     { "code": "rest_invalid_param", "message": "…", "data": { … } }
 *
 * A client would then need two parsers for one endpoint. So every error leaving
 * this namespace is normalised into the standard envelope, and core's
 * per-parameter messages are mapped into fieldErrors, which is where the React
 * client already looks for them.
 *
 * Two hooks are needed, because WordPress has two distinct exit paths:
 *
 *  - rest_request_after_callbacks fires inside WP_REST_Server::dispatch(), and
 *    is the only one that sees a refusal from a permission callback or from
 *    argument validation. It also fires for in-process calls made with
 *    rest_do_request(), which later phases will use for internal requests.
 *  - rest_post_dispatch fires in serve_request() and is the only one that sees
 *    a route that matched nothing at all. It does not fire for rest_do_request.
 *
 * Using either alone leaves a gap, so both are registered and the normaliser is
 * idempotent: a body that is already an envelope passes straight through.
 *
 * Only this plugin's namespace is touched. Core's and other plugins' endpoints
 * keep their own shape.
 */
final class Router {

	/**
	 * Controllers to register.
	 *
	 * @var class-string<Controller>[]
	 */
	private const CONTROLLERS = array(
		Status_Controller::class,
		Locations_Controller::class,
		Otp_Controller::class,
		Leads_Controller::class,
	);

	/**
	 * Hooks registration and normalisation.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'normalize_after_callbacks' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'normalize_post_dispatch' ), 10, 3 );
	}

	/**
	 * Instantiates and registers each controller.
	 */
	public static function register_routes(): void {
		foreach ( self::CONTROLLERS as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			$controller = new $class_name();

			if ( $controller instanceof Controller ) {
				$controller->register();
			}
		}
	}

	/**
	 * Normalises refusals from permission callbacks and argument validation.
	 *
	 * @param WP_REST_Response|WP_Error $response Result so far.
	 * @param array<string,mixed>       $handler  Matched route handler.
	 * @param WP_REST_Request           $request  Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function normalize_after_callbacks( $response, $handler, $request ) {
		unset( $handler );

		return self::normalize( $response, $request );
	}

	/**
	 * Normalises anything left, including a route that matched nothing.
	 *
	 * @param WP_HTTP_Response|WP_Error $response Dispatch result.
	 * @param mixed                     $server   REST server.
	 * @param WP_REST_Request           $request  Incoming request.
	 * @return WP_HTTP_Response|WP_Error
	 */
	public static function normalize_post_dispatch( $response, $server, $request ) {
		unset( $server );

		return self::normalize( $response, $request );
	}

	/**
	 * Converts an error into the standard envelope.
	 *
	 * Idempotent: anything already carrying a `success` key is returned as-is,
	 * so a body normalised by one hook is not rewritten by the other.
	 *
	 * @param mixed           $response Result to inspect.
	 * @param mixed           $request  Incoming request.
	 * @return mixed
	 */
	private static function normalize( $response, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! self::is_ours( $request ) ) {
			return $response;
		}

		if ( $response instanceof WP_Error ) {
			return self::from_wp_error( $response );
		}

		if ( ! $response instanceof WP_REST_Response || $response->get_status() < 400 ) {
			return $response;
		}

		$data = $response->get_data();

		if ( is_array( $data ) && array_key_exists( 'success', $data ) ) {
			return $response;
		}

		$envelope = Response::error(
			is_array( $data ) && ! empty( $data['message'] )
				? (string) $data['message']
				: __( 'That request could not be completed.', '3best-core' ),
			$response->get_status(),
			is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : 'error',
			self::field_errors_from( is_array( $data ) ? $data : array() )
		);

		foreach ( $response->get_headers() as $header => $value ) {
			$envelope->header( $header, $value );
		}

		return $envelope;
	}

	/**
	 * Builds an envelope response from a WP_Error.
	 *
	 * @param WP_Error $error Error to render.
	 */
	private static function from_wp_error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		return Response::error(
			$error->get_error_message() ?: __( 'That request could not be completed.', '3best-core' ),
			$status,
			$error->get_error_code() ? (string) $error->get_error_code() : 'error',
			self::field_errors_from( array( 'data' => is_array( $data ) ? $data : array() ) )
		);
	}

	/**
	 * Extracts per-parameter messages from a core REST error.
	 *
	 * WordPress uses two different shapes for data.params, and both have to be
	 * handled or half of the argument-schema failures arrive with an empty
	 * fieldErrors object:
	 *
	 *   rest_invalid_param          array( 'q' => 'q is not of type string.' )
	 *                               a map of parameter => message.
	 *   rest_missing_callback_param array( 'q' )
	 *                               a bare list of names, with no message.
	 *
	 * The list form gets a written message, because the client attaches these
	 * to form controls and "Missing parameter(s): q" is not something to show a
	 * person filling in a form.
	 *
	 * @param array<string,mixed> $data Error data.
	 */
	private static function field_errors_from( array $data ): Field_Errors {
		$errors = Field_Errors::from();
		$params = $data['data']['params'] ?? null;

		if ( ! is_array( $params ) ) {
			return $errors;
		}

		foreach ( $params as $field => $message ) {
			// Map form: parameter => message.
			if ( is_string( $field ) && is_string( $message ) ) {
				$errors->add( $field, $message );
				continue;
			}

			// List form: the value is the parameter name and no message exists.
			if ( is_int( $field ) && is_string( $message ) ) {
				$errors->add( $message, __( 'This field is required.', '3best-core' ) );
			}
		}

		return $errors;
	}

	/**
	 * Whether a request targets this plugin's namespace.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	private static function is_ours( WP_REST_Request $request ): bool {
		return 0 === strpos( ltrim( (string) $request->get_route(), '/' ), Controller::REST_NAMESPACE );
	}
}
