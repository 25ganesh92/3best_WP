<?php
/**
 * Lead browser.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Leads\Lead_Repository;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Lists stored leads and shows one at a time in full.
 *
 * ---------------------------------------------------------------------------
 * HOW PERSONAL DATA IS HANDLED HERE
 * ---------------------------------------------------------------------------
 * The list shows no contact details at all — not a name, not an email, not a
 * phone number, not an address. It shows a reference, what was asked for,
 * where, whether the number was verified, and the delivery state. The query
 * behind it does not even SELECT the contact columns, so a list render cannot
 * leak them through a debug bar, a query log or a stray var_dump.
 *
 * Contact details are read only when an administrator opens one specific lead,
 * and that view is recorded in the log by lead id and user id — never by any
 * of the values it displayed.
 *
 * The lead is addressed by its numeric id in the URL, never by email or phone,
 * so no personal data ends up in a browser history, a referrer header or a
 * server access log.
 *
 * There is no bulk export. An export is the single highest-risk feature an
 * admin of this kind can have, and nothing in the project needs one yet.
 */
final class Leads_Screen {

	public const MENU_SLUG  = 'threebest-leads';
	public const CAPABILITY = 'manage_options';

	private const PER_PAGE = 25;

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', '3best-core' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state.
		$id = isset( $_GET['lead'] ) ? absint( wp_unslash( $_GET['lead'] ) ) : 0;

		echo '<div class="wrap">';

		if ( $id > 0 ) {
			self::render_detail( $id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * The list view. No contact details.
	 */
	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only listing state.
		$filters = array(
			'service'         => Sanitizer::slug( $_GET['service'] ?? '', 64 ),
			'delivery_status' => Sanitizer::choice(
				$_GET['delivery_status'] ?? '',
				array( 'pending', 'processing', 'delivered', 'retrying', 'failed' ),
				''
			),
			'verified'        => Sanitizer::choice( $_GET['verified'] ?? '', array( '1', '0' ), '' ),
		);

		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = Lead_Repository::count( $filters );
		$rows  = Lead_Repository::page( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $filters );

		echo '<h1>' . esc_html__( 'Leads', '3best-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Contact details are not shown in this list. Open a lead to see them.', '3best-core' ) . '</p>';

		self::render_requeue_notice();
		self::render_filters( $filters, $total );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Reference', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Service', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Verified', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Delivery', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Received', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No leads match.', '3best-core' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$service  = Services_Repository::by_slug( (string) $row['service_slug'] );
			$verified = 1 === (int) $row['phone_verified'];

			echo '<tr>';

			echo '<td><a href="' . esc_url( Admin_Ui::url( self::MENU_SLUG, array( 'lead' => (int) $row['id'] ) ) ) . '"><strong>' . esc_html( (string) $row['lead_uid'] ) . '</strong></a></td>';

			echo '<td>' . esc_html( null !== $service ? (string) $service['name'] : (string) $row['service_slug'] ) . '</td>';

			echo '<td>' . esc_html( self::place( $row ) ) . '</td>';

			echo '<td>' . Admin_Ui::badge( $verified, $verified ? __( 'yes', '3best-core' ) : __( 'no', '3best-core' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.

			echo '<td>' . self::delivery_cell( $row ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- delivery_cell() escapes.

			echo '<td>' . esc_html( Admin_Ui::local_time( (string) $row['created_at'] ) ) . '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_pagination( $total, $paged, $filters );
	}

	/**
	 * One lead in full, including contact details.
	 *
	 * @param int $id Lead id.
	 */
	private static function render_detail( int $id ): void {
		$lead = Lead_Repository::find( $id );

		if ( null === $lead ) {
			echo '<h1>' . esc_html__( 'Lead', '3best-core' ) . '</h1>';
			Admin_Ui::notice( 'error', __( 'That lead no longer exists.', '3best-core' ) );
			echo '<p><a href="' . esc_url( Admin_Ui::url( self::MENU_SLUG ) ) . '">' . esc_html__( 'Back to leads', '3best-core' ) . '</a></p>';

			return;
		}

		/*
		 * An audit line for a view of personal data: who looked, and at which
		 * lead. Deliberately no name, email, phone or address — the point is
		 * accountability for the access, not a second copy of the data.
		 */
		Logger::log(
			'Lead viewed in admin.',
			array(
				'lead_id' => $id,
				'by'      => get_current_user_id(),
			)
		);

		$service     = Services_Repository::by_slug( (string) $lead['service_slug'] );
		$subcategory = null !== $service ? Services_Repository::subcategory( $service, (string) $lead['subcategory_id'] ) : null;

		echo '<h1>' . esc_html( (string) $lead['lead_uid'] ) . '</h1>';
		echo '<p><a href="' . esc_url( Admin_Ui::url( self::MENU_SLUG ) ) . '">&larr; ' . esc_html__( 'Back to leads', '3best-core' ) . '</a></p>';

		// A requeue started from this screen comes back to it, so the
		// confirmation has to be rendered here and not only on the list.
		self::render_requeue_notice();

		/* Request ---------------------------------------------------------- */
		echo '<h2>' . esc_html__( 'Request', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:860px"><tbody>';
		Admin_Ui::row( __( 'Service', '3best-core' ), null !== $service ? (string) $service['name'] : (string) $lead['service_slug'] );
		Admin_Ui::row( __( 'Needs', '3best-core' ), null !== $subcategory ? (string) $subcategory['name'] : (string) $lead['subcategory_id'] );
		Admin_Ui::row( __( 'Location', '3best-core' ), self::place( $lead ) );
		Admin_Ui::row( __( 'Received', '3best-core' ), Admin_Ui::local_time( (string) $lead['created_at'] ) );

		$source = (string) $lead['source_page'];

		Admin_Ui::row_html(
			__( 'Submitted from', '3best-core' ),
			'' === $source
				? '—'
				: '<a href="' . esc_url( home_url( $source ) ) . '" target="_blank" rel="noreferrer">' . esc_html( $source ) . '</a>'
		);

		echo '</tbody></table>';

		/* Contact ---------------------------------------------------------- */
		echo '<h2>' . esc_html__( 'Contact details', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:860px"><tbody>';
		Admin_Ui::row( __( 'Name', '3best-core' ), (string) $lead['full_name'] );
		Admin_Ui::row( __( 'Email', '3best-core' ), (string) $lead['email'] );
		Admin_Ui::row( __( 'Phone', '3best-core' ), (string) $lead['phone'] );
		Admin_Ui::row( __( 'Address', '3best-core' ), (string) $lead['address'] );
		echo '</tbody></table>';

		/* Consent and verification ----------------------------------------- */
		$verified = 1 === (int) $lead['phone_verified'];
		$consent  = 1 === (int) $lead['consent'];

		echo '<h2>' . esc_html__( 'Consent and verification', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:860px"><tbody>';

		Admin_Ui::row_html(
			__( 'Phone verified', '3best-core' ),
			Admin_Ui::badge( $verified, $verified ? __( 'yes', '3best-core' ) : __( 'no', '3best-core' ) )
		);

		Admin_Ui::row( __( 'Verified at', '3best-core' ), Admin_Ui::local_time( $lead['phone_verified_at'] ) );

		Admin_Ui::row_html(
			__( 'Consent given', '3best-core' ),
			Admin_Ui::badge( $consent, $consent ? __( 'yes', '3best-core' ) : __( 'no', '3best-core' ) )
		);

		Admin_Ui::row( __( 'Consent version', '3best-core' ), (string) $lead['consent_version'] );
		Admin_Ui::row( __( 'Consent given at', '3best-core' ), Admin_Ui::local_time( $lead['consent_at'] ) );

		$text = (string) $lead['consent_text'];

		Admin_Ui::row(
			__( 'Wording shown', '3best-core' ),
			'' === $text ? Consent::text_for( (string) $lead['consent_version'] ) : $text
		);

		// The IP and user agent are part of the consent record and are shown
		// here because that is what makes the record evidential.
		Admin_Ui::row( __( 'Consent IP', '3best-core' ), (string) $lead['consent_ip'] );
		Admin_Ui::row( __( 'Consent user agent', '3best-core' ), (string) $lead['consent_user_agent'] );

		echo '</tbody></table>';

		/* Delivery --------------------------------------------------------- */
		echo '<h2>' . esc_html__( 'Delivery', '3best-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:860px"><tbody>';
		Admin_Ui::row_html( __( 'Status', '3best-core' ), self::delivery_cell( $lead ) );
		Admin_Ui::row( __( 'Attempts', '3best-core' ), (string) (int) $lead['delivery_attempts'] );
		Admin_Ui::row( __( 'Last attempt', '3best-core' ), Admin_Ui::local_time( $lead['delivery_last_attempt_at'] ) );
		Admin_Ui::row( __( 'Completed', '3best-core' ), Admin_Ui::local_time( $lead['delivery_completed_at'] ) );
		Admin_Ui::row( __( 'Last error', '3best-core' ), '' === (string) $lead['delivery_last_error'] ? '—' : (string) $lead['delivery_last_error'] );
		echo '</tbody></table>';

		if ( 'delivered' !== $lead['delivery_status'] ) {
			echo '<p>' . Delivery_Admin::requeue_button( (int) $lead['id'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- requeue_button() escapes.
		}

		/* Attribution ------------------------------------------------------ */
		$utm = array_filter(
			array(
				'utm_source'   => (string) $lead['utm_source'],
				'utm_medium'   => (string) $lead['utm_medium'],
				'utm_campaign' => (string) $lead['utm_campaign'],
				'utm_term'     => (string) $lead['utm_term'],
				'utm_content'  => (string) $lead['utm_content'],
			),
			static fn( string $value ): bool => '' !== $value
		);

		if ( array() !== $utm || '' !== (string) $lead['referrer'] ) {
			echo '<h2>' . esc_html__( 'Attribution', '3best-core' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:860px"><tbody>';

			foreach ( $utm as $key => $value ) {
				Admin_Ui::row( $key, $value );
			}

			if ( '' !== (string) $lead['referrer'] ) {
				Admin_Ui::row( __( 'Referrer', '3best-core' ), (string) $lead['referrer'] );
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Filter controls.
	 *
	 * @param array<string,string> $filters Current filters.
	 * @param int                  $total   Matching rows.
	 */
	private static function render_filters( array $filters, int $total ): void {
		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';

		$services = array();

		foreach ( Services_Repository::all() as $service ) {
			$services[ (string) $service['slug'] ] = (string) $service['name'];
		}

		Admin_Ui::select( 'service', $services, $filters['service'], __( 'All services', '3best-core' ) );

		Admin_Ui::select(
			'delivery_status',
			array(
				'pending'    => __( 'Pending', '3best-core' ),
				'processing' => __( 'Processing', '3best-core' ),
				'delivered'  => __( 'Delivered', '3best-core' ),
				'retrying'   => __( 'Retrying', '3best-core' ),
				'failed'     => __( 'Failed', '3best-core' ),
			),
			$filters['delivery_status'],
			__( 'Any delivery state', '3best-core' )
		);

		Admin_Ui::select(
			'verified',
			array(
				'1' => __( 'Verified only', '3best-core' ),
				'0' => __( 'Unverified only', '3best-core' ),
			),
			$filters['verified'],
			__( 'Any verification state', '3best-core' )
		);

		submit_button( __( 'Filter', '3best-core' ), 'secondary', '', false );

		echo ' <span class="description">' . sprintf(
			/* translators: %d: number of leads */
			esc_html__( '%d matching', '3best-core' ),
			(int) $total
		) . '</span>';

		echo '</form>';
	}

	/**
	 * A coloured delivery state cell.
	 *
	 * @param array<string,mixed> $row Lead row.
	 */
	private static function delivery_cell( array $row ): string {
		$status   = (string) $row['delivery_status'];
		$attempts = (int) $row['delivery_attempts'];

		$healthy = in_array( $status, array( 'delivered', 'pending', 'processing' ), true );

		$label = $attempts > 0
			? sprintf(
				/* translators: 1: delivery status, 2: attempt count */
				__( '%1$s (%2$d attempts)', '3best-core' ),
				$status,
				$attempts
			)
			: $status;

		return Admin_Ui::badge( $healthy, $label );
	}

	/**
	 * A human place label from a lead row.
	 *
	 * @param array<string,mixed> $row Lead row.
	 */
	private static function place( array $row ): string {
		$city  = (string) $row['city'];
		$state = (string) $row['state_code'];

		if ( '' !== $city && '' !== $state ) {
			return $city . ', ' . $state;
		}

		if ( '' !== $city ) {
			return $city;
		}

		return '' !== $state ? $state : (string) $row['location_slug'];
	}

	/**
	 * Confirmation after a requeue triggered from a lead.
	 */
	private static function render_requeue_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- rendering our own redirect parameter.
		if ( isset( $_GET['tb_requeued'] ) ) {
			Admin_Ui::notice( 'success', __( 'Lead put back in the delivery queue.', '3best-core' ) );
		}
	}

	/**
	 * Page links.
	 *
	 * @param int                  $total   Total rows.
	 * @param int                  $paged   Current page.
	 * @param array<string,string> $filters Active filters.
	 */
	private static function render_pagination( int $total, int $paged, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		echo '<p>';

		for ( $i = 1; $i <= $pages; $i++ ) {
			if ( $i === $paged ) {
				echo '<strong style="margin-right:8px">' . esc_html( (string) $i ) . '</strong>';

				continue;
			}

			echo '<a style="margin-right:8px" href="' . esc_url( Admin_Ui::url( self::MENU_SLUG, array_merge( $filters, array( 'paged' => $i ) ) ) ) . '">' . esc_html( (string) $i ) . '</a>';
		}

		echo '</p>';
	}
}
