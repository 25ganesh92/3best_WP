<?php
/**
 * Health/status endpoint.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Security\Request_Guard;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/threebest/v1/status — a minimal health check.
 *
 * Its purpose is to answer one question — "is 3Best Core loaded and running,
 * and which version?" — for development, deployment verification and future
 * uptime monitoring.
 *
 * It is deliberately almost empty. Anything that describes the host, the
 * database or the schema is diagnostic detail that belongs on an authenticated
 * admin screen, not on a URL, so none of it is reported here:
 *
 *   no credentials, no configuration values, not even whether one is set
 *   no filesystem paths
 *   no database names, table names, column counts or row counts
 *   no PHP or WordPress versions, no extension list
 *   no PII
 *   no stack traces
 *
 * Administrator-only regardless, so the access policy and the minimal payload
 * are two independent layers rather than one.
 */
final class Status_Controller extends Controller {

	/**
	 * API version this namespace serves.
	 */
	private const API_VERSION = 'v1';

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'       => '/status',
				'methods'    => 'GET',
				'callback'   => array( $this, 'get_status' ),
				'access'     => Request_Guard::ACCESS_CAPABILITY,
				'capability' => 'manage_options',
				'throttle'   => 'default',
			),
		);
	}

	/**
	 * Builds the status payload.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 */
	public function get_status( WP_REST_Request $request ): array {
		unset( $request );

		return array(
			'status'        => 'ok',
			'apiVersion'    => self::API_VERSION,
			'pluginVersion' => THREEBEST_CORE_VERSION,
		);
	}
}
