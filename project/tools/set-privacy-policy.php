<?php
/**
 * Runbook p2 — install the approved Privacy Policy into page ID 3.
 *
 * Development tooling, not part of the running application. Nothing in
 * WordPress, the theme or 3best-core loads this file.
 *
 * ARCHITECTURE. Page 3 is served by the theme's generic page.php, which is NOT
 * the Layer 4 architecture used by Home/About/How-it-works/Services. page.php
 * already emits the hero (from _threebest_eyebrow / _threebest_lead), the
 * .tb-page-body > .container > .tb-prose wrappers and the closing CTA. The
 * body therefore carries prose blocks ONLY — no hero, no wrappers, no CTA and
 * no second H1. The H1 is the page title, rendered by the hero.
 *
 * BLOCK MARKUP. Mirrors the project's existing content exactly: core/heading
 * serialises as <!-- wp:heading {"level":2} --><h2>…</h2>, and core/list as
 * <ul class="wp-block-list"> with core/list-item children. Verified against
 * this WordPress build (7.1.2): the heading block's save() applies only
 * useBlockProps.save() and adds no wp-block-heading class, so adding one would
 * make the block invalid in the editor.
 *
 * Idempotent: running it twice produces byte-identical content.
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

define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true );

$root = dirname( __DIR__, 2 );

if ( ! is_readable( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not locate wp-load.php from {$root}\n" );
	exit( 1 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

/* ---- Guard 2: never against production. -------------------------------- */
if ( 'production' === wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: WP_ENVIRONMENT_TYPE is 'production'.\n" );
	exit( 1 );
}

global $wpdb;

/*
 * unfiltered_html is required: without it KSES strips HTML comments on save,
 * which would silently destroy every block delimiter and leave raw HTML.
 */
$admin_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" );
wp_set_current_user( $admin_id );

if ( ! current_user_can( 'unfiltered_html' ) ) {
	fwrite( STDERR, "Refusing to run: the acting user lacks unfiltered_html.\n" );
	exit( 1 );
}

/* ---- Block builders. --------------------------------------------------- */

/**
 * Escapes only what block markup requires, leaving quotes as the editor stores them.
 *
 * @param string $s Raw text.
 */
function pp_text( string $s ): string {
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $s );
}

/**
 * A level-2 heading block.
 *
 * @param string $text Heading text.
 */
function pp_h2( string $text ): string {
	return '<!-- wp:heading {"level":2} --><h2>' . pp_text( $text ) . '</h2><!-- /wp:heading -->';
}

/**
 * A paragraph block.
 *
 * @param string $text Paragraph text.
 */
function pp_p( string $text ): string {
	return '<!-- wp:paragraph --><p>' . pp_text( $text ) . '</p><!-- /wp:paragraph -->';
}

/**
 * An unordered list block.
 *
 * @param string[] $items List items.
 */
function pp_ul( array $items ): string {
	$out = '<!-- wp:list --><ul class="wp-block-list">';
	foreach ( $items as $item ) {
		$out .= '<!-- wp:list-item --><li>' . pp_text( $item ) . '</li><!-- /wp:list-item -->';
	}
	return $out . '</ul><!-- /wp:list -->';
}

/* ---- The approved content. --------------------------------------------- */
/*
 * The leading "Privacy Policy" line of the approved source is the page title,
 * already rendered as the H1 by page.php's hero, so it is deliberately not
 * repeated here.
 */
$blocks = array();

$blocks[] = pp_p( 'Last Updated: October 6, 2026' );
$blocks[] = pp_p( '3Best.com ("3Best," "we," "us," or "our") respects your privacy and is committed to protecting the personal information you provide when using our website and services.' );
$blocks[] = pp_p( 'This Privacy Policy explains what information we collect, how we use it, when we may share it, and the choices available to you.' );
$blocks[] = pp_p( 'By using 3Best.com, you acknowledge the practices described in this Privacy Policy.' );

$blocks[] = pp_h2( 'Information We Collect' );
$blocks[] = pp_p( 'Depending on how you use our website, we may collect information that you voluntarily provide, including:' );
$blocks[] = pp_ul(
	array(
		'Service or category you are looking for',
		'City, area, landmark, ZIP code, or other location information',
		'Full name',
		'Email address',
		'Phone number',
		'Full address',
		'Information you provide in connection with a service request or inquiry',
		'Communications you send to us',
	)
);
$blocks[] = pp_p( 'When you submit a service request, we may also process information associated with that request, including the selected service, subcategory, resolved city, state, ZIP code, and location information such as latitude and longitude where available.' );
$blocks[] = pp_p( 'We may collect information associated with your consent and submission, including the consent provided, consent version, consent text, date and time of consent, IP address, and browser or user-agent information.' );
$blocks[] = pp_p( 'When you verify your phone number, we may process information necessary to complete the verification process, including verification status, verification attempts, sending attempts, IP address, browser or user-agent information, and securely hashed verification information.' );
$blocks[] = pp_p( 'We may also automatically collect certain technical information when you visit our website, such as your IP address, browser type, device information, pages viewed, referring website, and general usage information.' );
$blocks[] = pp_p( 'We may also receive referral and campaign information associated with your visit, such as referring URLs and UTM campaign parameters.' );

$blocks[] = pp_h2( 'How We Use Your Information' );
$blocks[] = pp_p( 'We may use the information we collect to:' );
$blocks[] = pp_ul(
	array(
		'Provide and operate the 3Best website and services',
		'Help you find relevant service providers',
		'Process and verify service requests',
		'Verify phone numbers and prevent fraudulent or abusive submissions',
		'Contact you regarding your request or inquiry',
		'Deliver your submitted request to relevant service providers or professionals',
		'Improve our website, services, security, and user experience',
		'Monitor and prevent spam, fraud, abuse, and unauthorized activity',
		'Maintain records required for operational, security, or legal purposes',
		'Respond to questions, requests, and support inquiries',
		'Comply with applicable laws and legal obligations',
	)
);
$blocks[] = pp_p( 'We may also use aggregated or de-identified information for analytics, reporting, service improvement, and other legitimate business purposes.' );

$blocks[] = pp_h2( 'Sharing of Information' );
$blocks[] = pp_p( '3Best may share information you submit when necessary to provide the service you requested.' );
$blocks[] = pp_p( 'For example, when you submit a request for a service, relevant information may be provided to service providers or professionals who may be able to respond to your request.' );
$blocks[] = pp_p( 'We may also share information with trusted third-party service providers that help us operate the website and provide our services. These providers may support functions such as:' );
$blocks[] = pp_ul(
	array(
		'Phone verification and communications',
		'Location and address services',
		'Lead delivery and workflow automation',
		'Website hosting and infrastructure',
		'Security and fraud prevention',
		'Analytics and website performance',
	)
);
$blocks[] = pp_p( 'These providers are permitted to access information only as reasonably necessary to perform services on our behalf.' );
$blocks[] = pp_p( 'We may also disclose information when required by law, legal process, court order, governmental request, or when reasonably necessary to protect the rights, property, safety, or security of 3Best, our users, service providers, or others.' );

$blocks[] = pp_h2( 'Third-Party Services' );
$blocks[] = pp_p( '3Best may use third-party technology providers to support specific website functions.' );
$blocks[] = pp_p( 'These services may include Twilio for phone verification, Zapier for lead delivery and workflow automation, and location services such as Nominatim/OpenStreetMap or other mapping and location providers.' );
$blocks[] = pp_p( 'The specific providers and services used by 3Best may change as the platform develops.' );
$blocks[] = pp_p( 'Third-party providers have their own privacy policies and terms governing their services. We encourage you to review the privacy practices of third-party services when appropriate.' );

$blocks[] = pp_h2( 'Cookies and Similar Technologies' );
$blocks[] = pp_p( '3Best may use cookies and similar technologies to operate the website, maintain functionality, remember preferences, understand website usage, improve performance, and support analytics or advertising.' );
$blocks[] = pp_p( 'You can control or disable cookies through your browser settings. Some website features may not function correctly if certain cookies are disabled.' );
$blocks[] = pp_p( 'Where required by applicable law, we will provide appropriate choices or notices regarding cookies and similar technologies.' );

$blocks[] = pp_h2( 'Advertising and Analytics' );
$blocks[] = pp_p( 'We may use third-party analytics, advertising, or measurement services to understand website traffic, improve our services, and support advertising.' );
$blocks[] = pp_p( 'These services may use cookies, pixels, or similar technologies and may collect information about your interaction with our website.' );
$blocks[] = pp_p( 'Where applicable, you may be able to control certain advertising or tracking preferences through your browser, device, or the relevant third-party service.' );

$blocks[] = pp_h2( 'Data Security' );
$blocks[] = pp_p( 'We use reasonable administrative, technical, and organizational measures designed to protect information against unauthorized access, loss, misuse, alteration, or disclosure.' );
$blocks[] = pp_p( 'Phone verification, access controls, rate limiting, and other security measures may be used to help protect the website and its users.' );
$blocks[] = pp_p( 'However, no method of transmission over the Internet or electronic storage is completely secure. Therefore, we cannot guarantee absolute security.' );

$blocks[] = pp_h2( 'Data Retention' );
$blocks[] = pp_p( 'We retain personal information for as long as reasonably necessary to provide our services, operate the website, maintain security and business records, resolve disputes, comply with legal obligations, and enforce our agreements.' );
$blocks[] = pp_p( 'Retention periods may vary depending on the type of information and the purpose for which it was collected.' );
$blocks[] = pp_p( 'When information is no longer reasonably required, we may securely delete, anonymize, or otherwise dispose of it in accordance with applicable requirements.' );

$blocks[] = pp_h2( 'Your Choices and Privacy Rights' );
$blocks[] = pp_p( 'Depending on where you live and applicable law, you may have rights concerning your personal information, which may include the right to:' );
$blocks[] = pp_ul(
	array(
		'Request access to personal information we hold about you',
		'Request correction of inaccurate information',
		'Request deletion of certain personal information',
		'Request information about how your personal information is used or shared',
		'Withdraw consent where processing is based on consent',
		'Opt out of certain marketing communications',
		'Exercise other rights provided by applicable privacy laws',
	)
);
$blocks[] = pp_p( 'Some requests may be subject to applicable legal exceptions or verification requirements.' );
$blocks[] = pp_p( 'To make a privacy-related request, please contact us using the contact information provided on our website.' );

$blocks[] = pp_h2( 'Communications' );
$blocks[] = pp_p( 'If you submit a service request or inquiry, we may contact you using the information you provide in order to process or respond to that request.' );
$blocks[] = pp_p( 'Where applicable, you may also receive communications related to your request, verification, account, or other services you have requested.' );
$blocks[] = pp_p( 'You may opt out of certain promotional communications by following the unsubscribe instructions provided in those communications or by contacting us.' );
$blocks[] = pp_p( 'Opting out of promotional communications does not necessarily prevent service-related or transactional communications.' );

$blocks[] = pp_h2( "Children's Privacy" );
$blocks[] = pp_p( '3Best.com is not intended for children under the age of 13, and we do not knowingly collect personal information from children under 13.' );
$blocks[] = pp_p( 'If you believe that a child has provided personal information to us, please contact us so that we can review and, where appropriate, delete the information.' );

$blocks[] = pp_h2( 'Third-Party Websites' );
$blocks[] = pp_p( 'Our website may contain links to websites or services operated by third parties.' );
$blocks[] = pp_p( 'We are not responsible for the privacy practices, security, content, or policies of third-party websites. We encourage you to review the privacy policy of any third-party website you visit.' );

$blocks[] = pp_h2( 'Changes to This Privacy Policy' );
$blocks[] = pp_p( 'We may update this Privacy Policy from time to time to reflect changes in our services, technology, business practices, or applicable legal requirements.' );
$blocks[] = pp_p( 'When we make changes, we will update the "Last Updated" date at the top of this page. Where required by applicable law, we may provide additional notice.' );

$blocks[] = pp_h2( 'Contact Us' );
$blocks[] = pp_p( 'If you have questions about this Privacy Policy, want to make a privacy-related request, or have concerns about how your information is handled, please contact 3Best through the contact information provided on our website.' );

$blocks[] = pp_p( '3Best.com' );
$blocks[] = pp_p( 'Last Updated: October 6, 2026' );

$content = implode( "\n\n", $blocks );

/* ---- Preconditions. ---------------------------------------------------- */
$page = get_post( 3 );

if ( ! $page instanceof WP_Post ) {
	fwrite( STDERR, "Page ID 3 does not exist.\n" );
	exit( 1 );
}

$preconditions = array(
	'is a page'                => 'page' === $page->post_type,
	'slug is privacy-policy'   => 'privacy-policy' === $page->post_name,
	'status is publish'        => 'publish' === $page->post_status,
	'is the WP privacy page'   => 3 === (int) get_option( 'wp_page_for_privacy_policy' ),
	'content has no H1'        => false === stripos( $content, '<h1' ),
	'content has no wrappers'  => false === strpos( $content, 'tb-page-body' ) && false === strpos( $content, 'tb-prose' ) && false === strpos( $content, 'tb-page-hero' ),
	'content has no uagb'      => false === stripos( $content, 'uagb' ) && false === stripos( $content, 'uag-' ),
	'content has no threebest' => false === strpos( $content, 'wp:threebest' ),
);

foreach ( $preconditions as $label => $ok ) {
	printf( "  [%s] precondition: %s\n", $ok ? 'OK' : 'FAIL', $label );
	if ( ! $ok ) {
		fwrite( STDERR, "Aborting: precondition failed.\n" );
		exit( 1 );
	}
}

$before       = (string) $page->post_content;
$before_len   = strlen( $before );
$before_title = $page->post_title;

if ( $before === $content ) {
	printf( "\n  Already installed, byte-identical. Nothing to do. (%d chars)\n", $before_len );
	exit( 0 );
}

/* ---- Write. ------------------------------------------------------------ */
/*
 * wp_slash: wp_update_post() expects slashed data and unslashes it. Only
 * post_content is passed, so the title, slug, status, template, menu order and
 * every meta value are left exactly as they are.
 */
$result = wp_update_post(
	array(
		'ID'           => 3,
		'post_content' => wp_slash( $content ),
	),
	true
);

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Update failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

/* ---- Verify what landed. ----------------------------------------------- */
clean_post_cache( 3 );
$after = get_post( 3 );

$stored = (string) $after->post_content;
$parsed = array_values( array_filter( parse_blocks( $stored ), static fn( array $b ): bool => ! empty( $b['blockName'] ) ) );
$names  = array_count_values( array_column( $parsed, 'blockName' ) );

printf( "\n  content: %d -> %d chars\n", $before_len, strlen( $stored ) );
printf( "  stored exactly as built: %s\n", $stored === $content ? 'yes' : 'NO' );
printf( "  top-level blocks: %d\n", count( $parsed ) );
foreach ( $names as $name => $n ) {
	printf( "    %-22s %d\n", $name, $n );
}
printf( "  block delimiters survived KSES: %s\n", substr_count( $stored, '<!-- wp:' ) > 0 ? 'yes' : 'NO' );
printf( "  title preserved: %s\n", $after->post_title === $before_title ? 'yes' : 'NO' );
printf( "  slug preserved: %s\n", 'privacy-policy' === $after->post_name ? 'yes' : 'NO' );
printf( "  status preserved: %s\n", 'publish' === $after->post_status ? 'yes' : 'NO' );
printf( "  still the WP privacy page: %s\n", 3 === (int) get_option( 'wp_page_for_privacy_policy' ) ? 'yes' : 'NO' );
printf( "  _threebest_eyebrow: %s\n", (string) get_post_meta( 3, '_threebest_eyebrow', true ) );
printf( "  _threebest_lead: %s\n", (string) get_post_meta( 3, '_threebest_lead', true ) );
printf( "  _wp_page_template: %s\n", (string) get_post_meta( 3, '_wp_page_template', true ) );

echo "\nDone.\n";
