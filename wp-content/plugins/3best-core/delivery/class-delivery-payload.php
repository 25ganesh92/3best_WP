<?php
/**
 * Zapier payload construction.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Delivery;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored lead row into the payload sent downstream.
 *
 * Built from the PERSISTED ROW, never from a browser request. By the time
 * delivery runs, the submission is long finished; the database holds the
 * canonical, validated, verified version, and that is the only thing worth
 * sending. It also means a retry hours later sends exactly what the first
 * attempt did.
 *
 * ALLOW-LIST, not deny-list. Every field below is named deliberately. Internal
 * columns — the auto-increment id, the delivery bookkeeping, updated_at — are
 * absent because the recipient has no use for them and they would leak how the
 * system works. Nothing here can carry an OTP, a credential, a path or a trace,
 * because none of those is ever written to this table in the first place.
 *
 * Two values are enriched from configuration rather than stored: the service
 * and subcategory display names, and the location label. The row holds
 * identifiers, and a CRM needs words. The enrichment is derived from the same
 * catalogues that produced the identifiers, so it cannot disagree with them.
 */
final class Delivery_Payload {

	/**
	 * Schema version of the payload shape itself.
	 *
	 * Sent so a Zap built against today's fields can detect a future change
	 * rather than silently mapping the wrong thing.
	 */
	public const PAYLOAD_VERSION = '1';

	/**
	 * Builds the payload for one stored lead.
	 *
	 * @param array<string,mixed> $lead Row from wp_threebest_leads.
	 * @return array<string,mixed>
	 */
	public static function build( array $lead ): array {
		$get = static function ( string $key ) use ( $lead ): string {
			return isset( $lead[ $key ] ) && null !== $lead[ $key ] ? (string) $lead[ $key ] : '';
		};

		$service     = Services_Repository::by_slug( $get( 'service_slug' ) );
		$subcategory = null !== $service
			? Services_Repository::subcategory( $service, $get( 'subcategory_id' ) )
			: null;

		$location = Catalog::by_slug( $get( 'location_slug' ) );

		return array(
			'payloadVersion' => self::PAYLOAD_VERSION,

			/*
			 * The stable identifier. Sent so the receiving Zap can deduplicate:
			 * if a timeout causes a retry that Zapier already received, both
			 * copies carry the same leadId.
			 */
			'leadId'         => $get( 'lead_uid' ),
			'createdAt'      => self::iso( $get( 'created_at' ) ),

			'service'        => array(
				'id'   => $get( 'service_id' ),
				'slug' => $get( 'service_slug' ),
				'name' => null !== $service ? (string) $service['name'] : '',
			),

			'subcategory'    => array(
				'id'   => $get( 'subcategory_id' ),
				'name' => null !== $subcategory ? (string) $subcategory['name'] : '',
			),

			'location'       => array(
				'slug'      => $get( 'location_slug' ),
				'label'     => null !== $location ? Labels::label( $location ) : '',
				'city'      => $get( 'city' ),
				'state'     => null !== $location ? $location->state : '',
				'stateCode' => $get( 'state_code' ),
				// The ZIP the visitor actually searched with. Never part of the
				// URL, but exactly what a dispatcher needs.
				'zip'       => $get( 'zip' ),
				'latitude'  => $get( 'latitude' ),
				'longitude' => $get( 'longitude' ),
				'type'      => $get( 'location_type' ),
				'sourceType' => $get( 'source_type' ),
			),

			'contact'        => array(
				'fullName' => $get( 'full_name' ),
				'email'    => $get( 'email' ),
				// Ten digits as validated, plus E.164 so a dialler needs no
				// guesswork about the country code.
				'phone'    => $get( 'phone' ),
				'phoneE164' => '' !== $get( 'phone' ) ? '+1' . $get( 'phone' ) : '',
				'address'  => $get( 'address' ),
			),

			'verification'   => array(
				'phoneVerified' => (bool) ( $lead['phone_verified'] ?? false ),
				'verifiedAt'    => self::iso( $get( 'phone_verified_at' ) ),
			),

			/*
			 * The consent record travels with the lead. A professional
			 * receiving this needs to be able to show what the person agreed
			 * to, when, and from where — the version alone is not enough, so
			 * the wording goes too.
			 */
			'consent'        => array(
				'given'     => (bool) ( $lead['consent'] ?? false ),
				'version'   => $get( 'consent_version' ),
				'text'      => $get( 'consent_text' ),
				'timestamp' => self::iso( $get( 'consent_at' ) ),
				'ip'        => $get( 'consent_ip' ),
			),

			'attribution'    => array(
				'sourcePage' => $get( 'source_page' ),
				'referrer'   => $get( 'referrer' ),
				'utmSource'   => $get( 'utm_source' ),
				'utmMedium'   => $get( 'utm_medium' ),
				'utmCampaign' => $get( 'utm_campaign' ),
				'utmTerm'     => $get( 'utm_term' ),
				'utmContent'  => $get( 'utm_content' ),
			),
		);
	}

	/**
	 * Converts a stored UTC datetime to ISO 8601.
	 *
	 * Everything is written in UTC, so the offset is explicit rather than
	 * depending on whatever timezone the recipient assumes.
	 *
	 * @param string $value Stored datetime, or ''.
	 */
	private static function iso( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false !== $timestamp ? gmdate( 'c', $timestamp ) : '';
	}
}
