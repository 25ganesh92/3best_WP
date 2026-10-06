<?php
/**
 * Regression suite 2 of 3 — functional, integration, delivery, SEO, location,
 * database. Rebuilt for p8 after the Spectra/UAGB deactivation.
 *
 * Parks and restores everything. No SMS, no real webhook, no production
 * credentials. Rows are identified by value, never by "the newest row".
 * The 35 development OTP records are never touched.
 */

require_once __DIR__ . '/bootstrap.php';

use ThreeBest\Core\Delivery\Delivery_Payload;
use ThreeBest\Core\Delivery\Delivery_Provider;
use ThreeBest\Core\Delivery\Delivery_Queue;
use ThreeBest\Core\Delivery\Delivery_Result;
use ThreeBest\Core\Delivery\Delivery_Scheduler;
use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Leads\Lead_Repository;
use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Location\Normalizer;
use ThreeBest\Core\Schema;
use ThreeBest\Core\Security\Throttle;
use ThreeBest\Core\Seo\Service_Locations;
use ThreeBest\Core\Services\Services_Repository;
use ThreeBest\Core\Settings;

global $wpdb;

$leads_table = Schema::table( Schema::LEADS );
$otp_table   = Schema::table( Schema::OTP_VERIFICATIONS );
$combo_table = Schema::table( Schema::SERVICE_LOCATIONS );

$leads_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" );
$otp_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" );
$parked       = get_option( Settings::OPTION, array() );
$parked_rows  = $wpdb->get_results( "SELECT lead_uid, delivery_status, delivery_attempts FROM {$leads_table}", ARRAY_A );

function purge(): void {
	global $wpdb, $leads_table, $otp_table;
	$wpdb->query( "DELETE FROM {$leads_table} WHERE email LIKE 'p8a+%@example.com'" );
	$wpdb->query( "DELETE FROM {$otp_table} WHERE phone LIKE '20155508%'" );
}
function restore(): void {
	global $parked, $wpdb, $leads_table, $parked_rows;
	purge();
	update_option( Settings::OPTION, $parked, true );
	Delivery_Queue::set_provider( null );
	\ThreeBest\Core\Otp\Otp_Provider_Factory::set_provider( null );
	Throttle::set_store( null );
	foreach ( $parked_rows as $row ) {
		$wpdb->update( $leads_table,
			array( 'delivery_status' => $row['delivery_status'], 'delivery_attempts' => $row['delivery_attempts'] ),
			array( 'lead_uid' => $row['lead_uid'] ) );
	}
}
register_shutdown_function( 'restore' );
purge();

$outbound = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$outbound ) {
	if ( 0 === strpos( (string) $url, home_url( '/' ) ) ) { return $pre; }
	$outbound[] = $url;
	return new WP_Error( 'blocked', 'Blocked by the p8 harness.' );
}, 1, 3 );

$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
wp_set_current_user( $admin_id );
$nonce = wp_create_nonce( 'wp_rest' );

function call( string $route, array $body, ?string $n = null, string $method = 'POST' ): array {
	$r = new WP_REST_Request( $method, '/threebest/v1' . $route );
	$r->set_header( 'Content-Type', 'application/json' );
	if ( null !== $n ) { $r->set_header( 'X-WP-Nonce', $n ); }
	if ( 'GET' === $method ) { foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); } }
	else { $r->set_body( wp_json_encode( $body ) ); }
	$res = rest_do_request( $r );
	return array( $res->get_status(), (array) $res->get_data() );
}
function fetch( string $path ): array {
	/*
	 * One retry, and ONLY when the server gave no response at all. A wrong
	 * status code is never retried - that is the thing being tested. A long run
	 * of sequential requests occasionally loses a connection on this host, which
	 * is an environment artefact rather than a result.
	 */
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$r = wp_remote_get( home_url( $path ), array( 'timeout' => 25, 'redirection' => 0 ) );

		if ( ! is_wp_error( $r ) ) {
			return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), wp_remote_retrieve_headers( $r )->getAll() );
		}
	}

	return array( 0, '', array() );
}
function clear_buckets(): void {
	$ip = \ThreeBest\Core\Sanitizer::client_ip();
	foreach ( Throttle::buckets() as $b ) {
		Throttle::reset( $b, $ip );
		for ( $i = 80; $i <= 89; $i++ ) { Throttle::reset( $b, 'phone:201555' . sprintf( '%04d', 800 + $i ) ); }
		for ( $i = 80; $i <= 99; $i++ ) { Throttle::reset( $b, 'phone:20155508' . sprintf( '%02d', $i % 100 ) ); }
	}
}

/* =================================================================== */
echo "\n--- SPECTRA DEACTIVATION (p1 regression) ---\n";
$active = (array) get_option( 'active_plugins' );
check( 'Spectra is deactivated', ! in_array( 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', $active, true ), implode( ',', $active ) );
check( '3best-core is still active', in_array( '3best-core/3best-core.php', $active, true ) );
check( 'exactly one plugin active', 1 === count( $active ), (string) count( $active ) );
check( 'the 3best theme is still active', '3best' === get_option( 'stylesheet' ) );

/* =================================================================== */
echo "\n--- FUNCTIONAL: PAGES AND THEME ---\n";
foreach ( array(
	'/' => 'Find Your 3 Best', '/services/' => 'tb-services__service', '/about/' => '',
	'/how-it-works/' => '', '/contact/' => '', '/privacy-policy/' => '', '/terms-of-service/' => '',
) as $path => $marker ) {
	list( $code, $html ) = fetch( $path );
	check( "{$path} returns 200", 200 === $code, (string) $code );
	check( "  {$path} contains no uagb reference", false === stripos( $html, 'uagb' ) && false === stripos( $html, 'uag-' ) );
	if ( '' !== $marker ) { check( "  {$path} renders its content", false !== strpos( $html, $marker ) ); }
}
list( , $home ) = fetch( '/' );
check( 'homepage has the search form', 1 === substr_count( $home, 'data-tb-search-form' ) );
check( 'homepage loads the search script only', false !== strpos( $home, 'threebestSearch' ) && false === strpos( $home, 'threebestLead' ) );
check( 'header navigation present', false !== strpos( $home, 'How It Works' ) && false !== strpos( $home, 'About Us' ) );
check( 'footer present', false !== stripos( $home, '</footer>' ) );

list( , $services ) = fetch( '/services/' );
check( '16 services listed', 16 === substr_count( $services, 'tb-services__service"' ), (string) substr_count( $services, 'tb-services__service"' ) );
check( '4 categories in the catalogue', 4 === count( Services_Repository::categories() ) );
$subcats = 0;
foreach ( Services_Repository::all() as $s ) { $subcats += count( $s['subcategories'] ); }
check( '64 subcategories in the catalogue', 64 === $subcats, (string) $subcats );
list( $code, $missing ) = fetch( '/no-such-page/' );
check( '404 returns 404 and uses the theme template', 404 === $code && false !== strpos( $missing, 'tb-notfound' ) );

/* =================================================================== */
echo "\n--- LOCATION SYSTEM ---\n";
check( 'catalogue has areas', count( Catalog::areas() ) > 0, (string) count( Catalog::areas() ) );
check( 'city lookup', null !== Catalog::by_slug( 'newark-nj' ) );
check( 'state lookup', null !== Catalog::by_slug( 'new-jersey' ) );
check( 'ZIP lookup', null !== Catalog::by_zip( '07102' ) );
check( 'unknown ZIP returns null, never a guess', null === Catalog::by_zip( '99999' ) );
$newark = Catalog::by_slug( 'newark-nj' );
check( 'city/state/code resolved', 'Newark' === $newark->city && 'New Jersey' === $newark->state && 'NJ' === $newark->state_code );
check( 'label built correctly', 'Newark, NJ' === Labels::label( $newark ) );
check( 'no fabricated ZIP on a city record', '' === $newark->zip );
check( 'kebab folds diacritics without ext-intl', 'san-jose' === Normalizer::kebab( 'San Jos' . chr(0xC3) . chr(0xA9) ), Normalizer::kebab( 'San Jos' . chr(0xC3) . chr(0xA9) ) );
check( 'apostrophe becomes a separator, as the React reference does', 'coeur-d-alene' === Normalizer::kebab( "Coeur d'Alene" ), Normalizer::kebab( "Coeur d'Alene" ) );
check( "O'Fallon matches the reference", 'o-fallon' === Normalizer::kebab( "O'Fallon" ), Normalizer::kebab( "O'Fallon" ) );
check( 'kebab collapses punctuation', 'winston-salem' === Normalizer::kebab( 'Winston-Salem' ) );
list( $code, $data ) = call( '/locations', array( 'q' => 'newark' ), $nonce, 'GET' );
check( 'location search returns results', 200 === $code && ! empty( $data['results'] ) );
check( 'results carry display parts', isset( $data['results'][0]['primary'], $data['results'][0]['secondary'] ) );
check( 'results carry no provider metadata', ! isset( $data['results'][0]['provider'], $data['results'][0]['raw'] ) );
list( $code ) = call( '/locations', array( 'q' => 'a' ), $nonce, 'GET' );
check( 'a too-short query is refused', 400 === $code, (string) $code );

/* =================================================================== */
echo "\n--- DYNAMIC SEO URLS ---\n";
$approved = array_column( Service_Locations::indexable(), 'combo_slug' );
check( '14 approved combinations', 14 === count( $approved ), (string) count( $approved ) );
$ok = 0;
foreach ( $approved as $slug ) { list( $c ) = fetch( "/{$slug}/" ); if ( 200 === $c ) { $ok++; } }
check( 'all 14 render', 14 === $ok, "{$ok}/14" );
foreach ( array(
	'/plumber-in-newark-new-jersey/' => '/plumber-in-newark-nj/',
	'/plumber-in-07102/'             => '/plumber-in-newark-nj/',
	'/plumber-in-07030/'             => '/plumber-in-hoboken-nj/',
) as $from => $to ) {
	list( $c, , $h ) = fetch( $from );
	check( "{$from} 301s to {$to}", 301 === $c && home_url( $to ) === ( $h['location'] ?? '' ), $c . ' ' . ( $h['location'] ?? '' ) );
}
foreach ( array( '/wizard-in-newark-nj/', '/plumber-in-atlantis-zz/', '/plumber-in-99999/', '/plumber-in-/', '/-in-newark-nj/' ) as $bad ) {
	list( $c ) = fetch( $bad );
	check( "{$bad} 404s", 404 === $c, (string) $c );
}
list( $c ) = fetch( '/plumber-in-denver-co/' );
check( 'a valid but unapproved combination still renders', 200 === $c, (string) $c );
check( 'and no row was created for it', null === Service_Locations::record( 'plumber-in-denver-co' ) );
check( 'still exactly 14 approved', 14 === count( Service_Locations::indexable() ) );
check( 'existing WordPress pages still win over the rewrite', 200 === fetch( '/services/' )[0] );

/* =================================================================== */
echo "\n--- SEO AND INDEXABILITY ---\n";
list( , $landing ) = fetch( '/plumber-in-newark-nj/' );
check( 'exactly one title', 1 === substr_count( $landing, '<title>' ) );
check( 'exactly one canonical', 1 === substr_count( $landing, 'rel="canonical"' ) );
check( 'exactly one description', 1 === substr_count( $landing, 'name="description"' ) );
check( 'exactly one robots tag', 1 === substr_count( $landing, "name='robots'" ) );
check( 'canonical is the clean URL', false !== strpos( $landing, 'href="' . home_url( '/plumber-in-newark-nj/' ) . '"' ) );
preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $landing, $m );
check( 'three JSON-LD graphs', 3 === count( $m[1] ) );
$types = array();
foreach ( $m[1] as $json ) { $d = json_decode( $json, true ); $types[] = $d['@type'] ?? '?'; }
check( 'BreadcrumbList + Service + FAQPage', array( 'BreadcrumbList', 'Service', 'FAQPage' ) === $types, implode( ',', $types ) );
$ld = implode( '', $m[1] );
foreach ( array( 'aggregateRating', 'reviewCount', 'telephone', 'streetAddress', '"price"' ) as $fab ) {
	check( "no fabricated {$fab} in structured data", false === stripos( $ld, $fab ) );
}
$blog_public = get_option( 'blog_public' );
update_option( 'blog_public', '1' );
$indexed = 0;
foreach ( $approved as $slug ) {
	list( , $h ) = fetch( "/{$slug}/" );
	if ( 1 === preg_match( "#<meta name='robots' content='([^']+)'#", $h, $rm )
		&& false !== strpos( $rm[1], 'index, follow' ) && false === strpos( $rm[1], 'noindex' ) ) { $indexed++; }
}
check( 'with blog_public on, all 14 approved are index,follow', 14 === $indexed, "{$indexed}/14" );
list( , $unapproved ) = fetch( '/plumber-in-denver-co/' );
preg_match( "#<meta name='robots' content='([^']+)'#", $unapproved, $rm2 );
check( 'an unapproved combination is noindex', false !== strpos( $rm2[1] ?? '', 'noindex' ), $rm2[1] ?? '' );
update_option( 'blog_public', $blog_public );
check( 'blog_public restored to its original value', (string) $blog_public === (string) get_option( 'blog_public' ), 'now ' . var_export( get_option( 'blog_public' ), true ) );

/* =================================================================== */
echo "\n--- INTEGRATION: LANDING -> OTP -> LEAD -> DELIVERY ---\n";
clear_buckets();
Settings::update( array( 'otp_provider' => 'dev' ) );
\ThreeBest\Core\Otp\Otp_Provider_Factory::set_provider( null );
$log = (string) ini_get( 'error_log' );
clearstatcache();
$at = file_exists( $log ) ? (int) filesize( $log ) : 0;

$form = array(
	'serviceSlug' => 'plumber', 'locationSlug' => 'newark-nj',
	'subcategoryId' => 'plumbing-water-heater-repair',
	'fullName' => 'P8 Regression', 'email' => 'p8a+flow@example.com',
	'phone' => '(201) 555-0881', 'address' => '8 Test Street, Newark, NJ 07102',
	'sourcePage' => '/plumber-in-newark-nj/', 'utm_source' => 'google', 'utm_medium' => 'cpc',
	'consent' => true,
);

list( , $page ) = fetch( '/plumber-in-newark-nj/' );
check( 'landing page carries exactly one lead form', 1 === substr_count( $page, 'data-tb-lead-form' ) );
check( 'form context comes from the server', false !== strpos( $page, 'data-service="plumber"' ) && false !== strpos( $page, 'data-location="newark-nj"' ) );
check( 'exact consent wording rendered', false !== strpos( $page, esc_html( Consent::TEXT ) ) );

list( $c, $d ) = call( '/otp/request', $form, $nonce );
check( 'OTP requested', 200 === $c, (string) $c . ' ' . ( $d['message'] ?? '' ) );
check( 'smsSent is false, never pretended', false === ( $d['smsSent'] ?? null ) );
$otp_id = (string) ( $d['otpId'] ?? '' );
clearstatcache();
$tail  = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $at ) : '';
$plain = 1 === preg_match( '/\.\.\.0881 is (\d{6})/', $tail, $mm ) ? $mm[1] : '';
check( 'development provider issued a code', 1 === preg_match( '/^\d{6}$/', $plain ) );
list( $c, $v ) = call( '/otp/verify', array( 'otpId' => $otp_id, 'code' => $plain ), $nonce );
check( 'code verified', 200 === $c, (string) $c );
$token = (string) ( $v['verificationToken'] ?? '' );
list( $c, $lead_res ) = call( '/leads', array_merge( $form, array( 'verificationToken' => $token ) ), $nonce );
check( 'lead accepted', 200 === $c, (string) $c . ' ' . ( $lead_res['message'] ?? '' ) );
$ref = (string) ( $lead_res['leadId'] ?? '' );
check( 'a reference was returned', '' !== $ref );

$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE lead_uid = %s", $ref ), ARRAY_A );
check( 'a lead row exists for that reference', is_array( $lead ) );
if ( ! is_array( $lead ) ) { echo "  !! cannot continue delivery tests without a lead\n"; restore(); tb_report( 'SUITE 2' ); }
$lead_id = (int) $lead['id'];
check( 'stored with server-resolved service and location', 'plumber' === $lead['service_slug'] && 'newark-nj' === $lead['location_slug'] );
check( 'stored with resolved city and state', 'Newark' === $lead['city'] && 'NJ' === $lead['state_code'] );
check( 'consent recorded in full', Consent::VERSION === $lead['consent_version'] && Consent::TEXT === $lead['consent_text'] );
check( 'phone marked verified', '1' === (string) $lead['phone_verified'] );
check( 'attribution captured', 'google' === $lead['utm_source'] && 'cpc' === $lead['utm_medium'] );
check( 'queued pending, zero attempts - persist first, deliver second', 'pending' === $lead['delivery_status'] && 0 === (int) $lead['delivery_attempts'] );

$payload = Delivery_Payload::build( $lead );
$payload_json = wp_json_encode( $payload );
check( 'payload carries the lead reference', false !== strpos( $payload_json, $ref ) );
check( 'payload carries the consent record', false !== strpos( $payload_json, Consent::VERSION ) );
foreach ( array( 'zapier', 'twilio', 'auth', 'secret', 'api_key', 'password', 'code_hash', 'verify_token' ) as $leak ) {
	check( "payload contains no {$leak}", false === stripos( $payload_json, $leak ) );
}

$mock = new class implements Delivery_Provider {
	public static array $received = array();
	public static string $mode = 'ok';
	public function name(): string { return 'mock'; }
	public function is_configured(): bool { return true; }
	public function configuration_error(): string { return ''; }
	public function send( array $payload ): Delivery_Result {
		self::$received[] = $payload;
		if ( 'retry' === self::$mode ) { return Delivery_Result::retryable( 'http_503', 503 ); }
		if ( 'permanent' === self::$mode ) { return Delivery_Result::permanent( 'http_400', 400 ); }
		return Delivery_Result::delivered( 200 );
	}
};
Delivery_Queue::set_provider( $mock );

Settings::update( array( 'delivery_enabled' => false ) );
$mock::$received = array();
$summary = Delivery_Queue::process();
check( 'with delivery disabled nothing is sent', 0 === count( $mock::$received ) );
check( 'the lead is still pending', 'pending' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivery_status FROM {$leads_table} WHERE lead_uid = %s", $ref ) ) );

$wpdb->query( $wpdb->prepare( "UPDATE {$leads_table} SET delivery_status='delivered' WHERE lead_uid <> %s", $ref ) );
Settings::update( array( 'delivery_enabled' => true ) );
$mock::$mode = 'ok'; $mock::$received = array();
$summary = Delivery_Queue::process();
check( 'queue delivered exactly one lead', 1 === (int) ( $summary['delivered'] ?? 0 ), wp_json_encode( $summary ) );
check( 'the provider received one payload', 1 === count( $mock::$received ) );
check( 'the payload was for our lead', false !== strpos( wp_json_encode( $mock::$received[0] ), $ref ) );
$after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE lead_uid = %s", $ref ), ARRAY_A );
check( 'lead marked delivered', 'delivered' === $after['delivery_status'], (string) $after['delivery_status'] );
check( 'attempt counted', 1 === (int) $after['delivery_attempts'] );
check( 'completion timestamp set', null !== $after['delivery_completed_at'] );
check( 'no response body stored', '' === (string) $after['delivery_last_error'] );

$mock::$received = array();
Delivery_Queue::process();
check( 'a delivered lead is never sent again', 0 === count( $mock::$received ) );
Lead_Repository::requeue( $lead_id );
check( 'requeue refuses to resend an already-delivered lead', 'delivered' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivery_status FROM {$leads_table} WHERE lead_uid = %s", $ref ) ) );

function reset_to_pending( string $ref ): void {
	global $wpdb, $leads_table;
	$wpdb->query( $wpdb->prepare( "UPDATE {$leads_table} SET delivery_status='pending', delivery_attempts=0, delivery_last_attempt_at=NULL, delivery_last_error='', delivery_completed_at=NULL WHERE lead_uid=%s", $ref ) );
}

reset_to_pending( $ref );
$mock::$mode = 'retry'; $mock::$received = array();
Delivery_Queue::process();
$after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE lead_uid = %s", $ref ), ARRAY_A );
check( 'a 503 leaves the lead queued, not failed', 'pending' === $after['delivery_status'], (string) $after['delivery_status'] );
check( 'the attempt was counted', 1 === (int) $after['delivery_attempts'] );
check( 'the attempt time was recorded, so backoff can be computed', null !== $after['delivery_last_attempt_at'] );
check( 'it was not marked delivered', null === $after['delivery_completed_at'] );
check( 'the failure reason is a short code, not a body', 'http_503' === $after['delivery_last_error'], (string) $after['delivery_last_error'] );
$mock::$received = array();
Delivery_Queue::process();
check( 'backoff holds the lead back from an immediate retry', 0 === count( $mock::$received ) );

reset_to_pending( $ref );
$mock::$mode = 'permanent'; $mock::$received = array();
Delivery_Queue::process();
$after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE lead_uid = %s", $ref ), ARRAY_A );
check( 'a 400 fails permanently rather than retrying forever', 'failed' === $after['delivery_status'], (string) $after['delivery_status'] );
check( 'the lead row still exists after a permanent failure', is_array( $after ) );

$wpdb->query( $wpdb->prepare( "UPDATE {$leads_table} SET delivery_status='processing', delivery_last_attempt_at=%s WHERE lead_uid=%s", gmdate( 'Y-m-d H:i:s', time() - 7200 ), $ref ) );
$reclaimed = Lead_Repository::reclaim_stale_processing( 900 );
check( 'a lead stuck in processing is reclaimed', $reclaimed >= 1, (string) $reclaimed );
check( 'and is pending again', 'pending' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT delivery_status FROM {$leads_table} WHERE lead_uid = %s", $ref ) ) );

Settings::update( array( 'delivery_enabled' => false ) );
Delivery_Queue::set_provider( null );
check( 'cron event is scheduled exactly once', 1 === Delivery_Scheduler::scheduled_count() );
check( 'cron interval is registered', isset( wp_get_schedules()['threebest_five_minutes'] ) );
$forbidden = array_filter( $outbound, fn( $u ) => false !== stripos( $u, 'zapier' ) || false !== stripos( $u, 'twilio' ) );
check( 'no call to Zapier or Twilio in the whole run', array() === $forbidden, implode( ',', $forbidden ) );

/* =================================================================== */
echo "\n--- DATABASE ---\n";
check( 'four plugin tables', 4 === count( Schema::table_names() ) );
foreach ( Schema::status() as $row ) { check( "table present: {$row['table']}", (bool) $row['exists'] ); }
check( 'schema version 1.2.0 installed', '1.2.0' === Schema::installed_version(), Schema::installed_version() );
check( 'no pending migration', ! Schema::needs_upgrade() );
check( 'tables use the WordPress prefix', 0 === strpos( Schema::table( Schema::LEADS ), $wpdb->prefix ) );
$charset = (string) $wpdb->get_var( "SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$leads_table}'" );
check( 'leads table uses a utf8mb4 collation', 0 === strpos( $charset, 'utf8mb4' ), $charset );
$names = array_unique( array_column( $wpdb->get_results( "SHOW INDEX FROM {$leads_table}", ARRAY_A ), 'Key_name' ) );
foreach ( array( 'PRIMARY', 'lead_uid', 'delivery_queue', 'email', 'phone' ) as $k ) {
	check( "leads index present: {$k}", in_array( $k, $names, true ) );
}
$combo_idx = array_unique( array_column( $wpdb->get_results( "SHOW INDEX FROM {$combo_table}", ARRAY_A ), 'Key_name' ) );
check( 'combo_slug is unique', in_array( 'combo_slug', $combo_idx, true ) );
$thr_idx = array_unique( array_column( $wpdb->get_results( "SHOW INDEX FROM " . Schema::table( Schema::THROTTLE ), ARRAY_A ), 'Key_name' ) );
check( 'throttle table keyed on counter_key', in_array( 'PRIMARY', $thr_idx, true ) );
$tables_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" );
Schema::install();
check( 'dbDelta is idempotent - reinstall changes nothing', $tables_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" ) );

/* =================================================================== */
echo "\n--- CLEANUP AND REGRESSION ---\n";
restore();
clear_buckets();
check( 'test leads removed', $leads_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ), (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ) );
check( 'the 35 development OTP records are untouched', $otp_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" ), (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" ) );
check( 'pre-existing lead delivery state restored', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE delivery_status='pending' AND delivery_attempts=0" ) );
check( 'settings restored', $parked === get_option( Settings::OPTION, array() ) );
check( 'delivery disabled again', ! Delivery_Queue::is_enabled() );
check( '14 combinations, 14 indexable', 14 === count( Service_Locations::indexable() ) );
check( 'blog_public still 0', '0' === (string) get_option( 'blog_public' ) );

tb_report( 'SUITE 2' );
