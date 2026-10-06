<?php
/**
 * Curated US locations.
 *
 * Ported from src/data/locations.ts.
 *
 * Every record is built by Normalizer::create() — the same canonicaliser
 * geocoder results go through — so a curated slug can never drift from the one
 * search produces for the same place.
 *
 * This is configuration, not a table. The slugs here are the SEO surface, and
 * putting them in the database would let an editor rename one and silently
 * break a published URL.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

return array(

	/*
	 * Canonical areas — each one is a routable page.
	 */
	'states' => array(
		'New Jersey',
		'New York',
		'Pennsylvania',
		'Texas',
		'Florida',
		'California',
		'Arizona',
		'Illinois',
		'Massachusetts',
		'Georgia',
		'Colorado',
		'Washington',
		'North Carolina',
	),

	'cities' => array(
		array( 'New York', 'NY' ),
		array( 'Newark', 'NJ' ),
		array( 'Jersey City', 'NJ' ),
		array( 'Princeton', 'NJ' ),
		array( 'Hoboken', 'NJ' ),
		array( 'Philadelphia', 'PA' ),
		array( 'Dallas', 'TX' ),
		array( 'Houston', 'TX' ),
		array( 'Austin', 'TX' ),
		array( 'Miami', 'FL' ),
		array( 'Orlando', 'FL' ),
		array( 'Los Angeles', 'CA' ),
		array( 'San Diego', 'CA' ),
		array( 'Phoenix', 'AZ' ),
		array( 'Chicago', 'IL' ),
		array( 'Boston', 'MA' ),
		array( 'Atlanta', 'GA' ),
		array( 'Denver', 'CO' ),
		array( 'Seattle', 'WA' ),
		array( 'Charlotte', 'NC' ),
	),

	/*
	 * Curated ZIP codes — search entries, not pages.
	 *
	 * Each carries the slug of its city, so it never becomes a page of its own.
	 * This list is also the override for ZIPs a geocoder cannot place in a city:
	 * OpenStreetMap has 90001 only at county level, so without this entry it
	 * would resolve to nothing routable.
	 */
	'zips'   => array(
		array( '07030', 'Hoboken', 'NJ' ),
		array( '07102', 'Newark', 'NJ' ),
		array( '08540', 'Princeton', 'NJ' ),
		array( '10001', 'New York', 'NY' ),
		array( '19103', 'Philadelphia', 'PA' ),
		array( '75201', 'Dallas', 'TX' ),
		array( '33101', 'Miami', 'FL' ),
		array( '85001', 'Phoenix', 'AZ' ),
		array( '60601', 'Chicago', 'IL' ),
		array( '98101', 'Seattle', 'WA' ),
		array( '90001', 'Los Angeles', 'CA' ),
	),
);
