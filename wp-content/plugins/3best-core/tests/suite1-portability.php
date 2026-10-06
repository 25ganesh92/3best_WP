<?php
/**
 * Regression suite 1 of 3 — production portability.
 *
 * Rebuilt for p8 after the Spectra/UAGB deactivation. Static analysis only,
 * no WordPress bootstrap. Catches the Linux/cPanel class of defect that never
 * fails on Windows: filename case against the autoloader, byte-order marks,
 * path separators, hard-coded prefixes, ext-intl.
 */

/* Guard: command line only. This file is development tooling and must never
 * be reachable over HTTP, whatever the web server does with .php here. */
if ( 'cli' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		http_response_code( 403 );
	}
	exit( "Forbidden.
" );
}

$pass = 0; $fail = 0;
function check( string $l, bool $ok, string $d = '' ): void {
	global $pass, $fail; $ok ? $pass++ : $fail++;
	printf( "  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $l, '' !== $d ? "  -- {$d}" : '' );
}

$plugin = 'C:/xampp/htdocs/3best/wp-content/plugins/3best-core';
$theme  = 'C:/xampp/htdocs/3best/wp-content/themes/3best';

/**
 * Every file of a given extension under a directory, EXCLUDING this tests
 * directory.
 *
 * The suites live inside the plugin but are not part of it: they never ship,
 * never load on a WordPress request, and deliberately contain the very
 * strings this scan looks for. Scanning them would be scanning the ruler,
 * not the thing being measured.
 */
function files_under( string $dir, string $ext ): array {
	$out  = array();
	$skip = str_replace( chr(92), '/', __DIR__ ) . '/';
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 0 === strpos( str_replace( chr(92), '/', $file->getPathname() ), $skip ) ) {
			continue;
		}
		if ( $file->isFile() && strtolower( $file->getExtension() ) === $ext ) {
			$out[] = str_replace( '\\', '/', $file->getPathname() );
		}
	}
	sort( $out );
	return $out;
}

$php_files = array_merge( files_under( $plugin, 'php' ), files_under( $theme, 'php' ) );

echo "--- 1. CASE SENSITIVITY ---\n";
$class_map = array();
foreach ( $php_files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( 1 === preg_match( '/^namespace\s+([^;]+);/m', $src, $ns )
		&& preg_match_all( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $src, $cm ) ) {
		foreach ( $cm[1] as $class ) {
			$class_map[ trim( $ns[1] ) . '\\' . $class ] = $file;
		}
	}
}
check( 'declared classes found', count( $class_map ) >= 50, (string) count( $class_map ) );

$case_problems = array();
foreach ( $class_map as $fqcn => $file ) {
	$short = substr( $fqcn, strrpos( $fqcn, '\\' ) + 1 );
	$slug  = str_replace( '_', '-', strtolower( $short ) );
	$base  = basename( $file );
	if ( ! in_array( $base, array( "class-{$slug}.php", "interface-{$slug}.php", "trait-{$slug}.php" ), true ) ) {
		$case_problems[] = $fqcn . ' => ' . $base;
	}
}
check( 'every class file name matches what the autoloader builds', array() === $case_problems, implode( '; ', array_slice( $case_problems, 0, 5 ) ) );

$mixed = array();
foreach ( $php_files as $file ) {
	$rel = str_replace( array( $plugin . '/', $theme . '/' ), '', $file );
	if ( $rel !== strtolower( $rel ) ) { $mixed[] = $rel; }
}
check( 'no upper-case letters in any PHP path', array() === $mixed, implode( ', ', array_slice( $mixed, 0, 5 ) ) );

$asset_mixed = array();
foreach ( array_merge( files_under( $plugin, 'js' ), files_under( $theme, 'css' ), files_under( $theme, 'js' ) ) as $file ) {
	$rel = str_replace( array( $plugin . '/', $theme . '/' ), '', $file );
	if ( $rel !== strtolower( $rel ) ) { $asset_mixed[] = $rel; }
}
check( 'no upper-case letters in any asset path', array() === $asset_mixed, implode( ', ', $asset_mixed ) );

echo "\n--- 2. ENCODING AND LINE ENDINGS ---\n";
$bom = array(); $notutf = array(); $trailing = array();
foreach ( $php_files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( 0 === strpos( $src, "\xEF\xBB\xBF" ) ) { $bom[] = basename( $file ); }
	if ( ! mb_check_encoding( $src, 'UTF-8' ) ) { $notutf[] = basename( $file ); }
	if ( 1 === preg_match( '/\?>\s+$/', $src ) ) { $trailing[] = basename( $file ); }
}
check( 'no byte-order mark in any PHP file', array() === $bom, implode( ', ', $bom ) );
check( 'every PHP file is valid UTF-8', array() === $notutf, implode( ', ', $notutf ) );
check( 'no file ends with a closing tag plus whitespace', array() === $trailing, implode( ', ', $trailing ) );

echo "\n--- 3. PATHS AND PREFIXES ---\n";
$abs = array(); $sep = array(); $prefix = array();
foreach ( $php_files as $file ) {
	$src = (string) file_get_contents( $file );
	if ( 1 === preg_match( '#[\'"][A-Za-z]:[\\\\/]#', $src ) ) { $abs[] = basename( $file ); }
	if ( 1 === preg_match( '#(require|include)(_once)?\s*\(?\s*[\'"][^\'"]*\\\\#', $src ) ) { $sep[] = basename( $file ); }
	if ( 1 === preg_match( '#[\'"`]wp_(posts|options|users|threebest)#', $src ) ) { $prefix[] = basename( $file ); }
}
check( 'no absolute Windows path in any source file', array() === $abs, implode( ', ', $abs ) );
check( 'no backslash separators in require/include', array() === $sep, implode( ', ', $sep ) );
check( 'no hard-coded wp_ table prefix', array() === $prefix, implode( ', ', $prefix ) );

echo "\n--- 4. HOST ASSUMPTIONS ---\n";
$banned = array( 'shell_exec', 'exec(', 'system(', 'passthru', 'popen', 'proc_open', 'eval(', 'create_function', 'extract(' );
$found = array();
foreach ( $php_files as $file ) {
	$src = (string) file_get_contents( $file );
	foreach ( $banned as $fn ) {
		if ( false !== strpos( $src, $fn ) ) { $found[] = basename( $file ) . ':' . $fn; }
	}
}
check( 'no shell, eval or extract calls anywhere', array() === $found, implode( ', ', $found ) );

$short = array();
foreach ( $php_files as $file ) {
	if ( 1 === preg_match( '/<\?(?!php|=|xml)/', (string) file_get_contents( $file ) ) ) { $short[] = basename( $file ); }
}
check( 'no short open tags', array() === $short, implode( ', ', $short ) );

/*
 * ext-intl must not be required. "Normalizer::" alone is not evidence: the
 * project has its own ThreeBest\Core\Location\Normalizer, resolved inside its
 * own namespace. Only the GLOBAL class counts.
 */
$intl = array();
foreach ( $php_files as $file ) {
	$src = (string) file_get_contents( $file );
	foreach ( array( '\Normalizer::', '\Transliterator::', 'transliterator_create', 'iconv(', 'collator_create', 'numfmt_create' ) as $fn ) {
		if ( false !== strpos( $src, $fn ) ) { $intl[] = basename( $file ) . ':' . $fn; }
	}
	if ( 1 === preg_match( '/^use\s+(Normalizer|Transliterator|Collator)\s*;/m', $src ) ) {
		$intl[] = basename( $file ) . ':global import';
	}
}
check( 'no dependency on ext-intl or iconv', array() === $intl, implode( ', ', $intl ) );
check( 'ext-intl is genuinely not loaded here, and the site still works', ! extension_loaded( 'intl' ), extension_loaded( 'intl' ) ? 'loaded' : 'not loaded' );

echo "\n--- 5. PLUGIN HEADER AND BOOTSTRAP ---\n";
$main = (string) file_get_contents( $plugin . '/3best-core.php' );
foreach ( array( 'Plugin Name:', 'Version:', 'Requires at least:', 'Requires PHP:', 'Text Domain:' ) as $header ) {
	check( "header present: {$header}", false !== strpos( $main, $header ) );
}
$h = array(); $c = array();
check(
	'version constant matches the header',
	1 === preg_match( "/Version:\s*([0-9.]+)/", $main, $h )
		&& 1 === preg_match( "/THREEBEST_CORE_VERSION', '([0-9.]+)'/", $main, $c )
		&& $h[1] === $c[1],
	( $h[1] ?? '?' ) . ' vs ' . ( $c[1] ?? '?' )
);
$unguarded = array_filter( $php_files, static function ( string $f ): bool {
	$src = (string) file_get_contents( $f );
	return false === strpos( $src, 'ABSPATH' ) && false === strpos( $src, 'WP_UNINSTALL_PLUGIN' );
} );
check( 'every PHP file guards direct access', array() === $unguarded, implode( ', ', array_map( 'basename', $unguarded ) ) );

echo "\n--- 6. PHP COMPATIBILITY ---\n";
check( 'running PHP 8.2 or newer locally', version_compare( PHP_VERSION, '8.2', '>=' ), PHP_VERSION );
$lint_fail = array();
foreach ( $php_files as $file ) {
	$out = array(); $rc = 0;
	exec( 'C:\\xampp\\php\\php.exe -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
	if ( 0 !== $rc ) { $lint_fail[] = basename( $file ); }
}
check( 'every PHP file parses', array() === $lint_fail, implode( ', ', $lint_fail ) );
printf( "       (%d PHP files checked)\n", count( $php_files ) );

echo "\n==================================================\n";
printf( "SUITE 1 RESULT: %d passed, %d failed\n", $pass, $fail );
echo "==================================================\n";
exit( $fail > 0 ? 1 : 0 );
