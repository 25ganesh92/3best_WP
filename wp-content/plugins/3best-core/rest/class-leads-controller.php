<?php
/**
 * Lead submission endpoint.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Rest;

use ThreeBest\Core\Api_Exception;
use ThreeBest\Core\Field_Errors;
use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Leads\Lead_Repository;
use ThreeBest\Core\Leads\Lead_Validator;
use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Otp\Otp_Service;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Security\Request_Guard;
use ThreeBest\Core\Services\Services_Repository;
use ThreeBest\Core\Seo\Service_Locations;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * POST /threebest/v1/leads
 *
 * The gate the whole phase exists to build. A lead is stored only when every
 * one of these holds, checked here in this order and all server-side:
 *
 *   1. The service slug resolves to a canonical service.
 *   2. The subcategory belongs to THAT service.
 *   3. The location slug resolves to a canonical location.
 *   4. The service/location combination resolves.
 *   5. Every lead field passes validation.
 *   6. Consent was given.
 *   7. A verification token is present, unexpired, unspent — and the number it
 *      proves MATCHES the number on the lead.
 *
 * Point 7 is what makes the sequence unbypassable. The client cannot assert
 * "verified": there is no such parameter, and the only way to obtain a token is
 * to have answered a code at /otp/verify. Consuming it is a conditional UPDATE,
 * so it works exactly once, and binding it to the phone number stops a
 * verification of one number being spent on a lead carrying another.
 *
 * Nothing here calls Zapier. The lead is stored with delivery_status 'pending'
 * for Phase 6 to pick up.
 */
final class Leads_Controller extends Controller {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'     => '/leads',
				'methods'  => 'POST',
				'callback' => array( $this, 'submit' ),
				'access'   => Request_Guard::ACCESS_PUBLIC_NONCE,
				'throttle' => 'lead_submit',
			),
		);
	}

	/**
	 * Handles a lead submission.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string,mixed>
	 *
	 * @throws Api_Exception On any failed precondition.
	 */
	public function submit( WP_REST_Request $request ): array {
		// 1. Service — resolved, never trusted as a string.
		$service = Services_Repository::by_slug(
			Sanitizer::identifier( $request->get_param( 'serviceSlug' ), 64 )
		);

		if ( null === $service ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'service' => __( 'A valid service is required.', '3best-core' ) ) )
			);
		}

		// 2. Subcategory — must belong to this service, not merely exist.
		$subcategory_id = Sanitizer::identifier( $request->get_param( 'subcategoryId' ), 96 );
		$subcategory    = Services_Repository::subcategory( $service, $subcategory_id );

		// 3. Location — resolved from the slug, never from client-supplied
		//    city/state/ZIP values.
		$location = Catalog::by_slug(
			Sanitizer::identifier( $request->get_param( 'locationSlug' ), 96 )
		);

		if ( null === $location ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'location' => __( 'A valid location is required.', '3best-core' ) ) )
			);
		}

		// 4. The combination itself must resolve.
		if ( null === Service_Locations::resolve( $service['slug'], $location ) ) {
			throw Api_Exception::validation(
				Field_Errors::from( array( 'location' => __( 'A valid location is required.', '3best-core' ) ) )
			);
		}

		// 5. Lead fields.
		$fields = array(
			'subcategory_id' => $subcategory_id,
			'full_name'      => Sanitizer::text( $request->get_param( 'fullName' ), 150 ),
			'email'          => Sanitizer::email( $request->get_param( 'email' ) ),
			'phone'          => Sanitizer::phone( $request->get_param( 'phone' ) ),
			'address'        => Sanitizer::address( $request->get_param( 'address' ) ),
		);

		$errors = Lead_Validator::validate_fields( $fields );

		if ( null === $subcategory ) {
			$errors->add( 'subcategory', __( 'Please choose the service you need.', '3best-core' ) );
		}

		// 6. Consent.
		if ( ! Sanitizer::boolean( $request->get_param( 'consent' ) ) ) {
			$errors->add( 'consent', Consent::error_message() );
		}

		if ( $errors->any() ) {
			throw Api_Exception::validation( $errors );
		}

		/*
		 * 7. Verification.
		 *
		 * The token is claimed FOR THIS NUMBER in a single atomic statement, so
		 * a verification obtained for one phone cannot be spent on a lead
		 * carrying another, and a rejected submission never burns a good
		 * verification. Validation above runs first precisely so a token is not
		 * consumed by a request that was going to fail anyway.
		 */
		$token      = Sanitizer::text( $request->get_param( 'verificationToken' ), 128 );
		$lead_phone = Lead_Validator::normalize_phone( $fields['phone'] );

		if ( ! Otp_Service::consume_token( $token, $lead_phone ) ) {
			Logger::warning(
				'Lead submission rejected: not verified.',
				array( 'had_token' => '' !== $token )
			);

			throw Api_Exception::forbidden(
				__( 'Please verify your phone number before submitting your request.', '3best-core' )
			);
		}

		$fields['phone'] = $lead_phone;

		$stored = Lead_Repository::insert(
			$fields,
			$service,
			$subcategory,
			$location,
			$this->context( $request, $location )
		);

		return array(
			'leadId'  => $stored['lead_uid'],
			'message' => __( 'Lead received.', '3best-core' ),
		);
	}

	/**
	 * Collects attribution context.
	 *
	 * Every value is sanitised, length-capped, and — for the source page —
	 * reduced to a site-relative path, so an external URL cannot be recorded as
	 * though the lead came from our own site.
	 *
	 * @param WP_REST_Request $request  Incoming request.
	 * @param Location        $location Canonical location.
	 * @return array<string,string>
	 */
	private function context( WP_REST_Request $request, Location $location ): array {
		unset( $location );

		$utm = array();

		foreach ( array( 'source', 'medium', 'campaign', 'term', 'content' ) as $key ) {
			$utm[ 'utm_' . $key ] = Sanitizer::text( $request->get_param( 'utm_' . $key ), 100 );
		}

		return array_merge(
			array(
				'source_page' => Sanitizer::path( $request->get_param( 'sourcePage' ) ),
				'referrer'    => Sanitizer::text( $request->get_param( 'referrer' ), 255 ),
			),
			$utm
		);
	}
}
