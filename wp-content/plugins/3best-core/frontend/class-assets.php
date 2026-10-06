<?php
/**
 * Front-end asset registration.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Frontend;

use ThreeBest\Core\Location\Location_Service;
use ThreeBest\Core\Location\Provider_Factory;
use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Rest\Controller;
use ThreeBest\Core\Security\Request_Guard;
use ThreeBest\Core\Services\Services_Repository;
use ThreeBest\Core\Seo\Landing_Page;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the search behaviour and hands it everything it needs.
 *
 * Only on pages that actually contain the form, so a page without it pays
 * nothing. The service catalogue travels with the page rather than over the
 * network, matching the React implementation where service filtering is
 * synchronous and instant — a picker that waited on a request per keystroke
 * would be a regression, and the catalogue is public information anyway.
 *
 * NOTHING SENSITIVE IS LOCALISED. The payload is a REST URL, a nonce, the
 * public service catalogue, tuning numbers and UI strings. No API key, no
 * provider name, no endpoint of any geocoder — the browser cannot tell which
 * provider is behind the REST route, which is the point.
 */
final class Assets {

	public const HANDLE = 'threebest-search';

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_lead_form' ), 20 );
	}

	/**
	 * Loads the lead-form behaviour, but only on a page that renders one.
	 *
	 * Detected late (priority 20) and from the rendered content, because the
	 * form can arrive either from the shortcode or, later, from the landing
	 * template calling the action directly.
	 */
	public static function enqueue_lead_form(): void {
		if ( ! self::needs_lead_form() ) {
			return;
		}

		$relative = 'assets/js/lead-form.js';
		$path     = THREEBEST_CORE_DIR . $relative;

		wp_enqueue_script(
			'threebest-lead-form',
			THREEBEST_CORE_URL . $relative,
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : THREEBEST_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_add_inline_script(
			'threebest-lead-form',
			'window.threebestLead = ' . wp_json_encode( self::lead_config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration for the lead form.
	 *
	 * Strings and tuning numbers only. No credentials, no provider name, and
	 * nothing that could let a client assert a verified state.
	 *
	 * @return array<string,mixed>
	 */
	private static function lead_config(): array {
		return array(
			'restUrl' => rest_url( Controller::REST_NAMESPACE ),
			'nonce'   => wp_create_nonce( Request_Guard::NONCE_ACTION ),
			'config'  => array(
				'otpLength'  => (int) Settings::get( 'otp_length', 6 ),
				'storageKey' => '3best:selected-location',
			),
			'strings' => array(
				'sendCode'        => __( 'Send Verification Code', '3best-core' ),
				'sending'         => __( 'Sending…', '3best-core' ),
				'findMyBest'      => __( 'Find My 3 Best', '3best-core' ),
				'submitting'      => __( 'Submitting…', '3best-core' ),
				'consentRequired' => Consent::error_message(),
				'noteUnverified'  => __( 'We’ll text a 6-digit code to confirm your number before submitting.', '3best-core' ),
				'noteVerified'    => __( 'No cost, and no obligation to hire anyone.', '3best-core' ),
				'resend'          => __( 'Resend Code', '3best-core' ),
				'resendIn'        => __( 'Resend Code in %ds', '3best-core' ),
				'resent'          => __( 'A new code is on its way.', '3best-core' ),
				'rateLimited'     => __( 'Too many requests. Please wait a moment and try again.', '3best-core' ),
				'genericError'    => __( 'We could not complete that right now. Please try again.', '3best-core' ),
				'network'         => __( 'We could not reach the server. Check your connection and try again.', '3best-core' ),
			),
		);
	}

	/**
	 * Whether the current page renders a lead form.
	 */
	private static function needs_lead_form(): bool {
		// A landing page has no post behind it, so the shortcode scan cannot
		// see it; the resolved page is the signal instead.
		if ( Landing_Page::is_active() ) {
			return true;
		}

		$post = get_post();

		$needed = $post instanceof \WP_Post
			&& has_shortcode( (string) $post->post_content, 'threebest_lead_form' );

		/**
		 * Filters whether the lead-form assets load on this request.
		 *
		 * The landing template in a later phase sets this to true, since it
		 * calls the action rather than using the shortcode.
		 *
		 * @param bool $needed Current decision.
		 */
		return (bool) apply_filters( 'threebest_core_enqueue_lead_form', $needed );
	}

	/**
	 * Enqueues and configures the search script.
	 */
	public static function enqueue(): void {
		if ( ! self::needs_search() ) {
			return;
		}

		$relative = 'assets/js/search.js';
		$path     = THREEBEST_CORE_DIR . $relative;

		wp_enqueue_script(
			self::HANDLE,
			THREEBEST_CORE_URL . $relative,
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : THREEBEST_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.threebestSearch = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);
	}

	/**
	 * The configuration handed to the browser.
	 *
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return array(
			'restUrl'    => rest_url( Controller::REST_NAMESPACE . '/locations' ),
			// CSRF protection for the public endpoint. Not authentication —
			// see Request_Guard. A cached page can serve a stale one, which the
			// client handles by refreshing once on an invalid_nonce response.
			'nonce'      => wp_create_nonce( Request_Guard::NONCE_ACTION ),
			'homeUrl'    => home_url( '/' ),
			'categories' => Services_Repository::for_script(),
			'config'     => array(
				'minQueryLength' => Location_Service::min_query_length(),
				'debounceMs'     => 300,
				'maxResults'     => 5,
				'storageKey'     => '3best:selected-location',
			),
			'strings'    => array(
				'servicePlaceholderError' => __( 'Please choose the service you need.', '3best-core' ),
				'locationError'           => __( 'Please select a location from the suggestions.', '3best-core' ),
				'searching'               => __( 'Searching locations…', '3best-core' ),
				'startTyping'             => __( 'Start typing a city, state or ZIP code.', '3best-core' ),
				'startTypingHint'         => __( 'For example: New Jersey, Dallas or 07030.', '3best-core' ),
				'noLocations'             => __( 'No locations found.', '3best-core' ),
				'noLocationsHint'         => __( 'Try entering a city, state, ZIP code, or landmark.', '3best-core' ),
				'searchError'             => __( 'Unable to find locations right now. Please try again.', '3best-core' ),
				'searchErrorHint'         => __( 'Check your connection and try again in a moment.', '3best-core' ),
				'noServices'              => __( 'No services match that search.', '3best-core' ),
				'noServicesHint'          => __( 'Try a broader term such as plumbing, tax or legal.', '3best-core' ),
				// From the active provider, so swapping it cannot leave a
				// false credit behind.
				'attribution'             => Provider_Factory::get()->attribution(),
				'resultsAvailable'        => __( '%d locations available', '3best-core' ),
				'oneResultAvailable'      => __( '1 location available', '3best-core' ),
				'noResultsAnnouncement'   => __( 'No locations found', '3best-core' ),
			),
			'typeLabels' => array(
				'state'        => __( 'State', '3best-core' ),
				'city'         => __( 'City', '3best-core' ),
				'town'         => __( 'Town', '3best-core' ),
				'village'      => __( 'Village', '3best-core' ),
				'neighborhood' => __( 'Area', '3best-core' ),
				'postcode'     => __( 'ZIP', '3best-core' ),
				'landmark'     => __( 'Place', '3best-core' ),
				'address'      => __( 'Address', '3best-core' ),
			),
		);
	}

	/**
	 * Whether the current page renders a search form.
	 *
	 * The theme puts it on the front page and the services page. A filter keeps
	 * that from being a hard assumption if a template changes later.
	 */
	private static function needs_search(): bool {
		$needed = is_front_page() || is_page( 'services' );

		/**
		 * Filters whether the search assets load on this request.
		 *
		 * @param bool $needed Current decision.
		 */
		return (bool) apply_filters( 'threebest_core_enqueue_search', $needed );
	}
}
