<?php
/**
 * Service/location combination management.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Seo\Service_Locations;
use ThreeBest\Core\Seo\Slug;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The admin control confirmed decision 8 asked for.
 *
 * Phase 7 made indexability a stored decision and seeded the 14 approved
 * combinations; until now there was no way to change that set without SQL.
 *
 * ---------------------------------------------------------------------------
 * RESOLVABLE IS NOT INDEXABLE
 * ---------------------------------------------------------------------------
 * Every valid service/location pair renders a page. Only a pair recorded here
 * with is_indexable = 1 and status = active is offered to search engines. This
 * screen edits the second decision and never the first — removing a row does
 * not break a URL, it only withdraws the approval.
 *
 * The table stays SPARSE. There is no "approve everything" control, because 16
 * services across the curated catalogue is several hundred thin pages and that
 * is precisely the outcome decision 8 exists to prevent.
 *
 * Both halves of a combination are chosen from the catalogue, never typed, and
 * Service_Locations::save() resolves them again server-side before writing. A
 * row that claimed to be indexable for a slug nothing can resolve would put a
 * 404 into a sitemap.
 */
final class Seo_Pages_Screen {

	public const MENU_SLUG  = 'threebest-seo-pages';
	public const CAPABILITY = 'manage_options';

	private const PER_PAGE = 25;

	private const ACTION_SAVE   = 'threebest_save_combination';
	private const ACTION_TOGGLE = 'threebest_toggle_combination';
	private const ACTION_DELETE = 'threebest_delete_combination';

	/**
	 * Registers the write handlers.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_TOGGLE, array( self::class, 'handle_toggle' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
	}

	/**
	 * Creates or updates a combination.
	 */
	public static function handle_save(): void {
		self::guard( self::ACTION_SAVE );

		$service  = Sanitizer::slug( $_POST['service_slug'] ?? '', 64 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitizer::slug strips everything but [a-z0-9-].
		$location = Sanitizer::slug( $_POST['location_slug'] ?? '', 96 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- as above.

		$fields = array(
			'is_indexable'     => isset( $_POST['is_indexable'] ),
			'status'           => Sanitizer::choice( $_POST['status'] ?? 'active', array( 'active', 'paused' ), 'active' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- choice() returns one of the listed literals.
			'seo_title'        => Sanitizer::text( wp_unslash( $_POST['seo_title'] ?? '' ), 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised here.
			'seo_description'  => Sanitizer::text( wp_unslash( $_POST['seo_description'] ?? '' ), 320 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised here.
			'notes'            => Sanitizer::text( wp_unslash( $_POST['notes'] ?? '' ), 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised here.
			'sitemap_priority' => Sanitizer::text( $_POST['sitemap_priority'] ?? '', 4 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- numeric text, cast in save().
		);

		$saved = Service_Locations::save( $service, $location, $fields );

		if ( '' === $saved ) {
			self::redirect( array( 'tb_error' => 'unresolvable' ) );
		}

		Logger::log(
			'SEO combination saved.',
			array(
				'combo'     => $saved,
				'indexable' => (bool) $fields['is_indexable'],
				'by'        => get_current_user_id(),
			)
		);

		self::redirect( array( 'tb_saved' => $saved ) );
	}

	/**
	 * Flips one combination's indexable flag.
	 */
	public static function handle_toggle(): void {
		$slug = Sanitizer::slug( $_GET['combo'] ?? '', 191 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitizer::slug strips everything but [a-z0-9-].

		self::guard( self::ACTION_TOGGLE . '_' . $slug, 'GET' );

		$record = Service_Locations::record( $slug );

		if ( null === $record ) {
			self::redirect( array( 'tb_error' => 'missing' ) );
		}

		$now = 1 !== (int) $record['is_indexable'];

		Service_Locations::set_indexable( $slug, $now );

		Logger::log(
			'SEO combination indexability changed.',
			array(
				'combo'     => $slug,
				'indexable' => $now,
				'by'        => get_current_user_id(),
			)
		);

		self::redirect( array( 'tb_toggled' => $slug ) );
	}

	/**
	 * Removes a recorded decision.
	 */
	public static function handle_delete(): void {
		$slug = Sanitizer::slug( $_GET['combo'] ?? '', 191 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitizer::slug strips everything but [a-z0-9-].

		self::guard( self::ACTION_DELETE . '_' . $slug, 'GET' );

		Service_Locations::delete( $slug );

		Logger::log(
			'SEO combination removed.',
			array(
				'combo' => $slug,
				'by'    => get_current_user_id(),
			)
		);

		self::redirect( array( 'tb_deleted' => $slug ) );
	}

	/**
	 * Capability plus nonce, for every write on this screen.
	 *
	 * @param string $nonce  Nonce action.
	 * @param string $method 'POST' or 'GET'.
	 */
	private static function guard( string $nonce, string $method = 'POST' ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', '3best-core' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce );

		unset( $method );
	}

	/**
	 * Post/redirect/get, so a refresh cannot replay a write.
	 *
	 * @param array<string,mixed> $args Query arguments to carry back.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Admin_Ui::url( self::MENU_SLUG, $args ) );

		exit;
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', '3best-core' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only listing state.
		$filters = array(
			'service'   => Sanitizer::slug( $_GET['service'] ?? '', 64 ),
			'indexable' => Sanitizer::choice( $_GET['indexable'] ?? '', array( '1', '0' ), '' ),
		);

		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		$edit  = Sanitizer::slug( $_GET['edit'] ?? '', 191 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$total = Service_Locations::count( $filters );
		$rows  = Service_Locations::page( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $filters );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SEO Pages', '3best-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Every valid service and location combination renders a page. Only the combinations recorded here are offered to search engines.', '3best-core' ) . '</p>';

		self::render_notices();
		self::render_editor( '' === $edit ? null : Service_Locations::record( $edit ) );
		self::render_filters( $filters, $total );
		self::render_table( $rows );
		self::render_pagination( $total, $paged, $filters );

		echo '</div>';
	}

	/**
	 * Outcome messages from the redirect.
	 */
	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- rendering our own redirect parameters.
		if ( isset( $_GET['tb_error'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['tb_error'] ) );

			Admin_Ui::notice(
				'error',
				'unresolvable' === $code
					? __( 'That service and location pair does not resolve, so nothing was saved.', '3best-core' )
					: __( 'That combination no longer exists.', '3best-core' )
			);
		}

		foreach ( array(
			'tb_saved'   => __( 'Combination saved: %s', '3best-core' ),
			'tb_toggled' => __( 'Indexability updated: %s', '3best-core' ),
			'tb_deleted' => __( 'Combination removed: %s', '3best-core' ),
		) as $param => $template ) {
			if ( ! isset( $_GET[ $param ] ) ) {
				continue;
			}

			Admin_Ui::notice(
				'success',
				sprintf( $template, Sanitizer::slug( $_GET[ $param ], 191 ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The add/edit form.
	 *
	 * @param array<string,mixed>|null $record Row being edited, or null to add.
	 */
	private static function render_editor( ?array $record ): void {
		$editing = null !== $record;

		echo '<h2>' . esc_html( $editing ? __( 'Edit combination', '3best-core' ) : __( 'Add a combination', '3best-core' ) ) . '</h2>';

		Admin_Ui::open_form( self::ACTION_SAVE );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="service_slug">' . esc_html__( 'Service', '3best-core' ) . '</label></th><td>';
		Admin_Ui::select(
			'service_slug',
			self::service_choices(),
			$editing ? (string) $record['service_slug'] : '',
			__( '— choose a service —', '3best-core' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="location_slug">' . esc_html__( 'Location', '3best-core' ) . '</label></th><td>';
		Admin_Ui::select(
			'location_slug',
			self::location_choices(),
			$editing ? (string) $record['location_slug'] : '',
			__( '— choose a location —', '3best-core' )
		);
		echo '<p class="description">' . esc_html__( 'Both halves come from the catalogue. A pair that cannot resolve is refused.', '3best-core' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Indexable', '3best-core' ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="is_indexable" value="1"%s> %s</label>',
			checked( $editing && 1 === (int) $record['is_indexable'], true, false ),
			esc_html__( 'Offer this page to search engines', '3best-core' )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="status">' . esc_html__( 'Status', '3best-core' ) . '</label></th><td>';
		Admin_Ui::select(
			'status',
			array(
				'active' => __( 'Active', '3best-core' ),
				'paused' => __( 'Paused', '3best-core' ),
			),
			$editing ? (string) $record['status'] : 'active'
		);
		echo '<p class="description">' . esc_html__( 'A paused combination is never indexable, whatever the checkbox says.', '3best-core' ) . '</p>';
		echo '</td></tr>';

		self::text_row( 'seo_title', __( 'Title override', '3best-core' ), $editing ? (string) $record['seo_title'] : '', 255, __( 'Leave empty to use the generated title.', '3best-core' ) );
		self::text_row( 'seo_description', __( 'Description override', '3best-core' ), $editing ? (string) $record['seo_description'] : '', 320, __( 'Leave empty to use the generated description.', '3best-core' ) );
		self::text_row( 'notes', __( 'Internal notes', '3best-core' ), $editing ? (string) $record['notes'] : '', 255, __( 'Not published anywhere.', '3best-core' ) );

		echo '<tr><th scope="row"><label for="sitemap_priority">' . esc_html__( 'Sitemap priority', '3best-core' ) . '</label></th><td>';
		printf(
			'<input type="number" class="small-text" name="sitemap_priority" id="sitemap_priority" value="%s" min="0.1" max="1.0" step="0.1">',
			esc_attr( $editing && null !== $record['sitemap_priority'] ? (string) $record['sitemap_priority'] : '' )
		);
		echo '<p class="description">' . esc_html__( 'Optional. No sitemap is generated yet; this is recorded for when one is.', '3best-core' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( $editing ? __( 'Update combination', '3best-core' ) : __( 'Add combination', '3best-core' ) );

		echo '</form>';

		if ( $editing ) {
			echo '<p><a href="' . esc_url( Admin_Ui::url( self::MENU_SLUG ) ) . '">' . esc_html__( 'Cancel editing', '3best-core' ) . '</a></p>';
		}
	}

	/**
	 * A labelled text input row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Field label.
	 * @param string $value Current value.
	 * @param int    $max   Maximum length.
	 * @param string $help  Description.
	 */
	private static function text_row( string $name, string $label, string $value, int $max, string $help ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		printf(
			'<input type="text" class="large-text" name="%1$s" id="%1$s" value="%2$s" maxlength="%3$d">',
			esc_attr( $name ),
			esc_attr( $value ),
			$max
		);
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Filter controls.
	 *
	 * @param array<string,string> $filters Current filters.
	 * @param int                  $total   Matching rows.
	 */
	private static function render_filters( array $filters, int $total ): void {
		echo '<h2>' . sprintf(
			/* translators: %d: number of recorded combinations */
			esc_html__( 'Recorded combinations (%d)', '3best-core' ),
			(int) $total
		) . '</h2>';

		echo '<form method="get" style="margin-bottom:12px">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';

		Admin_Ui::select( 'service', self::service_choices(), $filters['service'], __( 'All services', '3best-core' ) );

		Admin_Ui::select(
			'indexable',
			array(
				'1' => __( 'Indexable only', '3best-core' ),
				'0' => __( 'Not indexable', '3best-core' ),
			),
			$filters['indexable'],
			__( 'Any indexability', '3best-core' )
		);

		submit_button( __( 'Filter', '3best-core' ), 'secondary', '', false );

		echo '</form>';
	}

	/**
	 * The listing table.
	 *
	 * @param array<int,array<string,mixed>> $rows Combination rows.
	 */
	private static function render_table( array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Combination', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Indexable', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Overrides', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No combinations recorded.', '3best-core' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$slug      = (string) $row['combo_slug'];
			$indexable = 1 === (int) $row['is_indexable'];
			$active    = 'active' === $row['status'];

			echo '<tr>';

			echo '<td><strong>' . esc_html( $slug ) . '</strong><br><a href="' . esc_url( home_url( '/' . $slug . '/' ) ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'View page', '3best-core' ) . '</a></td>';

			echo '<td>' . Admin_Ui::badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				$indexable && $active,
				$indexable ? __( 'yes', '3best-core' ) : __( 'no', '3best-core' )
			) . '</td>';

			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';

			$overrides = array();

			if ( '' !== (string) $row['seo_title'] ) {
				$overrides[] = __( 'title', '3best-core' );
			}

			if ( '' !== (string) $row['seo_description'] ) {
				$overrides[] = __( 'description', '3best-core' );
			}

			echo '<td>' . esc_html( array() === $overrides ? '—' : implode( ', ', $overrides ) ) . '</td>';

			echo '<td>' . esc_html( Admin_Ui::local_time( (string) $row['updated_at'] ) ) . '</td>';

			echo '<td>';
			echo '<a href="' . esc_url( Admin_Ui::url( self::MENU_SLUG, array( 'edit' => $slug ) ) ) . '">' . esc_html__( 'Edit', '3best-core' ) . '</a> | ';
			echo '<a href="' . esc_url( self::action_url( self::ACTION_TOGGLE, $slug ) ) . '">' . esc_html( $indexable ? __( 'Withdraw', '3best-core' ) : __( 'Approve', '3best-core' ) ) . '</a> | ';
			echo '<a href="' . esc_url( self::action_url( self::ACTION_DELETE, $slug ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Remove this recorded decision? The page will keep rendering but will no longer be indexable.', '3best-core' ) ) ) . ');">' . esc_html__( 'Remove', '3best-core' ) . '</a>';
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A nonce-protected admin-post URL for one combination.
	 *
	 * @param string $action Action name.
	 * @param string $slug   Combination slug.
	 */
	private static function action_url( string $action, string $slug ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'combo'  => $slug,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $slug
		);
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
			$url = Admin_Ui::url( self::MENU_SLUG, array_merge( $filters, array( 'paged' => $i ) ) );

			if ( $i === $paged ) {
				echo '<strong style="margin-right:8px">' . esc_html( (string) $i ) . '</strong>';

				continue;
			}

			echo '<a style="margin-right:8px" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>';
		}

		echo '</p>';
	}

	/**
	 * Services, as slug => name.
	 *
	 * @return array<string,string>
	 */
	private static function service_choices(): array {
		$choices = array();

		foreach ( Services_Repository::all() as $service ) {
			$choices[ (string) $service['slug'] ] = (string) $service['name'];
		}

		return $choices;
	}

	/**
	 * Catalogue locations, as slug => label.
	 *
	 * @return array<string,string>
	 */
	private static function location_choices(): array {
		$choices = array();

		foreach ( Catalog::areas() as $location ) {
			$choices[ $location->slug ] = Labels::label( $location );
		}

		asort( $choices );

		return $choices;
	}

	/**
	 * The canonical slug a pair would produce, for the overview screen.
	 *
	 * @param string $service_slug  Service slug.
	 * @param string $location_slug Location slug.
	 */
	public static function preview_slug( string $service_slug, string $location_slug ): string {
		$service  = Services_Repository::by_slug( $service_slug );
		$location = Catalog::by_slug( $location_slug );

		return ( null === $service || null === $location ) ? '' : Slug::build( $service, $location );
	}
}
