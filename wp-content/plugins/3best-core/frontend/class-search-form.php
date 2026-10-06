<?php
/**
 * Front-end search form and service listings.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Frontend;

use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Fills the extension points the Phase 3 theme left open.
 *
 * Phase 3 shipped the layout with two documented slots and theme-side
 * fallbacks. This class takes them over, so the templates themselves need no
 * edits and the theme keeps working on its own if the plugin is deactivated:
 *
 *   threebest_search_form        action  -> the real two-question form
 *   threebest_service_categories filter  -> the authoritative 4 categories
 *   threebest_category_services  action  -> the 16 services and 64 subcategories
 *
 * Markup only. Behaviour lives in assets/js/search.js, and presentation lives
 * in the theme stylesheet, so this file contains no styling decisions and no
 * event handling.
 *
 * The markup is a progressive-enhancement shell: it renders as a normal form
 * with real labels and inputs. JavaScript upgrades both fields into comboboxes.
 */
final class Search_Form {

	/**
	 * Service slugs offered as quick chips beneath the form.
	 */
	private const POPULAR = array( 'plumber', 'hvac', 'electrician', 'roofing' );

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'threebest_search_form', array( self::class, 'render' ), 10, 1 );
		add_filter( 'threebest_service_categories', array( self::class, 'categories' ) );
		add_action( 'threebest_category_services', array( self::class, 'render_category_services' ), 10, 1 );
	}

	/**
	 * Supplies the authoritative service categories to the theme.
	 *
	 * Replaces the theme's presentational fallback with the real catalogue, so
	 * the homepage cards and the services page are driven by one source.
	 *
	 * @param array<int,array<string,string>> $fallback Theme-supplied categories.
	 * @return array<int,array<string,string>>
	 */
	public static function categories( $fallback ): array {
		$categories = Services_Repository::categories();

		return array() !== $categories ? $categories : (array) $fallback;
	}

	/**
	 * Renders the two-question search form.
	 *
	 * @param string $context Where it is rendered: 'home' or 'services'.
	 */
	public static function render( string $context = 'home' ): void {
		$stacked  = 'home' === $context;
		$field_id = 'tb-search-' . sanitize_key( $context );

		$popular = array();

		foreach ( self::POPULAR as $slug ) {
			$service = Services_Repository::by_slug( $slug );

			if ( null !== $service ) {
				$popular[] = $service;
			}
		}
		?>
		<form
			class="tb-search"
			data-tb-search-form
			method="get"
			action="<?php echo esc_url( home_url( '/' ) ); ?>"
			novalidate
		>
			<div class="tb-search__fields<?php echo $stacked ? ' tb-search__fields--stacked' : ''; ?>">

				<?php
				self::render_combobox(
					array(
						'id'          => $field_id . '-service',
						'kind'        => 'service',
						'label'       => __( 'What service do you need?', '3best-core' ),
						'placeholder' => __( 'What service do you need?', '3best-core' ),
						'icon'        => 'search',
						'error'       => __( 'Please choose the service you need.', '3best-core' ),
					)
				);

				self::render_combobox(
					array(
						'id'          => $field_id . '-location',
						'kind'        => 'location',
						'label'       => __( 'Where do you need the service?', '3best-core' ),
						'placeholder' => __( 'City, State, ZIP code or landmark', '3best-core' ),
						'icon'        => 'map-pin',
						'error'       => __( 'Please select a location from the suggestions.', '3best-core' ),
					)
				);
				?>

				<button type="submit" class="tb-button tb-button--primary tb-button--lg tb-search__submit" data-tb-submit>
					<span data-tb-submit-label><?php esc_html_e( 'Find My 3 Best', '3best-core' ); ?></span>
				</button>
			</div>

			<?php if ( 'home' === $context && array() !== $popular ) : ?>
				<div class="tb-search__popular">
					<span class="tb-search__popular-label"><?php esc_html_e( 'Popular:', '3best-core' ); ?></span>
					<?php foreach ( $popular as $service ) : ?>
						<button
							type="button"
							class="tb-search__chip"
							data-tb-chip
							data-service-slug="<?php echo esc_attr( $service['slug'] ); ?>"
							data-service-name="<?php echo esc_attr( $service['name'] ); ?>"
						><?php echo esc_html( $service['name'] ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Renders one combobox field.
	 *
	 * Both fields share this shell so they look and behave identically, exactly
	 * as the React implementation shares one Combobox stylesheet.
	 *
	 * @param array<string,string> $args id, kind, label, placeholder, icon, error.
	 */
	private static function render_combobox( array $args ): void {
		$id          = $args['id'];
		$listbox_id  = $id . '-listbox';
		$error_id    = $id . '-error';
		$is_location = 'location' === $args['kind'];
		?>
		<div class="tb-combobox" data-tb-combobox="<?php echo esc_attr( $args['kind'] ); ?>">
			<label class="sr-only" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $args['label'] ); ?>
			</label>

			<div class="tb-combobox__wrap" data-tb-wrap>
				<span class="tb-combobox__icon" aria-hidden="true">
					<?php echo Icons::svg( $args['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
				</span>

				<input
					type="text"
					class="tb-combobox__input"
					id="<?php echo esc_attr( $id ); ?>"
					role="combobox"
					autocomplete="off"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $listbox_id ); ?>"
					aria-autocomplete="list"
					aria-describedby="<?php echo esc_attr( $error_id ); ?>"
					placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
					data-tb-input
					<?php echo $is_location ? 'data-min-length="' . (int) Catalog::MIN_QUERY_LENGTH . '"' : ''; ?>
				>

				<span class="tb-combobox__status" data-tb-spinner hidden aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="tb-spin" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9"/></svg>
				</span>

				<button type="button" class="tb-combobox__clear" data-tb-clear hidden
					aria-label="<?php echo esc_attr( $is_location ? __( 'Clear location', '3best-core' ) : __( 'Clear selected service', '3best-core' ) ); ?>">
					<?php echo Icons::svg( 'close', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
				</button>
			</div>

			<div
				class="tb-combobox__panel"
				id="<?php echo esc_attr( $listbox_id ); ?>"
				role="listbox"
				aria-label="<?php echo esc_attr( $is_location ? __( 'Location suggestions', '3best-core' ) : __( 'Services', '3best-core' ) ); ?>"
				data-tb-panel
				hidden
			></div>

			<!-- The canonical record the user picked. Empty until a real
			     selection is made, which is what stops typed text ever being
			     treated as a service or a location. -->
			<input type="hidden" data-tb-value value="">

			<span class="tb-combobox__error" id="<?php echo esc_attr( $error_id ); ?>" data-tb-error hidden></span>
			<span class="sr-only" role="status" aria-live="polite" data-tb-live></span>
		</div>
		<?php
	}

	/**
	 * Renders the services inside one category on the services page.
	 *
	 * @param array<string,string> $category Category being rendered.
	 */
	public static function render_category_services( $category ): void {
		if ( ! is_array( $category ) || ! isset( $category['id'] ) ) {
			return;
		}

		$services = Services_Repository::in_category( (string) $category['id'] );

		if ( array() === $services ) {
			return;
		}
		?>
		<div class="tb-services__grid">
			<?php foreach ( $services as $service ) : ?>
				<article class="tb-services__service" id="<?php echo esc_attr( $service['slug'] ); ?>">
					<h3 class="tb-services__service-name"><?php echo esc_html( $service['name'] ); ?></h3>
					<p class="tb-services__service-description"><?php echo esc_html( $service['description'] ); ?></p>
					<ul class="tb-services__sub-list">
						<?php foreach ( $service['subcategories'] as $subcategory ) : ?>
							<li class="tb-services__sub-item"><?php echo esc_html( $subcategory['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
