<?php
/**
 * Lead delivery provider contract.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the queue and whoever receives the lead.
 *
 * Zapier is the destination today, but the queue, the retry policy, the claim
 * mechanism and the state machine know nothing about it. A provider is handed a
 * finished payload and returns a Delivery_Result; that is the entire contract.
 *
 * SERVER-SIDE ONLY. An implementation holds the destination URL, which is a
 * bearer secret: anyone who has it can post leads into the customer's CRM.
 */
interface Delivery_Provider {

	/**
	 * Machine-readable provider name, e.g. 'zapier'.
	 */
	public function name(): string;

	/**
	 * Whether this provider is configured well enough to attempt a delivery.
	 *
	 * False when the destination is missing or unusable, so the queue can
	 * record that fact without making a request.
	 */
	public function is_configured(): bool;

	/**
	 * Why the provider is not configured, as a short safe string.
	 *
	 * Must never contain the destination URL or any part of it.
	 */
	public function configuration_error(): string;

	/**
	 * Sends one lead.
	 *
	 * Must not throw. Every failure — transport, HTTP, malformed response — is
	 * expressed as a Delivery_Result, because a thrown exception inside a cron
	 * batch would abandon the rest of the queue.
	 *
	 * @param array<string,mixed> $payload Finished payload.
	 */
	public function send( array $payload ): Delivery_Result;
}
