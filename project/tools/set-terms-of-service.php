<?php
/**
 * Runbook p3 — install the approved Terms of Service into page ID 12.
 *
 * Development tooling, not part of the running application. Nothing in
 * WordPress, the theme or 3best-core loads this file.
 *
 * ARCHITECTURE. Page 12 is served by the theme's generic page.php — the same
 * template as the Privacy Policy, and NOT the Layer 4 architecture used by
 * Home/About/How-it-works/Services. page.php lists 'terms-of-service' in its
 * own $threebest_legal_slugs, and already emits the hero (from
 * _threebest_eyebrow / _threebest_lead), the
 * .tb-page-body > .container > .tb-prose wrappers and the closing CTA. The body
 * therefore carries prose blocks ONLY — no hero, no wrappers, no CTA and no
 * second H1. The H1 is the page title, rendered by the hero.
 *
 * BLOCK MARKUP. Mirrors set-privacy-policy.php and the project's existing
 * content exactly: core/heading serialises as
 * <!-- wp:heading {"level":2} --><h2>…</h2>, and core/list as
 * <ul class="wp-block-list"> with core/list-item children. Verified against
 * this WordPress build (7.1.2): the heading block's save() applies only
 * useBlockProps.save() and adds no wp-block-heading class, so adding one would
 * make the block invalid in the editor.
 *
 * The approved source marks three standalone lines bold — the opening and
 * closing "Last Updated" lines and the closing "3Best.com". Those are carried
 * across as core/paragraph with inline <strong>, which is native inline
 * formatting the editor round-trips. The Privacy Policy source did not bold its
 * equivalents, so the two pages differ there by fidelity to each source.
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
function tos_text( string $s ): string {
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $s );
}

/**
 * A level-2 heading block.
 *
 * @param string $text Heading text.
 */
function tos_h2( string $text ): string {
	return '<!-- wp:heading {"level":2} --><h2>' . tos_text( $text ) . '</h2><!-- /wp:heading -->';
}

/**
 * A paragraph block.
 *
 * @param string $text Paragraph text.
 */
function tos_p( string $text ): string {
	return '<!-- wp:paragraph --><p>' . tos_text( $text ) . '</p><!-- /wp:paragraph -->';
}

/**
 * A paragraph block whose whole line is bold in the approved source.
 *
 * @param string $text Paragraph text.
 */
function tos_p_strong( string $text ): string {
	return '<!-- wp:paragraph --><p><strong>' . tos_text( $text ) . '</strong></p><!-- /wp:paragraph -->';
}

/**
 * An unordered list block.
 *
 * @param string[] $items List items.
 */
function tos_ul( array $items ): string {
	$out = '<!-- wp:list --><ul class="wp-block-list">';
	foreach ( $items as $item ) {
		$out .= '<!-- wp:list-item --><li>' . tos_text( $item ) . '</li><!-- /wp:list-item -->';
	}
	return $out . '</ul><!-- /wp:list -->';
}

/* ---- The approved content. --------------------------------------------- */
/*
 * The approved source opens with a "Terms of Service" line. That is the page
 * title, already rendered as the H1 by page.php's hero, so it is deliberately
 * not repeated here. Section numbering is part of the approved headings and is
 * preserved, because terms are commonly cited by section number.
 */
$blocks = array();

$blocks[] = tos_p_strong( 'Last Updated: October 7, 2026' );
$blocks[] = tos_p( 'Welcome to 3Best.com ("3Best," "we," "us," or "our"). These Terms of Service ("Terms") govern your access to and use of the 3Best website and related services.' );
$blocks[] = tos_p( 'By accessing or using 3Best.com, you agree to these Terms. If you do not agree with these Terms, please do not use the website or services.' );

$blocks[] = tos_h2( '1. About 3Best' );
$blocks[] = tos_p( '3Best is a platform designed to help users discover and connect with service providers and professionals based on the services and locations they are interested in.' );
$blocks[] = tos_p( '3Best may facilitate the submission and delivery of service requests or inquiries to relevant service providers.' );
$blocks[] = tos_p( '3Best is not the provider of the services offered by third-party professionals unless expressly stated otherwise.' );

$blocks[] = tos_h2( '2. Eligibility' );
$blocks[] = tos_p( 'You must be legally capable of entering into these Terms to use 3Best.' );
$blocks[] = tos_p( 'By using the website, you represent that the information you provide is accurate and that you have the authority to provide that information.' );

$blocks[] = tos_h2( '3. Using the Website' );
$blocks[] = tos_p( 'You agree to use 3Best only for lawful purposes and in accordance with these Terms.' );
$blocks[] = tos_p( 'You must not:' );
$blocks[] = tos_ul(
	array(
		'Provide false, misleading, fraudulent, or inaccurate information',
		'Submit information belonging to another person without authorization',
		'Use the website to impersonate another person or organization',
		'Attempt to gain unauthorized access to the website, systems, accounts, or data',
		'Interfere with or disrupt the operation or security of the website',
		'Use automated methods to abuse, scrape, overload, or circumvent website protections',
		'Attempt to bypass phone verification, rate limits, security controls, or other safeguards',
		'Submit requests for unlawful, abusive, fraudulent, or harmful purposes',
		'Introduce malicious code, software, or other harmful material',
		'Use the website in a manner that violates applicable laws or regulations',
	)
);
$blocks[] = tos_p( 'We may restrict or suspend access to the website or reject submissions that we reasonably believe violate these Terms or present security, fraud, abuse, or operational risks.' );

$blocks[] = tos_h2( '4. Service Requests and Lead Submissions' );
$blocks[] = tos_p( 'When you submit a request through 3Best, you may be asked to provide information such as your name, email address, phone number, location, address, service category, and other information necessary to process your request.' );
$blocks[] = tos_p( 'You agree that the information you provide is accurate and belongs to you or that you are authorized to provide it.' );
$blocks[] = tos_p( 'Phone verification may be required before a request can be submitted.' );
$blocks[] = tos_p( 'By submitting a request, you understand that information associated with your request may be shared with relevant service providers or professionals so that they may contact you regarding the service you requested.' );
$blocks[] = tos_p( 'Submitting a request does not guarantee that you will receive a response, quote, appointment, offer, or service from any provider.' );

$blocks[] = tos_h2( '5. Communications' );
$blocks[] = tos_p( 'By submitting a service request or completing phone verification, you may receive communications related to your request, verification, or the services you requested.' );
$blocks[] = tos_p( 'Depending on how you interact with the website and the choices available to you, you may also receive promotional communications.' );
$blocks[] = tos_p( 'You may opt out of promotional communications where applicable. Opting out of promotional communications does not necessarily prevent service-related, transactional, verification, or other communications necessary to process your request.' );

$blocks[] = tos_h2( '6. Service Providers and Third Parties' );
$blocks[] = tos_p( '3Best may connect users with independent service providers, professionals, businesses, or other third parties.' );
$blocks[] = tos_p( 'These providers are independent parties and are not employees, agents, or representatives of 3Best unless expressly stated otherwise.' );
$blocks[] = tos_p( '3Best does not guarantee or warrant that any service provider:' );
$blocks[] = tos_ul(
	array(
		'Is available',
		'Is properly licensed or qualified',
		'Will respond to your request',
		'Will provide a quote',
		'Will provide services at a particular price',
		'Will meet your expectations',
		'Will complete work satisfactorily',
		'Will comply with any particular standard or requirement',
	)
);
$blocks[] = tos_p( 'You are responsible for evaluating a service provider before entering into an agreement or purchasing services from that provider.' );
$blocks[] = tos_p( 'Any agreement, transaction, communication, or relationship between you and a service provider is between you and that provider.' );

$blocks[] = tos_h2( '7. No Endorsement or Guarantee' );
$blocks[] = tos_p( 'The appearance of a service provider, professional, business, listing, recommendation, or other information on 3Best does not necessarily constitute an endorsement, certification, recommendation, or guarantee by 3Best.' );
$blocks[] = tos_p( 'You should independently evaluate any provider before engaging their services.' );
$blocks[] = tos_p( '3Best does not guarantee the accuracy, completeness, reliability, quality, suitability, legality, availability, or performance of services provided by third parties.' );

$blocks[] = tos_h2( '8. Quotes, Pricing, and Transactions' );
$blocks[] = tos_p( 'Any quotes, estimates, pricing, availability, schedules, or other information provided by service providers are the responsibility of those providers.' );
$blocks[] = tos_p( '3Best does not determine or guarantee the price, scope, terms, quality, or outcome of services provided by third parties.' );
$blocks[] = tos_p( 'Any transaction or agreement between you and a service provider is solely between you and that provider.' );
$blocks[] = tos_p( '3Best is not responsible for payments, refunds, cancellations, warranties, disputes, damages, delays, or other issues arising from transactions between users and third-party providers unless expressly stated otherwise.' );

$blocks[] = tos_h2( '9. User Content and Information' );
$blocks[] = tos_p( 'You are responsible for the information and content you submit through 3Best.' );
$blocks[] = tos_p( 'You agree not to submit content that:' );
$blocks[] = tos_ul(
	array(
		'Is unlawful, fraudulent, defamatory, threatening, abusive, or misleading',
		"Violates another person's rights",
		'Contains unauthorized personal information',
		'Contains malicious software or harmful code',
		'Infringes intellectual property or other rights',
		'Is intended to abuse, disrupt, or manipulate the platform',
	)
);
$blocks[] = tos_p( 'By submitting information through the website, you grant 3Best the rights reasonably necessary to process, store, use, and share that information for the purposes described in our Privacy Policy and these Terms.' );

$blocks[] = tos_h2( '10. Privacy' );
$blocks[] = tos_p( 'Your use of 3Best is also subject to our Privacy Policy.' );
$blocks[] = tos_p( 'Our Privacy Policy explains what information we collect, how we use it, how it may be shared, and the choices available to you.' );
$blocks[] = tos_p( 'By using 3Best, you acknowledge that information may be collected and processed as described in the Privacy Policy.' );

$blocks[] = tos_h2( '11. Phone Verification' );
$blocks[] = tos_p( '3Best may use phone verification to confirm that a phone number belongs to the person submitting a request and to help prevent fraud, spam, and abuse.' );
$blocks[] = tos_p( 'A verification code may be sent to the phone number you provide.' );
$blocks[] = tos_p( 'You are responsible for providing a phone number that you are authorized to use.' );
$blocks[] = tos_p( 'You must not attempt to obtain, use, or submit verification codes belonging to another person or attempt to bypass or interfere with the verification process.' );

$blocks[] = tos_h2( '12. Intellectual Property' );
$blocks[] = tos_p( 'The 3Best website, including its design, branding, text, graphics, software, functionality, and other materials, may be protected by intellectual property and other applicable laws.' );
$blocks[] = tos_p( 'Except as permitted by law or expressly authorized by 3Best, you may not copy, reproduce, modify, distribute, publicly display, publish, sell, license, reverse engineer, or otherwise exploit protected portions of the website.' );
$blocks[] = tos_p( 'You retain ownership of information and content that you have the right to provide to 3Best, subject to the rights necessary for 3Best to operate its services as described in these Terms and the Privacy Policy.' );

$blocks[] = tos_h2( '13. Third-Party Services and Links' );
$blocks[] = tos_p( '3Best may use or integrate third-party services to support website functionality, including services for phone verification, location information, lead delivery, hosting, analytics, advertising, and other operational purposes.' );
$blocks[] = tos_p( 'The website may also contain links to third-party websites or services.' );
$blocks[] = tos_p( 'Third-party services and websites are governed by their own terms and policies. 3Best is not responsible for the content, availability, security, privacy practices, or policies of third-party websites or services.' );

$blocks[] = tos_h2( '14. Advertising and Featured Listings' );
$blocks[] = tos_p( '3Best may display advertising, sponsored content, featured professionals, paid listings, or other promotional placements.' );
$blocks[] = tos_p( 'Where applicable, promotional placements may be identified or presented as advertising, sponsored, featured, or similar content.' );
$blocks[] = tos_p( 'A paid or featured placement does not necessarily constitute an endorsement or guarantee of the provider or service.' );

$blocks[] = tos_h2( '15. Website Availability' );
$blocks[] = tos_p( 'We make reasonable efforts to keep 3Best available and functioning properly, but we do not guarantee that the website or any particular feature will always be available, uninterrupted, secure, or error-free.' );
$blocks[] = tos_p( 'We may modify, suspend, restrict, or discontinue portions of the website or services at any time.' );

$blocks[] = tos_h2( '16. Disclaimer of Warranties' );
$blocks[] = tos_p( 'To the extent permitted by applicable law, 3Best is provided on an "as is" and "as available" basis.' );
$blocks[] = tos_p( '3Best does not make warranties or representations regarding the accuracy, reliability, completeness, availability, suitability, or fitness of the website, information, listings, recommendations, or third-party services for any particular purpose.' );
$blocks[] = tos_p( 'Your use of the website and reliance on information obtained through the website is at your own discretion and risk.' );

$blocks[] = tos_h2( '17. Limitation of Liability' );
$blocks[] = tos_p( 'To the maximum extent permitted by applicable law, 3Best and its owners, operators, employees, contractors, and service providers will not be responsible for indirect, incidental, special, consequential, exemplary, or punitive damages arising from or related to your use of the website or your interactions or transactions with third-party service providers.' );
$blocks[] = tos_p( 'This includes, where applicable, claims arising from service provider conduct, service quality, pricing, availability, delays, cancellations, property damage, personal injury, disputes, or other matters relating to services provided by third parties.' );
$blocks[] = tos_p( 'Nothing in these Terms is intended to exclude or limit liability that cannot legally be excluded or limited under applicable law.' );

$blocks[] = tos_h2( '18. Indemnification' );
$blocks[] = tos_p( 'To the extent permitted by applicable law, you agree to defend, indemnify, and hold harmless 3Best and its owners, operators, employees, contractors, and service providers from claims, liabilities, damages, losses, costs, and expenses arising from:' );
$blocks[] = tos_ul(
	array(
		'Your violation of these Terms',
		'Your misuse of the website',
		'Information or content you submit',
		"Your violation of another person's rights",
		'Your interactions or transactions with third-party service providers',
		'Your violation of applicable laws or regulations',
	)
);

$blocks[] = tos_h2( '19. Suspension and Termination' );
$blocks[] = tos_p( 'We may restrict, suspend, or terminate access to the website or services if we reasonably believe that you have violated these Terms, engaged in fraudulent or abusive activity, created a security risk, or otherwise misused the platform.' );
$blocks[] = tos_p( 'We may also suspend or discontinue services or portions of the website for operational, security, legal, or business reasons.' );

$blocks[] = tos_h2( '20. Changes to These Terms' );
$blocks[] = tos_p( 'We may update these Terms from time to time to reflect changes in our services, business practices, technology, or applicable legal requirements.' );
$blocks[] = tos_p( 'When we make changes, we will update the "Last Updated" date at the top of this page.' );
$blocks[] = tos_p( 'Your continued use of 3Best after updated Terms become effective constitutes acceptance of the revised Terms to the extent permitted by applicable law.' );

$blocks[] = tos_h2( '21. Governing Law' );
$blocks[] = tos_p( 'These Terms will be governed by applicable law, subject to any mandatory legal requirements that may apply to your use of the website or services.' );
$blocks[] = tos_p( 'Any specific jurisdiction, venue, or governing-law provision should be added only after the applicable business entity and legal jurisdiction have been confirmed.' );

$blocks[] = tos_h2( '22. Contact Us' );
$blocks[] = tos_p( 'If you have questions about these Terms of Service, please contact 3Best through the contact information provided on our website.' );

$blocks[] = tos_p_strong( '3Best.com' );
$blocks[] = tos_p_strong( 'Last Updated: October 7, 2026' );

$content = implode( "\n\n", $blocks );

/* ---- Preconditions. ---------------------------------------------------- */
$page = get_post( 12 );

if ( ! $page instanceof WP_Post ) {
	fwrite( STDERR, "Page ID 12 does not exist.\n" );
	exit( 1 );
}

$preconditions = array(
	'is a page'                 => 'page' === $page->post_type,
	'slug is terms-of-service'  => 'terms-of-service' === $page->post_name,
	'status is publish'         => 'publish' === $page->post_status,
	'not the WP privacy page'   => 12 !== (int) get_option( 'wp_page_for_privacy_policy' ),
	'content has no H1'         => false === stripos( $content, '<h1' ),
	'content has no wrappers'   => false === strpos( $content, 'tb-page-body' ) && false === strpos( $content, 'tb-prose' ) && false === strpos( $content, 'tb-page-hero' ),
	'content has no uagb'       => false === stripos( $content, 'uagb' ) && false === stripos( $content, 'uag-' ),
	'content has no threebest'  => false === strpos( $content, 'wp:threebest' ),
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
		'ID'           => 12,
		'post_content' => wp_slash( $content ),
	),
	true
);

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Update failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

/* ---- Verify what landed. ----------------------------------------------- */
clean_post_cache( 12 );
$after = get_post( 12 );

$stored = (string) $after->post_content;
$parsed = array_values( array_filter( parse_blocks( $stored ), static fn( array $b ): bool => ! empty( $b['blockName'] ) ) );
$names  = array_count_values( array_column( $parsed, 'blockName' ) );
ksort( $names );

$list_items = 0;
foreach ( $parsed as $b ) {
	if ( 'core/list' === $b['blockName'] ) {
		$list_items += count( array_filter( $b['innerBlocks'], static fn( array $c ): bool => 'core/list-item' === ( $c['blockName'] ?? '' ) ) );
	}
}

printf( "\n  content: %d -> %d chars\n", $before_len, strlen( $stored ) );
printf( "  stored exactly as built: %s\n", $stored === $content ? 'yes' : 'NO' );
printf( "  top-level blocks: %d\n", count( $parsed ) );
foreach ( $names as $name => $n ) {
	printf( "    %-22s %d\n", $name, $n );
}
printf( "    %-22s %d\n", 'core/list-item', $list_items );
printf( "  round-trip serialize match: %s\n", serialize_blocks( parse_blocks( $stored ) ) === $stored ? 'yes' : 'NO' );
printf( "  classic/freeform blocks: %d\n", count( array_filter( parse_blocks( $stored ), static fn( array $b ): bool => 'core/freeform' === ( $b['blockName'] ?? '' ) ) ) );
printf( "  block delimiters survived KSES: %s\n", substr_count( $stored, '<!-- wp:' ) > 0 ? 'yes' : 'NO' );
printf( "  inline <strong> preserved: %d\n", substr_count( $stored, '<strong>' ) );
printf( "  title preserved: %s\n", $after->post_title === $before_title ? 'yes' : 'NO' );
printf( "  slug preserved: %s\n", 'terms-of-service' === $after->post_name ? 'yes' : 'NO' );
printf( "  status preserved: %s\n", 'publish' === $after->post_status ? 'yes' : 'NO' );
printf( "  _threebest_eyebrow: %s\n", (string) get_post_meta( 12, '_threebest_eyebrow', true ) );
printf( "  _threebest_lead: %s\n", (string) get_post_meta( 12, '_threebest_lead', true ) );
printf( "  _wp_page_template: %s\n", (string) get_post_meta( 12, '_wp_page_template', true ) );
printf( "  privacy page still 3: %s\n", 3 === (int) get_option( 'wp_page_for_privacy_policy' ) ? 'yes' : 'NO' );

echo "\nDone.\n";
