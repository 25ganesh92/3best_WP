<?php
/**
 * Regression suite 3 of 3 — security, OTP, admin.
 * Rebuilt for p8 after the Spectra/UAGB deactivation.
 *
 * Parks and restores everything; sends nothing anywhere. The 35 development
 * OTP records are never touched.
 */

require_once __DIR__ . '/bootstrap.php';

use ThreeBest\Core\Admin\Leads_Screen;
use ThreeBest\Core\Admin\Seo_Pages_Screen;
use ThreeBest\Core\Admin\Settings_Screen;
use ThreeBest\Core\Admin\Status_Page;
use ThreeBest\Core\Delivery\Delivery_Queue;
use ThreeBest\Core\Otp\Dev_Otp_Provider;
use ThreeBest\Core\Otp\Otp_Provider_Factory;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Schema;
use ThreeBest\Core\Security\Db_Throttle_Store;
use ThreeBest\Core\Security\Throttle;
use ThreeBest\Core\Security\Throttle_Store;
use ThreeBest\Core\Security\Throttle_Unavailable;
use ThreeBest\Core\Settings;

global $wpdb;

$leads_table = Schema::table( Schema::LEADS );
$otp_table   = Schema::table( Schema::OTP_VERIFICATIONS );
$combo_table = Schema::table( Schema::SERVICE_LOCATIONS );

$leads_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" );
$otp_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" );
$parked        = get_option( Settings::OPTION, array() );
$parked_combos = $wpdb->get_results( "SELECT * FROM {$combo_table}", ARRAY_A );

function purge(): void {
	global $wpdb, $leads_table, $otp_table;
	$wpdb->query( "DELETE FROM {$leads_table} WHERE email LIKE 'p8b+%@example.com'" );
	$wpdb->query( "DELETE FROM {$otp_table} WHERE phone LIKE '20155509%'" );
}
function restore(): void {
	global $parked, $wpdb, $combo_table, $parked_combos;
	purge();
	update_option( Settings::OPTION, $parked, true );
	Throttle::set_store( null );
	Otp_Provider_Factory::set_provider( null );
	$wpdb->query( "DELETE FROM {$combo_table}" );
	foreach ( $parked_combos as $row ) { $wpdb->insert( $combo_table, $row ); }
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
$ip = Sanitizer::client_ip();

function call( string $route, array $body, ?string $n = null, string $method = 'POST' ): array {
	$r = new WP_REST_Request( $method, '/threebest/v1' . $route );
	$r->set_header( 'Content-Type', 'application/json' );
	if ( null !== $n ) { $r->set_header( 'X-WP-Nonce', $n ); }
	if ( 'GET' === $method ) { foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); } }
	else { $r->set_body( wp_json_encode( $body ) ); }
	$res = rest_do_request( $r );
	return array( $res->get_status(), (array) $res->get_data() );
}
function clear_buckets(): void {
	global $ip;
	foreach ( Throttle::buckets() as $b ) {
		Throttle::reset( $b, $ip );
		for ( $i = 0; $i <= 99; $i++ ) { Throttle::reset( $b, 'phone:20155509' . sprintf( '%02d', $i ) ); }
	}
}
function expect_die( callable $fn ): bool {
	$h = static fn() => static function ( $m ) { throw new RuntimeException( 'wp_die' ); };
	add_filter( 'wp_die_handler', $h, 99 );
	$died = false;
	try { $fn(); } catch ( RuntimeException $e ) { $died = true; } catch ( Throwable $e ) { $died = false; }
	finally { remove_filter( 'wp_die_handler', $h, 99 ); }
	return $died;
}
function render( callable $fn ): string { ob_start(); $fn(); return (string) ob_get_clean(); }

clear_buckets();
$nonce = wp_create_nonce( 'wp_rest' );

/* =================================================================== */
echo "\n--- ACCESS CONTROL ---\n";
$routes = rest_get_server()->get_routes();
$ours   = array_values( array_filter( array_keys( $routes ), fn( $r ) => 0 === strpos( $r, '/threebest/v1/' ) ) );
check( 'exactly five 3Best endpoints', 5 === count( $ours ), implode( ' ', $ours ) );
$open = array();
foreach ( $ours as $route ) {
	foreach ( $routes[ $route ] as $h ) {
		if ( empty( $h['permission_callback'] ) || true === $h['permission_callback'] ) { $open[] = $route; }
	}
}
check( 'none has an open permission callback', array() === $open, implode( ',', $open ) );

wp_set_current_user( 0 );
check( 'anonymous cannot read /status', in_array( call( '/status', array(), $nonce, 'GET' )[0], array( 401, 403 ), true ) );
check( 'public endpoint refuses a missing nonce', 403 === call( '/locations', array( 'q' => 'newark' ), null, 'GET' )[0] );
list( $fc, $fd ) = call( '/locations', array( 'q' => 'newark' ), 'forged', 'GET' );
check( 'public endpoint refuses a forged nonce', 403 === $fc, (string) $fc );
check( 'and names the reason so a client can refresh once', 'invalid_nonce' === ( $fd['code'] ?? '' ), (string) ( $fd['code'] ?? '' ) );
$_REQUEST['_wpnonce'] = 'bad'; $_POST['_wpnonce'] = 'bad';
check( 'anonymous cannot run the delivery queue', expect_die( array( \ThreeBest\Core\Admin\Delivery_Admin::class, 'handle_run' ) ) );
check( 'anonymous cannot save settings', expect_die( array( Settings_Screen::class, 'handle_save' ) ) );
check( 'anonymous cannot edit an SEO combination', expect_die( array( Seo_Pages_Screen::class, 'handle_save' ) ) );
foreach ( array( 'overview' => array( Status_Page::class, 'render' ), 'leads' => array( Leads_Screen::class, 'render' ),
	'SEO' => array( Seo_Pages_Screen::class, 'render' ), 'settings' => array( Settings_Screen::class, 'render' ) ) as $label => $fn ) {
	check( "anonymous cannot view the {$label} screen", expect_die( $fn ) );
}
$_POST = array(); $_REQUEST = array();
wp_set_current_user( $admin_id );
$nonce = wp_create_nonce( 'wp_rest' );
check( 'administrator can read /status', 200 === call( '/status', array(), $nonce, 'GET' )[0] );
list( , $st ) = call( '/status', array(), $nonce, 'GET' );
check( '/status exposes version only, no host detail', array( 'success', 'status', 'apiVersion', 'pluginVersion' ) === array_keys( $st ), implode( ',', array_keys( $st ) ) );
check( 'wrong HTTP method on /leads is refused', in_array( call( '/leads', array(), $nonce, 'GET' )[0], array( 404, 405 ), true ) );

/* =================================================================== */
echo "\n--- RATE LIMITING ---\n";
Throttle::set_store( null );
check( 'atomic store is active', Throttle::store() instanceof Db_Throttle_Store, get_class( Throttle::store() ) );
foreach ( array(
	'location_search' => array( 60, 60 ), 'otp_request' => array( 5, 3600 ),
	'otp_request_phone' => array( 5, 86400 ), 'otp_resend_phone' => array( 1, 30 ),
	'otp_verify' => array( 10, 900 ), 'otp_verify_code' => array( 5, 900 ),
	'lead_submit' => array( 10, 3600 ), 'default' => array( 120, 60 ),
) as $b => $exp ) {
	check( "{$b} = {$exp[0]} per {$exp[1]}s", $exp === Throttle::limits_for( $b ), implode( '/', Throttle::limits_for( $b ) ) );
}
$k = 'p8-' . wp_generate_password( 8, false );
for ( $i = 1; $i <= 11; $i++ ) { $r = Throttle::check( 'lead_submit', $k ); }
check( 'the 11th request is denied', ! $r->allowed, 'count=' . $r->count );
check( 'denial carries a real retry_after', $r->retry_after > 0, (string) $r->retry_after );
check( 'peek does not increment', 11 === Throttle::check( 'lead_submit', $k, false )->count );
Throttle::reset( 'lead_submit', $k );
check( 'reset clears the counter', 0 === Throttle::check( 'lead_submit', $k, false )->count );
check( 'counter keys are stored as opaque digests only', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Schema::table( Schema::THROTTLE ) . " WHERE counter_key NOT REGEXP '^[0-9a-f]{64}$'" ) );
$a = Throttle::check( 'lead_submit', '' );
$b2 = Throttle::check( 'lead_submit', '' );
check( 'an unidentifiable caller shares one counter, not an unlimited one', $b2->count > $a->count );
Throttle::reset( 'lead_submit', '' );

$broken = new class implements Throttle_Store {
	public function hit( string $k, int $w ): int { throw new Throttle_Unavailable( 'simulated' ); }
	public function peek( string $k ): int { throw new Throttle_Unavailable( 'simulated' ); }
	public function seconds_remaining( string $k ): int { throw new Throttle_Unavailable( 'simulated' ); }
	public function clear( string $k ): void { throw new Throttle_Unavailable( 'simulated' ); }
};
Throttle::set_store( $broken );
check( 'location_search fails OPEN', Throttle::check( 'location_search', $ip )->allowed );
foreach ( array( 'otp_request', 'otp_request_phone', 'otp_resend_phone', 'otp_verify', 'otp_verify_code', 'lead_submit', 'default' ) as $b3 ) {
	check( "{$b3} fails CLOSED", ! Throttle::check( $b3, $ip )->allowed );
}
check( 'the site still answers with a broken limiter', 200 === call( '/locations', array( 'q' => 'newark' ), $nonce, 'GET' )[0] );
list( $bc, $bd ) = call( '/otp/request', array( 'consent' => true, 'phone' => '(201) 555-0990' ), $nonce );
check( 'OTP is refused with a broken limiter', 429 === $bc, (string) $bc );
check( 'and the refusal uses the standard envelope', false === ( $bd['success'] ?? null ) && isset( $bd['message'], $bd['code'] ) );
check( 'no OTP row was created', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table} WHERE phone = '2015550990'" ) );
Throttle::set_store( null );
clear_buckets();

/* =================================================================== */
echo "\n--- CLIENT IDENTITY ---\n";
$real = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';
check( 'X-Forwarded-For ignored with no trusted proxy', '203.0.113.9' === Sanitizer::client_ip(), Sanitizer::client_ip() );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '<script>alert(1)</script>';
check( 'junk forwarded header cannot become an identity', '203.0.113.9' === Sanitizer::client_ip() );
$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
check( 'an invalid REMOTE_ADDR yields no identity', '' === Sanitizer::client_ip() );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
if ( null === $real ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $real; }
check( 'trusted-proxy support is opt-in and currently off', ! defined( 'THREEBEST_TRUSTED_PROXIES' ) );

/* =================================================================== */
echo "\n--- OTP ---\n";
Settings::update( array( 'otp_provider' => 'dev' ) );
Otp_Provider_Factory::set_provider( null );
check( 'development provider active here', Otp_Provider_Factory::get() instanceof Dev_Otp_Provider );
check( 'it sends no SMS', false === Otp_Provider_Factory::get()->sends_sms() );
check( 'it needs the explicit opt-in constant', defined( 'THREEBEST_ALLOW_DEV_OTP' ) && THREEBEST_ALLOW_DEV_OTP );
check( 'and a non-production environment', 'production' !== wp_get_environment_type(), wp_get_environment_type() );

$form = array(
	'serviceSlug' => 'plumber', 'locationSlug' => 'newark-nj',
	'subcategoryId' => 'plumbing-water-heater-repair',
	'fullName' => 'P8 Security', 'email' => 'p8b+a@example.com',
	'address' => '9 Test Street, Newark, NJ 07102', 'consent' => true,
);
$log = (string) ini_get( 'error_log' );
clearstatcache();
$at = file_exists( $log ) ? (int) filesize( $log ) : 0;

check( 'no consent -> 400', 400 === call( '/otp/request', array_merge( $form, array( 'consent' => false, 'phone' => '(201) 555-0901' ) ), $nonce )[0] );
check( 'no row created without consent', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table} WHERE phone = '2015550901'" ) );
list( $c, $d ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0901' ) ), $nonce );
check( 'code requested', 200 === $c, (string) $c );
check( 'smsSent false, never pretended', false === ( $d['smsSent'] ?? null ) );
check( 'phone masked in the response', false === strpos( (string) ( $d['maskedPhone'] ?? '' ), '5550901' ) );
$otp_id = (string) ( $d['otpId'] ?? '' );
clearstatcache();
$tail  = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $at ) : '';
$plain = 1 === preg_match( '/\.\.\.0901 is (\d{6})/', $tail, $m ) ? $m[1] : '';
check( 'a 6-digit code was issued', 1 === preg_match( '/^\d{6}$/', $plain ) );
check( 'the code is not the React demo 123456', '123456' !== $plain );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$otp_table} WHERE otp_uid = %s", $otp_id ), ARRAY_A );
check( 'plaintext code is not stored', '' !== $plain && false === strpos( wp_json_encode( $row ), $plain ) );
check( 'code stored as a keyed digest', 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $row['code_hash'] ) );
check( 'code absent from the REST response', '' !== $plain && false === strpos( wp_json_encode( $d ), $plain ) );
check( 'no token issued yet', '' === (string) $row['verify_token_hash'] );

list( $rc, $rd ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0901' ) ), $nonce );
check( 'immediate resend refused', 429 === $rc, (string) $rc );
$cool = (string) ( $rd['message'] ?? '' );

clear_buckets();
$sent = 1;
for ( $i = 0; $i < 7; $i++ ) {
	Throttle::reset( 'otp_resend_phone', 'phone:2015550901' );
	Throttle::reset( 'otp_request', $ip );
	$wpdb->query( $wpdb->prepare( "UPDATE {$otp_table} SET created_at=%s WHERE phone='2015550901'", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
	if ( 200 === call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0901' ) ), $nonce )[0] ) { $sent++; }
}
check( 'no more than 5 sends per number per day', 5 === $sent, 'sent=' . $sent );
list( , $capd ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0901' ) ), $nonce );
check( 'cap and cooldown give the same message (no enumeration)', $cool === (string) $capd['message'], (string) $capd['message'] );
check( 'the message discloses no count or history', false === stripos( (string) $capd['message'], 'today' ) );

clear_buckets();
$ok = 0;
foreach ( range( 10, 16 ) as $n ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$otp_table} WHERE phone=%s", '20155509' . $n ) );
	if ( 200 === call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-09' . $n ) ), $nonce )[0] ) { $ok++; }
}
check( 'one address cannot walk a list of numbers', 5 === $ok, 'sent=' . $ok );

clear_buckets();
$wpdb->query( "DELETE FROM {$otp_table} WHERE phone LIKE '20155509%'" );
clearstatcache();
$at = file_exists( $log ) ? (int) filesize( $log ) : 0;
list( , $d2 ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0902' ) ), $nonce );
$otp_id = (string) ( $d2['otpId'] ?? '' );
clearstatcache();
$tail  = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $at ) : '';
$plain = 1 === preg_match( '/\.\.\.0902 is (\d{6})/', $tail, $m ) ? $m[1] : '';
$wrong = '000000' === $plain ? '111111' : '000000';
$codes = array();
for ( $i = 0; $i < 8; $i++ ) { $codes[] = call( '/otp/verify', array( 'otpId' => $otp_id, 'code' => $wrong ), $nonce )[0]; }
check( 'attempts capped at 5 on the record', 5 >= (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$otp_table} WHERE otp_uid=%s", $otp_id ) ) );
check( 'further guesses are throttled', in_array( 429, $codes, true ), implode( ',', $codes ) );
check( 'the correct code no longer works after exhaustion', 200 !== call( '/otp/verify', array( 'otpId' => $otp_id, 'code' => $plain ), $nonce )[0] );

clear_buckets();
$wpdb->query( "DELETE FROM {$otp_table} WHERE phone LIKE '20155509%'" );
clearstatcache();
$at = file_exists( $log ) ? (int) filesize( $log ) : 0;
list( , $d3 ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0903' ) ), $nonce );
$otp_id = (string) ( $d3['otpId'] ?? '' );
clearstatcache();
$tail  = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $at ) : '';
$plain = 1 === preg_match( '/\.\.\.0903 is (\d{6})/', $tail, $m ) ? $m[1] : '';
$wpdb->query( $wpdb->prepare( "UPDATE {$otp_table} SET expires_at=%s WHERE otp_uid=%s", gmdate( 'Y-m-d H:i:s', time() - 60 ), $otp_id ) );
check( 'an expired code is refused', 200 !== call( '/otp/verify', array( 'otpId' => $otp_id, 'code' => $plain ), $nonce )[0] );

/* =================================================================== */
echo "\n--- LEAD SUBMISSION ---\n";
clear_buckets();
$wpdb->query( "DELETE FROM {$otp_table} WHERE phone LIKE '20155509%'" );
clearstatcache();
$at = file_exists( $log ) ? (int) filesize( $log ) : 0;
list( , $d4 ) = call( '/otp/request', array_merge( $form, array( 'phone' => '(201) 555-0904' ) ), $nonce );
$otp_id = (string) ( $d4['otpId'] ?? '' );
clearstatcache();
$tail  = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $at ) : '';
$plain = 1 === preg_match( '/\.\.\.0904 is (\d{6})/', $tail, $m ) ? $m[1] : '';
list( , $v ) = call( '/otp/verify', array( 'otpId' => $otp_id, 'code' => $plain ), $nonce );
$token = (string) ( $v['verificationToken'] ?? '' );
check( 'token issued', 1 === preg_match( '/^[a-f0-9]{64}$/', $token ) );
check( 'token is stored only as a hash', '' !== $token && null === $wpdb->get_var( $wpdb->prepare( "SELECT otp_uid FROM {$otp_table} WHERE verify_token_hash = %s", $token ) ) );

$lead = array_merge( $form, array( 'phone' => '(201) 555-0904', 'email' => 'p8b+b@example.com' ) );
$cases = array(
	'no token'                     => array( $lead, 403 ),
	'client-asserted verified'     => array( array_merge( $lead, array( 'verified' => true, 'phoneVerified' => true, 'otpVerified' => 1 ) ), 403 ),
	'forged token'                 => array( array_merge( $lead, array( 'verificationToken' => str_repeat( 'f', 64 ) ) ), 403 ),
	'consent withdrawn'            => array( array_merge( $lead, array( 'verificationToken' => $token, 'consent' => false ) ), 400 ),
	'forged service'               => array( array_merge( $lead, array( 'verificationToken' => $token, 'serviceSlug' => 'wizard' ) ), 400 ),
	'forged location'              => array( array_merge( $lead, array( 'verificationToken' => $token, 'locationSlug' => 'atlantis-zz' ) ), 400 ),
	'foreign subcategory'          => array( array_merge( $lead, array( 'verificationToken' => $token, 'subcategoryId' => 'hvac-ac-repair' ) ), 400 ),
	'token bound to another phone' => array( array_merge( $lead, array( 'verificationToken' => $token, 'phone' => '(201) 555-0999' ) ), 403 ),
);
foreach ( $cases as $label => $case ) {
	clear_buckets();
	list( $status ) = call( '/leads', $case[0], $nonce );
	check( "{$label} -> {$case[1]}", $case[1] === $status, (string) $status );
}
clear_buckets();
check( 'none of those burned the token', null === $wpdb->get_var( $wpdb->prepare( "SELECT token_consumed_at FROM {$otp_table} WHERE otp_uid=%s", $otp_id ) ) );
check( 'none of those stored a lead', $leads_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ) );

check( 'a 5000-character name is capped to the column width', 150 === strlen( Sanitizer::text( str_repeat( 'A', 5000 ), 150 ) ) );
check( 'a 5000-character address is capped', 255 >= strlen( Sanitizer::address( str_repeat( 'B', 5000 ) ) ) );
check( 'a long legitimate US address survives intact', 'Apt 4B, 1234 North Martin Luther King Jr Boulevard, Newark, NJ 07102' === Sanitizer::address( 'Apt 4B, 1234 North Martin Luther King Jr Boulevard, Newark, NJ 07102' ) );
check( 'an apostrophe name survives intact', "O'Brien-Smith" === Sanitizer::text( "O'Brien-Smith", 150 ) );
check( 'a legitimate UTM value survives intact', 'spring_sale-2026' === Sanitizer::text( 'spring_sale-2026', 100 ) );

$big = new WP_REST_Request( 'POST', '/threebest/v1/leads' );
$big->set_header( 'Content-Type', 'application/json' );
$big->set_header( 'X-WP-Nonce', $nonce );
$big->set_body( wp_json_encode( array_merge( $lead, array( 'x' => str_repeat( 'y', 200000 ) ) ) ) );
$bigres = rest_do_request( $big );
check( 'a 200 KB body is refused with 413', 413 === $bigres->get_status(), (string) $bigres->get_status() );
check( '413 names no limit and echoes no input', false === strpos( wp_json_encode( $bigres->get_data() ), '65536' ) && false === strpos( wp_json_encode( $bigres->get_data() ), 'yyyy' ) );

$bad = new WP_REST_Request( 'POST', '/threebest/v1/leads' );
$bad->set_header( 'Content-Type', 'application/json' );
$bad->set_header( 'X-WP-Nonce', $nonce );
$bad->set_body( '{"broken' );
$badres = rest_do_request( $bad );
check( 'malformed JSON handled, not fatal', in_array( $badres->get_status(), array( 400, 403 ), true ), (string) $badres->get_status() );

foreach ( array( "'; DROP TABLE {$leads_table}; --", "' OR '1'='1", '<script>alert(1)</script>', '../../etc/passwd' ) as $p ) {
	clear_buckets();
	call( '/leads', array_merge( $lead, array( 'verificationToken' => $token, 'fullName' => $p, 'serviceSlug' => $p ) ), $nonce );
}
check( 'all tables survive injection attempts', 16 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" ), (string) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" ) );
check( 'no lead created by them', $leads_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ) );

clear_buckets();
list( $lc ) = call( '/leads', array_merge( $lead, array( 'verificationToken' => $token ) ), $nonce );
check( 'a legitimate verified submission succeeds', 200 === $lc, (string) $lc );
check( 'exactly one lead added', $leads_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ) );
clear_buckets();
check( 'replay refused', 403 === call( '/leads', array_merge( $lead, array( 'verificationToken' => $token ) ), $nonce )[0] );
check( 'verification marked spent', null !== $wpdb->get_var( $wpdb->prepare( "SELECT token_consumed_at FROM {$otp_table} WHERE otp_uid=%s", $otp_id ) ) );

/* =================================================================== */
echo "\n--- SECRETS AND ERROR SAFETY ---\n";
$bodies = array();
foreach ( array( array( '/locations', array( 'q' => 'newark' ), 'GET' ), array( '/status', array(), 'GET' ) ) as $p ) {
	$bodies[] = wp_json_encode( call( $p[0], $p[1], $nonce, $p[2] )[1] );
}
$bodies[] = wp_json_encode( call( '/leads', array(), $nonce )[1] );
$bodies[] = wp_json_encode( call( '/otp/verify', array( 'otpId' => 'nope' ), $nonce )[1] );
$all = strtolower( implode( ' ', $bodies ) );
foreach ( array( 'zapier', 'twilio', 'googleapis', 'auth_token', 'api_key', '.php', 'wpdb', 'c:\\', 'threebest\\core', 'stack trace' ) as $marker ) {
	check( "no REST response leaks {$marker}", false === strpos( $all, strtolower( $marker ) ) );
}
foreach ( array( '/leads' => array(), '/otp/verify' => array( 'otpId' => 'x' ) ) as $route => $body ) {
	list( , $e ) = call( $route, $body, $nonce );
	check( "envelope intact on {$route}", isset( $e['success'], $e['message'], $e['code'] ) && false === $e['success'] );
}
$home_html = (string) wp_remote_retrieve_body( wp_remote_get( home_url( '/plumber-in-newark-nj/' ), array( 'timeout' => 20 ) ) );
check( 'landing page markup carries no webhook or credential', false === stripos( $home_html, 'zapier' ) && false === stripos( $home_html, 'twilio' ) && false === stripos( $home_html, 'api_key' ) );
clearstatcache();
$log_all = file_exists( $log ) ? (string) file_get_contents( $log, false, null, max( 0, (int) filesize( $log ) - 300000 ) ) : '';
check( 'no webhook URL in the log', false === stripos( $log_all, 'hooks.zapier.com' ) );
check( 'no Twilio credential shape in the log', 0 === preg_match( '/\bAC[0-9a-f]{32}\b/i', $log_all ) );
check( 'no full test phone in the log', false === strpos( $log_all, '2015550904' ) );
check( 'no test email in the log', false === strpos( $log_all, 'p8b+b@example.com' ) );
check( 'no verification token in the log', '' === $token || false === strpos( $log_all, $token ) );

/* =================================================================== */
echo "\n--- ADMIN AND SETTINGS ---\n";
$html = render( array( Settings_Screen::class, 'render' ) );
check( 'settings screen renders', false !== strpos( $html, '3Best Settings' ) );
check( 'no input exists for any secret', 0 === preg_match( '/name="(zapier_webhook_url|twilio_auth_token|twilio_account_sid|twilio_verify_service_sid|google_maps_api_key)"/', $html ) );
check( 'credential constants are named, values never shown', false !== strpos( $html, 'THREEBEST_ZAPIER_WEBHOOK_URL' ) );
check( 'rate limits shown read-only', false !== strpos( $html, 'Rate limits' ) && false !== strpos( $html, 'atomic increment' ) );
check( 'warns that verification sends no SMS', false !== strpos( $html, 'sends no SMS' ) );

$_POST = array( 'delivery_batch_size' => '99999', 'otp_length' => '1', 'zapier_webhook_url' => 'https://evil.example/forged', 'otp_provider' => 'nonsense' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'threebest_save_settings' );
$_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
$f = function ( $loc ) { throw new RuntimeException( 'r' ); };
add_filter( 'wp_redirect', $f, 99 );
try { Settings_Screen::handle_save(); } catch ( RuntimeException $e ) { } finally { remove_filter( 'wp_redirect', $f, 99 ); }
check( 'out-of-range values clamped', 100 === (int) Settings::get( 'delivery_batch_size' ) && 4 === (int) Settings::get( 'otp_length' ) );
check( 'invalid provider falls back to the default', 'twilio_verify' === Settings::get( 'otp_provider' ), (string) Settings::get( 'otp_provider' ) );
$stored = (array) get_option( Settings::OPTION, array() );
check( 'a forged secret is never written', ! array_key_exists( 'zapier_webhook_url', $stored ) );
check( 'no secret value anywhere in the option', false === strpos( wp_json_encode( $stored ), 'evil.example' ) );
$_POST = array(); $_REQUEST = array();

$leads_html = render( array( Leads_Screen::class, 'render' ) );
check( 'lead list says contact details are hidden', false !== strpos( $leads_html, 'Contact details are not shown' ) );
$sample = $wpdb->get_row( "SELECT id, full_name, email, phone FROM {$leads_table} ORDER BY id LIMIT 1", ARRAY_A );
if ( is_array( $sample ) ) {
	foreach ( array( 'full_name', 'email', 'phone' ) as $col ) {
		$vv = (string) $sample[ $col ];
		check( "lead list does not render the {$col}", '' === $vv || false === strpos( $leads_html, $vv ) );
	}
}
$seo_html = render( array( Seo_Pages_Screen::class, 'render' ) );
check( 'SEO screen lists combinations', false !== strpos( $seo_html, 'Recorded combinations' ) );
check( 'SEO screen offers no bulk approve', false === stripos( $seo_html, 'approve all' ) );
check( 'delivery cannot be triggered publicly: no such REST route', 0 === count( array_filter( $ours, fn( $r ) => false !== stripos( $r, 'deliver' ) || false !== stripos( $r, 'queue' ) ) ) );
check( 'delivery is disabled', ! Delivery_Queue::is_enabled() );
check( 'webhook reported as a code, never a value', 'webhook_url_not_configured' === Delivery_Queue::provider()->configuration_error() );

/* =================================================================== */
echo "\n--- CLEANUP ---\n";
restore();
clear_buckets();
check( 'test leads removed', $leads_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ), (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" ) );
check( 'the 35 development OTP records are untouched', $otp_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" ), (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$otp_table}" ) );
check( 'settings restored', $parked === get_option( Settings::OPTION, array() ) );
check( 'combinations restored', count( $parked_combos ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$combo_table}" ) );
$forbidden = array_filter( $outbound, fn( $u ) => false !== stripos( $u, 'zapier' ) || false !== stripos( $u, 'twilio' ) );
check( 'no call to Zapier or Twilio in the whole run', array() === $forbidden, implode( ',', $forbidden ) );

tb_report( 'SUITE 3' );
