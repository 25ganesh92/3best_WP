<?php
/**
 * Settings screen.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

use ThreeBest\Core\Delivery\Delivery_Queue;
use ThreeBest\Core\Location\Provider_Factory as Location_Provider_Factory;
use ThreeBest\Core\Logger;
use ThreeBest\Core\Otp\Otp_Provider_Factory;
use ThreeBest\Core\Sanitizer;
use ThreeBest\Core\Security\Throttle;
use ThreeBest\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The first screen in this project that writes configuration.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS SCREEN DELIBERATELY CANNOT DO
 * ---------------------------------------------------------------------------
 * It cannot set a secret. The Google key, the three Twilio values and the
 * Zapier webhook URL have no field here, are rejected by Settings::update()
 * even if one were forged into the POST, and are reported only as "configured"
 * or "not configured" alongside the NAME of the wp-config.php constant that
 * supplies them. A credential that can be typed into a web form is a credential
 * that can be read back out of one, and it would land in the options table, in
 * every database dump, and in every backup.
 *
 * It also cannot override a constant. Where wp-config.php defines a value, the
 * field is shown read-only with that constant named, because a form that
 * silently accepts a value the application will then ignore is worse than no
 * form at all.
 *
 * Every value is sanitised and clamped on the way in. The numeric fields are
 * not free text: an OTP that lives for a week or a batch size of 10,000 are
 * configuration mistakes with real consequences, so the bounds are enforced
 * server-side rather than suggested by the markup.
 */
final class Settings_Screen {

	public const MENU_SLUG  = 'threebest-settings';
	public const CAPABILITY = 'manage_options';

	private const ACTION_SAVE = 'threebest_save_settings';

	/**
	 * Fields this screen may write, with their bounds.
	 *
	 * The single source of truth for what is editable. A key absent from here
	 * is not writable through the UI no matter what the form posts.
	 *
	 * @var array<string,array{type:string,min?:int,max?:int,choices?:string[],label:string,help?:string}>
	 */
	private const FIELDS = array(
		'location_provider'        => array( 'type' => 'provider_location', 'label' => 'Geocoding provider' ),
		'location_cache_ttl'       => array( 'type' => 'int', 'min' => 0, 'max' => 86400, 'label' => 'Location cache lifetime (seconds)' ),
		'location_min_query'       => array( 'type' => 'int', 'min' => 1, 'max' => 10, 'label' => 'Minimum search length' ),
		'location_max_results'     => array( 'type' => 'int', 'min' => 1, 'max' => 20, 'label' => 'Maximum suggestions' ),
		'nominatim_base_url'       => array( 'type' => 'url', 'label' => 'Nominatim endpoint' ),
		'nominatim_user_agent'     => array( 'type' => 'text', 'max' => 190, 'label' => 'Nominatim user agent' ),

		'otp_provider'             => array( 'type' => 'provider_otp', 'label' => 'Verification provider' ),
		'otp_length'               => array( 'type' => 'int', 'min' => 4, 'max' => 10, 'label' => 'Code length' ),
		'otp_ttl_seconds'          => array( 'type' => 'int', 'min' => 60, 'max' => 3600, 'label' => 'Code lifetime (seconds)' ),
		'otp_resend_cooldown'      => array( 'type' => 'int', 'min' => 0, 'max' => 600, 'label' => 'Resend cooldown (seconds)' ),
		'otp_max_attempts'         => array( 'type' => 'int', 'min' => 1, 'max' => 10, 'label' => 'Maximum code attempts' ),
		'otp_max_sends_per_day'    => array( 'type' => 'int', 'min' => 1, 'max' => 50, 'label' => 'Maximum sends per number per day' ),
		'otp_token_ttl_seconds'    => array( 'type' => 'int', 'min' => 60, 'max' => 3600, 'label' => 'Verification token lifetime (seconds)' ),

		'delivery_enabled'         => array( 'type' => 'bool', 'label' => 'Send queued leads to the webhook' ),
		'delivery_batch_size'      => array( 'type' => 'int', 'min' => 1, 'max' => 100, 'label' => 'Delivery batch size' ),
		'delivery_max_attempts'    => array( 'type' => 'int', 'min' => 1, 'max' => 20, 'label' => 'Delivery attempts before giving up' ),

		'remove_data_on_uninstall' => array( 'type' => 'bool', 'label' => 'Drop the 3Best tables when the plugin is deleted' ),
	);

	/**
	 * Registers the write handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
	}

	/**
	 * Persists the form.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', '3best-core' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_SAVE );

		$values  = array();
		$changed = array();

		foreach ( self::FIELDS as $key => $field ) {
			// A constant wins over the option, so writing one would store a
			// value the application never reads. Skip it entirely.
			if ( Settings::is_from_constant( $key ) ) {
				continue;
			}

			$posted = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by clean() below.
			$clean  = self::clean( $key, $field, $posted );

			$values[ $key ] = $clean;

			if ( Settings::get( $key ) !== $clean ) {
				$changed[] = $key;
			}
		}

		Settings::update( $values );

		/*
		 * The KEYS that changed are logged, never the values. Knowing that
		 * delivery was switched on and by whom is an audit trail; copying
		 * configuration into the log is just another place to leak it from.
		 */
		Logger::log(
			'Settings updated.',
			array(
				'changed' => $changed,
				'by'      => get_current_user_id(),
			)
		);

		wp_safe_redirect( Admin_Ui::url( self::MENU_SLUG, array( 'tb_saved' => count( $changed ) ) ) );

		exit;
	}

	/**
	 * Sanitises and clamps one posted field.
	 *
	 * @param string                                                                              $key   Setting key.
	 * @param array{type:string,min?:int,max?:int,choices?:string[],label:string,help?:string}     $field Field definition.
	 * @param mixed                                                                               $value Raw posted value.
	 * @return mixed
	 */
	private static function clean( string $key, array $field, $value ) {
		switch ( $field['type'] ) {
			case 'bool':
				// An unchecked checkbox posts nothing at all, which is the
				// only way "off" can be expressed.
				return Sanitizer::boolean( $value );

			case 'int':
				return Sanitizer::integer( $value, $field['min'] ?? null, $field['max'] ?? null );

			case 'url':
				$url = Sanitizer::url( $value );

				return '' === $url ? (string) Settings::defaults()[ $key ] : $url;

			case 'provider_location':
				return Sanitizer::choice(
					$value,
					Location_Provider_Factory::known(),
					(string) Settings::defaults()[ $key ]
				);

			case 'provider_otp':
				return Sanitizer::choice(
					$value,
					array_keys( Otp_Provider_Factory::availability() ),
					(string) Settings::defaults()[ $key ]
				);

			default:
				return Sanitizer::text( $value, $field['max'] ?? 190 );
		}
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', '3best-core' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( '3Best Settings', '3best-core' ) . '</h1>';

		self::render_saved_notice();
		self::render_verification_warning();
		self::render_credentials();

		Admin_Ui::open_form( self::ACTION_SAVE );

		self::render_group(
			__( 'Location search', '3best-core' ),
			array( 'location_provider', 'location_cache_ttl', 'location_min_query', 'location_max_results', 'nominatim_base_url', 'nominatim_user_agent' )
		);

		self::render_group(
			__( 'Phone verification', '3best-core' ),
			array( 'otp_provider', 'otp_length', 'otp_ttl_seconds', 'otp_resend_cooldown', 'otp_max_attempts', 'otp_max_sends_per_day', 'otp_token_ttl_seconds' )
		);

		self::render_group(
			__( 'Lead delivery', '3best-core' ),
			array( 'delivery_enabled', 'delivery_batch_size', 'delivery_max_attempts' )
		);

		self::render_group(
			__( 'Housekeeping', '3best-core' ),
			array( 'remove_data_on_uninstall' )
		);

		submit_button( __( 'Save settings', '3best-core' ) );

		echo '</form>';

		self::render_rate_limits();

		echo '</div>';
	}

	/**
	 * Warns, loudly, when verification is not actually sending anything.
	 *
	 * The development provider accepts a code nobody had to receive, which means
	 * leads can be submitted with a phone number the sender does not own. It is
	 * gated on two separate switches, but an operator looking at this screen
	 * should never have to go and read wp-config.php to find out which mode the
	 * site is in.
	 */
	private static function render_verification_warning(): void {
		$provider = Otp_Provider_Factory::get();

		if ( $provider->sends_sms() ) {
			return;
		}

		Admin_Ui::notice(
			'error',
			sprintf(
				/* translators: %s: provider name */
				__( 'Phone verification is running on the "%s" provider, which sends no SMS. Codes are not delivered and verification does not prove ownership of a number. This must not be the case in production.', '3best-core' ),
				$provider->name()
			)
		);
	}

	/**
	 * Rate limits, read-only.
	 *
	 * Deliberately NOT editable. These are abuse controls in front of an
	 * endpoint that spends money, and a number box in a web form is one
	 * fat-fingered zero away from switching one off. They live in code with a
	 * filter seam for a deployment that genuinely needs different values; this
	 * table is here so an operator can see what is being enforced without
	 * reading the source.
	 */
	private static function render_rate_limits(): void {
		echo '<h2>' . esc_html__( 'Rate limits', '3best-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Enforced limits, shown for reference. These are set in code rather than here, because they are abuse controls rather than preferences.', '3best-core' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:860px"><thead><tr>';
		echo '<th>' . esc_html__( 'Bucket', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Limit', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Window', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'If the counter store fails', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( Throttle::buckets() as $bucket ) {
			list( $limit, $window ) = Throttle::limits_for( $bucket );
			$fails_open             = Throttle::fails_open( $bucket );

			echo '<tr>';
			echo '<td><code>' . esc_html( $bucket ) . '</code></td>';
			echo '<td>' . esc_html( 0 === $limit ? __( 'unlimited', '3best-core' ) : (string) $limit ) . '</td>';
			echo '<td>' . esc_html( self::describe_window( $window ) ) . '</td>';
			echo '<td>' . Admin_Ui::badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				! $fails_open,
				$fails_open ? __( 'allow (read-only surface)', '3best-core' ) : __( 'deny', '3best-core' )
			) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$store = Throttle::store();

		echo '<p class="description">' . sprintf(
			/* translators: %s: store description */
			esc_html__( 'Counter storage: %s', '3best-core' ),
			$store instanceof \ThreeBest\Core\Security\Db_Throttle_Store
				? esc_html__( 'dedicated table, atomic increment', '3best-core' )
				: esc_html__( 'transients — counting is not atomic; run the database upgrade', '3best-core' )
		) . '</p>';
	}

	/**
	 * A window length in words.
	 *
	 * @param int $seconds Window length.
	 */
	private static function describe_window( int $seconds ): string {
		if ( $seconds >= DAY_IN_SECONDS ) {
			return sprintf( /* translators: %d: number of days */ _n( '%d day', '%d days', (int) ( $seconds / DAY_IN_SECONDS ), '3best-core' ), (int) ( $seconds / DAY_IN_SECONDS ) );
		}

		if ( $seconds >= HOUR_IN_SECONDS ) {
			return sprintf( /* translators: %d: number of hours */ _n( '%d hour', '%d hours', (int) ( $seconds / HOUR_IN_SECONDS ), '3best-core' ), (int) ( $seconds / HOUR_IN_SECONDS ) );
		}

		if ( $seconds >= MINUTE_IN_SECONDS ) {
			return sprintf( /* translators: %d: number of minutes */ _n( '%d minute', '%d minutes', (int) ( $seconds / MINUTE_IN_SECONDS ), '3best-core' ), (int) ( $seconds / MINUTE_IN_SECONDS ) );
		}

		return sprintf( /* translators: %d: number of seconds */ _n( '%d second', '%d seconds', $seconds, '3best-core' ), $seconds );
	}

	/**
	 * Confirmation after a save.
	 */
	private static function render_saved_notice(): void {
		// Rendering our own redirect parameter; no state changes here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tb_saved'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$changed = absint( wp_unslash( $_GET['tb_saved'] ) );

		Admin_Ui::notice(
			'success',
			0 === $changed
				? __( 'Settings saved. Nothing changed.', '3best-core' )
				: sprintf(
					/* translators: %d: number of settings changed */
					_n( 'Settings saved. %d setting changed.', 'Settings saved. %d settings changed.', $changed, '3best-core' ),
					$changed
				)
		);
	}

	/**
	 * Credential status — names and state only, never a value.
	 */
	private static function render_credentials(): void {
		echo '<h2>' . esc_html__( 'Credentials', '3best-core' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Credentials are set in wp-config.php and are never editable or readable here. Only whether each one is present is shown.', '3best-core' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:860px"><thead><tr>';
		echo '<th>' . esc_html__( 'Credential', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'wp-config.php constant', '3best-core' ) . '</th>';
		echo '<th>' . esc_html__( 'State', '3best-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		$labels = array(
			'google_maps_api_key'       => __( 'Google Maps Platform key', '3best-core' ),
			'twilio_account_sid'        => __( 'Twilio account SID', '3best-core' ),
			'twilio_auth_token'         => __( 'Twilio auth token', '3best-core' ),
			'twilio_verify_service_sid' => __( 'Twilio Verify service SID', '3best-core' ),
			'zapier_webhook_url'        => __( 'Zapier webhook URL', '3best-core' ),
		);

		foreach ( $labels as $key => $label ) {
			$configured = Settings::is_configured( $key );

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td><code>' . esc_html( Settings::constant_name( $key ) ) . '</code></td>';
			echo '<td>' . Admin_Ui::badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
				$configured,
				$configured ? __( 'configured', '3best-core' ) : __( 'not configured', '3best-core' )
			) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one labelled group of fields.
	 *
	 * @param string   $title Group heading.
	 * @param string[] $keys  Setting keys in display order.
	 */
	private static function render_group( string $title, array $keys ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $keys as $key ) {
			self::render_field( $key );
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one field.
	 *
	 * @param string $key Setting key.
	 */
	private static function render_field( string $key ): void {
		$field = self::FIELDS[ $key ] ?? null;

		if ( null === $field ) {
			return;
		}

		$value       = Settings::get( $key );
		$from_const  = Settings::is_from_constant( $key );
		$description = '';

		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		if ( $from_const ) {
			/*
			 * Shown, but not editable and not posted. The value itself is only
			 * printed when the key is not a secret; a secret reaching this
			 * branch would still be caught by is_secret(), but the UI should
			 * not depend on that single check.
			 */
			if ( Settings::is_secret( $key ) ) {
				echo Admin_Ui::badge( true, __( 'set in wp-config.php', '3best-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			} else {
				echo '<code>' . esc_html( (string) $value ) . '</code> ';
				echo Admin_Ui::badge( true, __( 'set in wp-config.php', '3best-core' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
			}

			echo '<p class="description">' . sprintf(
				/* translators: %s: PHP constant name */
				esc_html__( 'Defined by %s. Edit wp-config.php to change it.', '3best-core' ),
				'<code>' . esc_html( Settings::constant_name( $key ) ) . '</code>'
			) . '</p>';

			echo '</td></tr>';

			return;
		}

		switch ( $field['type'] ) {
			case 'bool':
				printf(
					'<label><input type="checkbox" name="%1$s" id="%1$s" value="1"%2$s> %3$s</label>',
					esc_attr( $key ),
					checked( (bool) $value, true, false ),
					esc_html__( 'Enabled', '3best-core' )
				);

				if ( 'remove_data_on_uninstall' === $key ) {
					$description = __( 'Off by default. Leads are the only record of the consent that was given.', '3best-core' );
				}

				if ( 'delivery_enabled' === $key ) {
					$description = __( 'Leads are always stored first. This controls only whether the queue sends them on.', '3best-core' );
				}
				break;

			case 'int':
				printf(
					'<input type="number" class="small-text" name="%1$s" id="%1$s" value="%2$s" min="%3$d" max="%4$d" step="1">',
					esc_attr( $key ),
					esc_attr( (string) (int) $value ),
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? 0 )
				);

				$description = sprintf(
					/* translators: 1: minimum, 2: maximum */
					__( 'Between %1$d and %2$d.', '3best-core' ),
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? 0 )
				);
				break;

			case 'provider_location':
				Admin_Ui::select( $key, self::location_choices(), (string) $value );
				$description = __( 'Public Nominatim is for development only. Google Places is the production provider.', '3best-core' );
				break;

			case 'provider_otp':
				Admin_Ui::select( $key, self::otp_choices(), (string) $value );
				$description = __( 'The development provider sends no SMS and must never be selected in production.', '3best-core' );
				break;

			case 'url':
				printf(
					'<input type="url" class="regular-text code" name="%1$s" id="%1$s" value="%2$s">',
					esc_attr( $key ),
					esc_attr( (string) $value )
				);
				break;

			default:
				printf(
					'<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s" maxlength="%3$d">',
					esc_attr( $key ),
					esc_attr( (string) $value ),
					(int) ( $field['max'] ?? 190 )
				);

				if ( 'nominatim_user_agent' === $key ) {
					$description = __( 'Nominatim requires a contact address in this string before any non-trivial use.', '3best-core' );
				}
		}

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Geocoding providers, annotated with availability.
	 *
	 * @return array<string,string>
	 */
	private static function location_choices(): array {
		$choices = array();

		foreach ( Location_Provider_Factory::availability() as $key => $available ) {
			$choices[ $key ] = $available
				? $key
				: sprintf(
					/* translators: %s: provider key */
					__( '%s — not configured', '3best-core' ),
					$key
				);
		}

		return $choices;
	}

	/**
	 * Verification providers, annotated with availability and SMS capability.
	 *
	 * @return array<string,string>
	 */
	private static function otp_choices(): array {
		$choices = array();

		foreach ( Otp_Provider_Factory::availability() as $key => $state ) {
			if ( ! empty( $state['available'] ) && empty( $state['sends_sms'] ) ) {
				$choices[ $key ] = sprintf(
					/* translators: %s: provider key */
					__( '%s — sends no SMS', '3best-core' ),
					$key
				);

				continue;
			}

			$choices[ $key ] = empty( $state['available'] )
				? sprintf(
					/* translators: %s: provider key */
					__( '%s — not configured', '3best-core' ),
					$key
				)
				: $key;
		}

		return $choices;
	}

	/**
	 * Whether delivery can actually run, for the overview screen.
	 */
	public static function delivery_ready(): bool {
		return Delivery_Queue::is_enabled() && '' === Delivery_Queue::provider()->configuration_error();
	}
}
