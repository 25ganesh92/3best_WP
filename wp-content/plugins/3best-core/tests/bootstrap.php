<?php
/**
 * Development-only test bootstrap.
 *
 * These suites are DEVELOPMENT TOOLING. They are never loaded by the plugin,
 * never reachable from the web, and refuse to run against a production
 * environment. Four independent guards, because any one of them could be
 * defeated by a hosting quirk and the consequence would be a test harness
 * mutating live data:
 *
 *   1. CLI ONLY. A web request reaching one of these files is answered with
 *      403 and nothing else runs, whatever the web server was configured to
 *      do with .php files in this directory.
 *   2. NOT PRODUCTION. After WordPress loads, wp_get_environment_type() must
 *      not be 'production'. WordPress defaults to 'production' when nothing
 *      says otherwise, so the safe answer is the default one.
 *   3. NOT A RUNTIME DEPENDENCY. Nothing in 3best-core references this
 *      directory, and the autoloader only maps class-/interface-/trait-
 *      prefixed files, which none of these are. A normal WordPress request
 *      never opens them.
 *   4. NO CREDENTIALS. No file here contains, reads or prints a credential.
 *      The suites assert the absence of secrets; they never supply one.
 *
 * The suites park and restore every piece of state they touch, identify their
 * own rows by value rather than by "the newest row", and block every outbound
 * HTTP request that is not to this site.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

/* ---- Guard 1: command line only. --------------------------------------- */
if ( 'cli' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		http_response_code( 403 );
	}
	exit( "Forbidden.\n" );
}

/* ---- WordPress. -------------------------------------------------------- */
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

$threebest_tests_root = dirname( __DIR__, 4 );

if ( ! is_readable( $threebest_tests_root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not locate wp-load.php from " . $threebest_tests_root . "\n" );
	exit( 1 );
}

require_once $threebest_tests_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

/* ---- Guard 2: never against production. -------------------------------- */
if ( 'production' === wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: WP_ENVIRONMENT_TYPE is 'production'.\n" );
	fwrite( STDERR, "These suites write and delete rows. They are development tooling only.\n" );
	exit( 1 );
}

/* ---- Shared helpers. --------------------------------------------------- */
$GLOBALS['tb_pass'] = 0;
$GLOBALS['tb_fail'] = 0;

if ( ! function_exists( 'check' ) ) {
	/**
	 * Records one assertion.
	 *
	 * @param string $label  What is being asserted.
	 * @param bool   $ok     Whether it held.
	 * @param string $detail Optional observed value.
	 */
	function check( string $label, bool $ok, string $detail = '' ): void {
		$ok ? $GLOBALS['tb_pass']++ : $GLOBALS['tb_fail']++;
		printf( "  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? "  -- {$detail}" : '' );
	}
}

if ( ! function_exists( 'tb_report' ) ) {
	/**
	 * Prints the suite total and exits with a meaningful status.
	 *
	 * @param string $suite Suite name.
	 */
	function tb_report( string $suite ): void {
		echo "\n==================================================\n";
		printf( "%s RESULT: %d passed, %d failed\n", $suite, $GLOBALS['tb_pass'], $GLOBALS['tb_fail'] );
		echo "==================================================\n";
		exit( $GLOBALS['tb_fail'] > 0 ? 1 : 0 );
	}
}
