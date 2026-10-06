<?php
/**
 * Lead form rendering.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Frontend;

use ThreeBest\Core\Leads\Consent;
use ThreeBest\Core\Location\Catalog;
use ThreeBest\Core\Location\Labels;
use ThreeBest\Core\Location\Location;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Seo\Content_Generator;
use ThreeBest\Core\Services\Services_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the three-stage lead form.
 *
 * Stages mirror the React implementation exactly — form, verification, success
 * — all present in the markup and switched by JavaScript, so there is no flash
 * of the wrong panel and no extra round trip between steps.
 *
 * Placement: the form needs a service and a location, which on the finished
 * site come from the service/location landing page. That page arrives in a
 * later phase, so the form is exposed two ways and is complete either way:
 *
 *   do_action( 'threebest_lead_form', $service_slug, $location_slug )
 *   [threebest_lead_form service="plumber" location="newark-nj"]
 *
 * The landing template will call the action with its own resolved record.
 *
 * Both inputs are RESOLVED here, never echoed. An unknown slug renders nothing
 * rather than a form that cannot submit.
 */
final class Lead_Form {

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'threebest_lead_form', array( self::class, 'render' ), 10, 2 );
		add_shortcode( 'threebest_lead_form', array( self::class, 'shortcode' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'service'  => '',
				'location' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'threebest_lead_form'
		);

		ob_start();
		self::render( (string) $atts['service'], (string) $atts['location'] );

		return (string) ob_get_clean();
	}

	/**
	 * Renders the form for a service/location pair.
	 *
	 * @param string $service_slug  Canonical service slug.
	 * @param string $location_slug Canonical location slug.
	 */
	public static function render( string $service_slug, string $location_slug ): void {
		$service  = Services_Repository::by_slug( Sanitizer::identifier( $service_slug, 64 ) );
		$location = Catalog::by_slug( Sanitizer::identifier( $location_slug, 96 ) );

		if ( null === $service || null === $location ) {
			return;
		}

		$id = 'tb-lead-' . sanitize_key( $service['slug'] . '-' . $location->slug );
		?>
		<div
			class="tb-lead"
			id="lead-form"
			data-tb-lead-form
			data-service="<?php echo esc_attr( $service['slug'] ); ?>"
			data-location="<?php echo esc_attr( $location->slug ); ?>"
		>
			<?php
			self::render_form_stage( $id, $service, $location );
			self::render_otp_stage( $id );
			self::render_success_stage( $id, $service, $location );
			?>
		</div>
		<?php
	}

	/**
	 * Stage 1: the form.
	 *
	 * @param string              $id       Field id prefix.
	 * @param array<string,mixed> $service  Canonical service.
	 * @param Location            $location Canonical location.
	 */
	private static function render_form_stage( string $id, array $service, Location $location ): void {
		?>
		<div class="tb-lead__stage" data-tb-stage="form">
			<div class="tb-lead__header">
				<h2 class="tb-lead__title"><?php esc_html_e( 'Tell us about your project', '3best-core' ); ?></h2>
				<p class="tb-lead__subtitle">
					<?php esc_html_e( 'It takes about a minute. Your details are only used to work on your request.', '3best-core' ); ?>
				</p>
			</div>

			<form novalidate data-tb-lead>
				<div class="tb-lead__grid">

					<div class="tb-field">
						<label class="tb-field__label" for="<?php echo esc_attr( $id ); ?>-service"><?php esc_html_e( 'Service', '3best-core' ); ?></label>
						<input id="<?php echo esc_attr( $id ); ?>-service" class="tb-field__control tb-field__control--readonly"
							value="<?php echo esc_attr( $service['name'] ); ?>" readonly aria-readonly="true" tabindex="-1">
					</div>

					<div class="tb-field">
						<label class="tb-field__label" for="<?php echo esc_attr( $id ); ?>-location"><?php esc_html_e( 'Location', '3best-core' ); ?></label>
						<input id="<?php echo esc_attr( $id ); ?>-location" class="tb-field__control tb-field__control--readonly"
							value="<?php echo esc_attr( Labels::label( $location ) ); ?>" readonly aria-readonly="true" tabindex="-1"
							data-tb-location-label>
					</div>

					<div class="tb-field tb-field--full">
						<label class="tb-field__label" for="<?php echo esc_attr( $id ); ?>-subcategory"><?php esc_html_e( 'What do you need?', '3best-core' ); ?></label>
						<select id="<?php echo esc_attr( $id ); ?>-subcategory" class="tb-field__control" name="subcategoryId" data-tb-field="subcategory"
							aria-describedby="<?php echo esc_attr( $id ); ?>-subcategory-error">
							<option value="">
								<?php
								/*
								 * "a plumbing service" but "an HVAC service" and
								 * "an accounting service". The article is chosen
								 * by the same rule the generated copy uses, so
								 * the form and the page around it agree.
								 */
								printf(
									/* translators: 1: "a" or "an", 2: service term, e.g. "plumbing" */
									esc_html__( 'Select %1$s %2$s service', '3best-core' ),
									esc_html( Content_Generator::indefinite_article( (string) $service['service_term'] ) ),
									esc_html( $service['service_term'] )
								);
								?>
							</option>
							<?php foreach ( $service['subcategories'] as $subcategory ) : ?>
								<option value="<?php echo esc_attr( $subcategory['id'] ); ?>"><?php echo esc_html( $subcategory['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="tb-field__error" id="<?php echo esc_attr( $id ); ?>-subcategory-error" data-tb-error="subcategory" hidden></span>
					</div>

					<?php
					self::render_text_field( $id, 'fullName', __( 'Full Name', '3best-core' ), __( 'Your full name', '3best-core' ), 'name', 'text' );
					self::render_text_field( $id, 'email', __( 'Email', '3best-core' ), __( 'Your email address', '3best-core' ), 'email', 'email' );
					self::render_text_field( $id, 'mobile', __( 'Mobile Number', '3best-core' ), '(555) 555-5555', 'tel-national', 'tel', __( 'We send a one-time code to confirm this number.', '3best-core' ) );
					self::render_text_field( $id, 'address', __( 'Full Address', '3best-core' ), __( 'Street address, City, State, ZIP', '3best-core' ), 'street-address', 'text' );
					?>
				</div>

				<?php
				/*
				 * CONSENT. Placed immediately before the action it governs, so
				 * nobody can reach "Send Verification Code" without having read
				 * the sentence describing exactly that. The whole label is the
				 * click target: the <label> wraps both the box and the text, so
				 * clicking anywhere on the sentence toggles it.
				 */
				?>
				<div class="tb-lead__consent">
					<label class="tb-consent" for="<?php echo esc_attr( $id ); ?>-consent">
						<input
							type="checkbox"
							class="tb-consent__box"
							id="<?php echo esc_attr( $id ); ?>-consent"
							name="consent"
							value="1"
							data-tb-field="consent"
							aria-describedby="<?php echo esc_attr( $id ); ?>-consent-error"
						>
						<span class="tb-consent__text"><?php echo esc_html( Consent::display_text() ); ?></span>
					</label>
					<span class="tb-field__error" id="<?php echo esc_attr( $id ); ?>-consent-error" data-tb-error="consent" hidden></span>
				</div>

				<p class="tb-lead__verified" data-tb-verified hidden>
					<?php esc_html_e( 'Phone number verified', '3best-core' ); ?>
				</p>

				<p class="tb-lead__form-error" role="alert" data-tb-form-error hidden></p>

				<div class="tb-lead__actions">
					<button type="submit" class="tb-button tb-button--primary tb-button--lg tb-button--full" data-tb-submit>
						<span data-tb-submit-label><?php esc_html_e( 'Send Verification Code', '3best-core' ); ?></span>
					</button>
					<p class="tb-lead__note" data-tb-note>
						<?php esc_html_e( 'We’ll text a 6-digit code to confirm your number before submitting.', '3best-core' ); ?>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One labelled text input.
	 *
	 * @param string $id          Field id prefix.
	 * @param string $field       Field key.
	 * @param string $label       Visible label.
	 * @param string $placeholder Placeholder.
	 * @param string $autocomplete Autocomplete token.
	 * @param string $type        Input type.
	 * @param string $hint        Optional hint shown when there is no error.
	 */
	private static function render_text_field(
		string $id,
		string $field,
		string $label,
		string $placeholder,
		string $autocomplete,
		string $type,
		string $hint = ''
	): void {
		$field_id = $id . '-' . $field;
		?>
		<div class="tb-field">
			<label class="tb-field__label" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="tb-field__control"
				name="<?php echo esc_attr( $field ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
				<?php echo 'tel' === $type ? 'inputmode="tel"' : ''; ?>
				data-tb-field="<?php echo esc_attr( $field ); ?>"
				aria-describedby="<?php echo esc_attr( $field_id ); ?>-error"
			>
			<?php if ( '' !== $hint ) : ?>
				<span class="tb-field__hint" data-tb-hint="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $hint ); ?></span>
			<?php endif; ?>
			<span class="tb-field__error" id="<?php echo esc_attr( $field_id ); ?>-error" data-tb-error="<?php echo esc_attr( $field ); ?>" hidden></span>
		</div>
		<?php
	}

	/**
	 * Stage 2: code entry.
	 *
	 * @param string $id Field id prefix.
	 */
	private static function render_otp_stage( string $id ): void {
		$length = 6;
		?>
		<div class="tb-lead__stage" data-tb-stage="otp" hidden>
			<div class="tb-otp">
				<h3 class="tb-otp__title"><?php esc_html_e( 'Verify Your Phone', '3best-core' ); ?></h3>
				<p class="tb-otp__subtitle">
					<?php esc_html_e( 'We’ve sent a 6-digit verification code to', '3best-core' ); ?>
					<span class="tb-otp__phone" data-tb-masked></span>.
				</p>

				<p class="tb-otp__dev" data-tb-dev-notice hidden>
					<?php esc_html_e( 'Development mode — no SMS was sent. The code is in the server log.', '3best-core' ); ?>
				</p>

				<form novalidate data-tb-otp-form>
					<div class="tb-otp__row" role="group" aria-labelledby="<?php echo esc_attr( $id ); ?>-otp-label">
						<span id="<?php echo esc_attr( $id ); ?>-otp-label" class="sr-only"><?php esc_html_e( 'Verification code', '3best-core' ); ?></span>
						<?php for ( $i = 0; $i < $length; $i++ ) : ?>
							<input
								type="text"
								inputmode="numeric"
								class="tb-otp__input"
								maxlength="<?php echo (int) $length; ?>"
								autocomplete="<?php echo 0 === $i ? 'one-time-code' : 'off'; ?>"
								aria-label="<?php
									printf(
										/* translators: 1: digit position, 2: total digits */
										esc_attr__( 'Digit %1$d of %2$d', '3best-core' ),
										(int) $i + 1,
										(int) $length
									);
								?>"
								data-tb-otp-digit="<?php echo (int) $i; ?>"
							>
						<?php endfor; ?>
					</div>

					<div role="alert" aria-live="assertive">
						<span class="tb-otp__error" data-tb-otp-error hidden></span>
					</div>
					<div aria-live="polite">
						<span class="tb-otp__notice" data-tb-otp-notice hidden></span>
					</div>

					<div class="tb-lead__actions">
						<button type="submit" class="tb-button tb-button--primary tb-button--lg tb-button--full" data-tb-otp-submit disabled>
							<?php esc_html_e( 'Verify Phone', '3best-core' ); ?>
						</button>
					</div>
				</form>

				<div class="tb-otp__meta">
					<button type="button" class="tb-otp__link" data-tb-resend disabled></button>
					<span aria-hidden="true">·</span>
					<button type="button" class="tb-otp__link" data-tb-change-number>
						<?php esc_html_e( 'Use a different number', '3best-core' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Stage 3: confirmation.
	 *
	 * @param string              $id       Field id prefix.
	 * @param array<string,mixed> $service  Canonical service.
	 * @param Location            $location Canonical location.
	 */
	private static function render_success_stage( string $id, array $service, Location $location ): void {
		unset( $id );
		?>
		<div class="tb-lead__stage" data-tb-stage="success" hidden>
			<div class="tb-lead__success">
				<h2 class="tb-lead__success-title"><?php esc_html_e( 'You’re All Set!', '3best-core' ); ?></h2>
				<p class="tb-lead__success-text">
					<?php esc_html_e( 'Your request has been submitted. We’re finding your 3 best matches in your area and will be in touch using the details you provided.', '3best-core' ); ?>
				</p>

				<dl class="tb-lead__summary">
					<div class="tb-lead__summary-item">
						<dt><?php esc_html_e( 'Service', '3best-core' ); ?></dt>
						<dd><?php echo esc_html( $service['name'] ); ?></dd>
					</div>
					<div class="tb-lead__summary-item">
						<dt><?php esc_html_e( 'Location', '3best-core' ); ?></dt>
						<dd data-tb-summary-location><?php echo esc_html( Labels::label( $location ) ); ?></dd>
					</div>
					<div class="tb-lead__summary-item">
						<dt><?php esc_html_e( 'What you need', '3best-core' ); ?></dt>
						<dd data-tb-summary-subcategory>—</dd>
					</div>
					<div class="tb-lead__summary-item">
						<dt><?php esc_html_e( 'Reference', '3best-core' ); ?></dt>
						<dd data-tb-summary-reference>—</dd>
					</div>
				</dl>

				<div class="tb-lead__actions">
					<a class="tb-button tb-button--secondary tb-button--lg" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php esc_html_e( 'Back to Home', '3best-core' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
