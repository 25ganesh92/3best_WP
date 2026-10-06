<?php
/**
 * Abstract REST controller.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Security\Request_Guard;
use ThreeBest\Core\Security\Throttle;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every 3Best REST endpoint.
 *
 * A subclass describes its routes and writes plain handlers. Everything that
 * must not be forgotten — authorisation, throttling, the response envelope,
 * exception containment — happens here, once, for all of them.
 *
 * A route is an array:
 *
 *     array(
 *         'path'       => '/example',
 *         'methods'    => 'POST',
 *         'callback'   => array( $this, 'handle_example' ),
 *         'access'     => Request_Guard::ACCESS_PUBLIC_NONCE,
 *         'capability' => 'manage_options',   // ACCESS_CAPABILITY only
 *         'throttle'   => 'lead_submit',      // bucket name, or '' to skip
 *         'args'       => array( … ),         // WordPress argument schema
 *     )
 *
 * 'access' is required and has no default. Registering a route without it
 * raises a notice and the route is skipped, so an endpoint cannot be shipped
 * with an accidentally open permission callback — the single most common way
 * a WordPress REST endpoint ends up unprotected.
 *
 * A handler returns either an array, which becomes the success payload, or a
 * WP_REST_Response when it needs control of the status. To fail, it throws an
 * Api_Exception; nothing else needs to know how errors are rendered.
 */
abstract class Controller {

	/**
	 * Namespace for every route in this plugin.
	 *
	 * Versioned from the start so a future breaking change becomes /v2 rather
	 * than a silent contract change for a deployed front end.
	 */
	public const REST_NAMESPACE = 'threebest/v1';

	/**
	 * Largest request body a 3Best endpoint will look at, in bytes.
	 *
	 * The biggest legitimate payload this API accepts is a lead: a handful of
	 * short strings, the longest of which is a 255-character address. 64 KB is
	 * roughly two hundred times that — comfortably out of the way of anything
	 * real, and small enough that a megabyte of JSON is rejected before it is
	 * parsed, validated or logged.
	 *
	 * This is an APPLICATION-level backstop, not a replacement for the server's
	 * own limits. PHP's post_max_size and Apache's LimitRequestBody still do
	 * the heavy lifting and still have to be configured on the host; see the
	 * Phase 9 report. The value here is what keeps a body that squeaks past
	 * those from reaching a handler.
	 */
	public const MAX_BODY_BYTES = 65536;

	/**
	 * Routes this controller provides.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function routes(): array;

	/**
	 * Registers every route with WordPress.
	 */
	public function register(): void {
		foreach ( $this->routes() as $route ) {
			$this->register_route( $route );
		}
	}

	/**
	 * Registers one route.
	 *
	 * @param array<string,mixed> $route Route definition.
	 */
	private function register_route( array $route ): void {
		$path     = isset( $route['path'] ) ? (string) $route['path'] : '';
		$callback = $route['callback'] ?? null;
		$access   = isset( $route['access'] ) ? (string) $route['access'] : '';

		if ( '' === $path || ! is_callable( $callback ) ) {
			_doing_it_wrong( __METHOD__, 'A 3Best route needs a path and a callable callback.', '0.2.0' );

			return;
		}

		if ( ! in_array( $access, Request_Guard::policies(), true ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf( 'The 3Best route %s must declare a valid access policy.', esc_html( $path ) ),
				'0.2.0'
			);

			return;
		}

		$capability = isset( $route['capability'] ) ? (string) $route['capability'] : 'manage_options';
		$bucket     = isset( $route['throttle'] ) ? (string) $route['throttle'] : '';

		register_rest_route(
			self::REST_NAMESPACE,
			$path,
			array(
				'methods'             => $route['methods'] ?? 'GET',
				'args'                => isset( $route['args'] ) && is_array( $route['args'] ) ? $route['args'] : array(),
				'permission_callback' => static function ( WP_REST_Request $request ) use ( $access, $capability ) {
					return Request_Guard::authorize( $request, $access, $capability );
				},
				'callback'            => function ( WP_REST_Request $request ) use ( $callback, $bucket, $path ) {
					return $this->dispatch( $callback, $request, $bucket, $path );
				},
			)
		);
	}

	/**
	 * Runs a handler with throttling and full exception containment.
	 *
	 * @param callable        $callback Route handler.
	 * @param WP_REST_Request $request  Incoming request.
	 * @param string          $bucket   Throttle bucket, or '' to skip.
	 * @param string          $path     Route path, for logging.
	 */
	private function dispatch( callable $callback, WP_REST_Request $request, string $bucket, string $path ): WP_REST_Response {
		try {
			/*
			 * Size first: there is no point spending a throttle slot, or any
			 * parsing, on a body that is going to be refused anyway.
			 */
			$this->enforce_body_limit( $request );

			if ( '' !== $bucket ) {
				$this->enforce_throttle( $bucket, $request );
			}

			$result = $callback( $request );

			if ( $result instanceof WP_REST_Response ) {
				return $result;
			}

			return Response::success( is_array( $result ) ? $result : array() );
		} catch ( Api_Exception $exception ) {
			if ( $exception->should_log() ) {
				Logger::error(
					'REST request failed.',
					array_merge(
						$exception->log_context(),
						array(
							'route'  => $path,
							'code'   => $exception->error_code(),
							'status' => $exception->status(),
							'detail' => $exception->getPrevious() ? $exception->getPrevious()->getMessage() : '',
						)
					)
				);
			}

			return Response::from_exception( $exception );
		} catch ( \Throwable $error ) {
			/*
			 * Anything that reaches here is unplanned: a TypeError, a database
			 * failure, a null dereference. The full detail goes to the log; the
			 * caller gets a fixed sentence with no message, class, file or line
			 * from the original error. Leaking any of that would describe the
			 * stack to whoever triggered it.
			 */
			Logger::error(
				'Unhandled error in a REST handler.',
				array(
					'route'   => $path,
					'type'    => get_debug_type( $error ),
					'detail'  => $error->getMessage(),
					'where'   => basename( $error->getFile() ) . ':' . $error->getLine(),
				)
			);

			return Response::from_exception( Api_Exception::internal() );
		}
	}

	/**
	 * Refuses an oversized request body.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @throws Api_Exception When the body is larger than the cap.
	 */
	private function enforce_body_limit( WP_REST_Request $request ): void {
		/**
		 * Filters the maximum accepted request body size in bytes.
		 *
		 * @param int             $max     Maximum size.
		 * @param WP_REST_Request $request Incoming request.
		 */
		$max = (int) apply_filters( 'threebest_core_max_body_bytes', self::MAX_BODY_BYTES, $request );

		if ( $max <= 0 ) {
			return;
		}

		$size = strlen( (string) $request->get_body() );

		// A declared Content-Length is also checked, so a body that never
		// finished arriving is judged on what it claimed to be.
		$declared = $request->get_header( 'content-length' );

		if ( is_string( $declared ) && ctype_digit( $declared ) ) {
			$size = max( $size, (int) $declared );
		}

		if ( $size > $max ) {
			Logger::warning(
				'Request body exceeded the limit.',
				array(
					'bytes' => $size,
					'max'   => $max,
				)
			);

			// 413, and nothing about what the limit is or what was sent.
			throw Api_Exception::payload_too_large();
		}
	}

	/**
	 * Applies the route's rate limit, keyed by client IP.
	 *
	 * Endpoints needing a second dimension — per phone number, per session —
	 * call Throttle directly in their handler. Phase 9 layers those properly.
	 *
	 * @param string          $bucket  Bucket name.
	 * @param WP_REST_Request $request Incoming request.
	 *
	 * @throws Api_Exception When the limit is exceeded.
	 */
	private function enforce_throttle( string $bucket, WP_REST_Request $request ): void {
		unset( $request );

		$result = Throttle::check( $bucket, Sanitizer::client_ip() );

		if ( ! $result->allowed ) {
			throw Api_Exception::throttled( $result->retry_after );
		}
	}

	/**
	 * Reads a sanitised string parameter.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $name    Parameter name.
	 * @param int             $max     Maximum length.
	 */
	protected function string_param( WP_REST_Request $request, string $name, int $max = 0 ): string {
		return Sanitizer::text( $request->get_param( $name ), $max );
	}
}
