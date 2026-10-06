<?php
/**
 * Delivery visibility and manual controls.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

use ThreeBest\Core\Delivery\Delivery_Queue;
use ThreeBest\Core\Delivery\Delivery_Scheduler;
use ThreeBest\Core\Leads\Lead_Repository;
use ThreeBest\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Shows delivery state and lets an administrator run the queue by hand.
 *
 * Minimal on purpose: the operational question is "is delivery working", and
 * answering it needs counts, a schedule and a short recent list. Not a
 * dashboard, and not a lead browser — the full admin is Phase 8.
 *
 * NO PERSONAL DATA IS SHOWN. The table lists the lead reference, a date, a
 * status, an attempt count and a short failure code. No name, email, phone or
 * address appears anywhere on this screen, because none of it helps diagnose a
 * webhook and all of it would widen the blast radius of a compromised admin
 * account.
 *
 * The manual trigger is the one write path here, and it is protected the way
 * every WordPress write should be: an admin-post handler, a capability check,
 * a nonce, and a redirect afterwards so a refresh cannot replay it.
 */
final class Delivery_Admin {

	public const CAPABILITY = 'manage_options';

	private const ACTION_RUN     = 'threebest_run_delivery_queue';
	private const ACTION_REQUEUE = 'threebest_requeue_lead';

	/**
	 * Registers the handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_RUN, array( self::class, 'handle_run' ) );
		add_action( 'admin_post_' . self::ACTION_REQUEUE, array( self::class, 'handle_requeue' ) );
	}

	/**
	 * Processes one batch, triggered from the admin screen.
	 *
	 * Deliberately NOT a REST route and NOT reachable by a public consumer.
	 * admin-post.php plus a capability check plus a nonce is the narrowest
	 * surface that does the job.
	 */
	public static function handle_run(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', '3best-core' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_RUN );

		$summary = Delivery_Queue::process();

		Logger::log( 'Delivery queue run manually.', array_merge( $summary, array( 'by' => get_current_user_id() ) ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => Status_Page::MENU_SLUG,
					'tb_delivered' => (int) $summary['delivered'],
					'tb_retrying'  => (int) $summary['retrying'],
					'tb_failed'    => (int) $summary['failed'],
					'tb_skipped'   => (int) $summary['skipped'],
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Puts one lead back in the queue.
	 */
	public static function handle_requeue(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', '3best-core' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['lead'] ) ? absint( wp_unslash( $_GET['lead'] ) ) : 0;

		check_admin_referer( self::ACTION_REQUEUE . '_' . $id );

		if ( $id > 0 ) {
			Delivery_Queue::requeue( $id );
			Logger::log( 'Lead requeued for delivery.', array( 'lead_id' => $id, 'by' => get_current_user_id() ) );
		}

		wp_safe_redirect( self::requeue_destination( $id ) );

		exit;
	}

	/**
	 * Renders the delivery section of the status screen.
	 */
	public static function render(): void {
		$counts   = Lead_Repository::delivery_counts();
		$provider = Delivery_Queue::provider();
		$next     = Delivery_Scheduler::next_run();
		$events   = Delivery_Scheduler::scheduled_count();

		echo '<h2>' . esc_html__( 'Lead delivery', '3best-core' ) . '</h2>';

		self::render_run_notice();

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';

		self::row(
			__( 'Delivery', '3best-core' ),
			Delivery_Queue::is_enabled()
				? self::badge( true, __( 'enabled', '3best-core' ) )
				: self::badge( false, __( 'disabled — leads are queued but not sent', '3best-core' ) )
		);

		self::row( __( 'Provider', '3best-core' ), esc_html( $provider->name() ) );

		$config_error = $provider->configuration_error();

		self::row(
			__( 'Webhook configuration', '3best-core' ),
			'' === $config_error
				? self::badge( true, __( 'configured', '3best-core' ) )
				: self::badge( false, esc_html( $config_error ) )
		);

		self::row( __( 'Batch size', '3best-core' ), esc_html( (string) Delivery_Queue::batch_size() ) );
		self::row( __( 'Max attempts', '3best-core' ), esc_html( (string) Delivery_Queue::max_attempts() ) );

		self::row(
			__( 'Next scheduled run', '3best-core' ),
			0 === $next
				? self::badge( false, __( 'not scheduled', '3best-core' ) )
				: esc_html( wp_date( 'Y-m-d H:i:s', $next ) )
		);

		self::row(
			__( 'Scheduled events', '3best-core' ),
			1 === $events
				? self::badge( true, __( '1 (correct)', '3best-core' ) )
				: self::badge( false, sprintf( /* translators: %d: number of events */ __( '%d — duplicates present', '3best-core' ), $events ) )
		);

		echo '</tbody></table>';

		/* Counts ---------------------------------------------------------- */
		echo '<h3>' . esc_html__( 'Queue', '3best-core' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';

		foreach ( array_keys( $counts ) as $status ) {
			echo '<th>' . esc_html( $status ) . '</th>';
		}

		echo '</tr></thead><tbody><tr>';

		foreach ( $counts as $total ) {
			echo '<td>' . esc_html( (string) $total ) . '</td>';
		}

		echo '</tr></tbody></table>';

		/* Manual trigger --------------------------------------------------- */
		echo '<p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_RUN );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RUN ) . '">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Process delivery queue now', '3best-core' ) . '</button>';
		echo '</form></p>';

		self::render_recent();
	}

	/**
	 * Shows the outcome of a manual run.
	 */
	private static function render_run_notice(): void {
		// Read-only display of our own redirect parameters; no state changes
		// here, so no nonce is needed to render a number.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tb_delivered'] ) ) {
			return;
		}

		$delivered = absint( wp_unslash( $_GET['tb_delivered'] ) );
		$retrying  = isset( $_GET['tb_retrying'] ) ? absint( wp_unslash( $_GET['tb_retrying'] ) ) : 0;
		$failed    = isset( $_GET['tb_failed'] ) ? absint( wp_unslash( $_GET['tb_failed'] ) ) : 0;
		$skipped   = isset( $_GET['tb_skipped'] ) ? absint( wp_unslash( $_GET['tb_skipped'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $skipped > 0 ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Delivery is disabled, so nothing was sent. Leads remain queued.', '3best-core' );
			echo '</p></div>';

			return;
		}

		echo '<div class="notice notice-info"><p>';
		printf(
			/* translators: 1: delivered count, 2: retrying count, 3: failed count */
			esc_html__( 'Queue run finished: %1$d delivered, %2$d will retry, %3$d failed.', '3best-core' ),
			(int) $delivered,
			(int) $retrying,
			(int) $failed
		);
		echo '</p></div>';
	}

	/**
	 * Recent leads, delivery state only.
	 */
	private static function render_recent(): void {
		$rows = Lead_Repository::recent_delivery_state( 15 );

		if ( array() === $rows ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Recent leads', '3best-core' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Delivery state only — no personal data is shown on this screen.', '3best-core' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';

		foreach (
			array(
				__( 'Reference', '3best-core' ),
				__( 'Created', '3best-core' ),
				__( 'Delivery', '3best-core' ),
				__( 'Attempts', '3best-core' ),
				__( 'Last attempt', '3best-core' ),
				__( 'Last result', '3best-core' ),
				__( 'Action', '3best-core' ),
			) as $heading
		) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$status = (string) $row['delivery_status'];

			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $row['lead_uid'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td>';
			echo '<td>' . self::badge( 'delivered' === $status, esc_html( $status ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['delivery_attempts'] ) . '</td>';
			echo '<td>' . esc_html( null === $row['delivery_last_attempt_at'] ? '—' : (string) $row['delivery_last_attempt_at'] ) . '</td>';
			echo '<td><code>' . esc_html( '' === $row['delivery_last_error'] ? '—' : (string) $row['delivery_last_error'] ) . '</code></td>';
			echo '<td>';

			if ( 'delivered' !== $status ) {
				printf(
					'<a class="button button-small" href="%s">%s</a>',
					esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'action' => self::ACTION_REQUEUE,
									'lead'   => $id,
								),
								admin_url( 'admin-post.php' )
							),
							self::ACTION_REQUEUE . '_' . $id
						)
					),
					esc_html__( 'Requeue', '3best-core' )
				);
			} else {
				echo '—';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A nonce-protected requeue control for one lead.
	 *
	 * Exposed so the Phase 8 lead screen can offer the same action without a
	 * second handler: one write path, one capability check, one nonce scheme.
	 *
	 * @param int $id Lead id.
	 * @return string Escaped markup.
	 */
	public static function requeue_button( int $id ): string {
		return sprintf(
			'<a class="button button-secondary" href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION_REQUEUE,
							'lead'   => $id,
							'return' => 'lead',
						),
						admin_url( 'admin-post.php' )
					),
					self::ACTION_REQUEUE . '_' . $id
				)
			),
			esc_html__( 'Put back in the delivery queue', '3best-core' )
		);
	}

	/**
	 * Where to go after a requeue.
	 *
	 * The destination is chosen from a fixed set rather than taken from the
	 * request, so the parameter cannot be turned into an open redirect.
	 *
	 * @param int $id Lead id.
	 */
	private static function requeue_destination( int $id ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the action itself is nonce-checked by the caller.
		$return = isset( $_GET['return'] ) ? sanitize_key( wp_unslash( $_GET['return'] ) ) : '';

		if ( 'lead' === $return && $id > 0 ) {
			return Admin_Ui::url(
				Leads_Screen::MENU_SLUG,
				array(
					'lead'        => $id,
					'tb_requeued' => 1,
				)
			);
		}

		return Admin_Ui::url( Status_Page::MENU_SLUG );
	}

	/**
	 * One label/value row.
	 *
	 * @param string $label Plain label.
	 * @param string $value Escaped value.
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:240px">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at each call site.
	}

	/**
	 * Small coloured state badge. Returns escaped markup.
	 *
	 * @param bool   $good  Whether the state is good.
	 * @param string $label Already-escaped text.
	 */
	private static function badge( bool $good, string $label ): string {
		return sprintf(
			'<span style="color:%s;font-weight:600">%s</span>',
			$good ? '#1a7f37' : '#b32d2e',
			$label
		);
	}
}
