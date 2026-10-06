<?php
/**
 * Plugin bootstrap and hook wiring.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core;

use ThreeBest\Core\Admin\Admin_Menu;
use ThreeBest\Core\Admin\Delivery_Admin;
use ThreeBest\Core\Admin\Seo_Pages_Screen;
use ThreeBest\Core\Admin\Settings_Screen;
use ThreeBest\Core\Delivery\Delivery_Scheduler;
use ThreeBest\Core\Seo\Landing_Template;
use ThreeBest\Core\Seo\Meta;
use ThreeBest\Core\Seo\Rewrites;
use ThreeBest\Core\Seo\Structured_Data;
use ThreeBest\Core\Frontend\Assets;
use ThreeBest\Core\Frontend\Blocks;
use ThreeBest\Core\Frontend\Lead_Form;
use ThreeBest\Core\Frontend\Search_Form;
use ThreeBest\Core\Rest\Router;

defined( 'ABSPATH' ) || exit;

/**
 * Single place where every WordPress hook this plugin uses is registered.
 *
 * Keeping registration in one readable method — rather than scattering
 * add_action calls through the class files — is what makes it possible to see
 * the plugin's whole surface area at a glance as later phases add routing,
 * REST endpoints, lead handling, verification, delivery and SEO output.
 *
 * Phase 1 registers only the migration check, the translations load and the
 * read-only admin status screen. Nothing touches the front end yet.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $initialised = false;

	/**
	 * Shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private: use instance().
	 */
	private function __construct() {}

	/**
	 * Registers hooks. Safe to call more than once.
	 */
	public function init(): void {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		/*
		 * Schema upgrades run in the admin only. A front-end request must never
		 * pay for a migration check, and an upgrade needs a logged-in context to
		 * be able to report a failure to somebody.
		 */
		add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $this, 'record_version' ) );

		/*
		 * REST routes must be registered on every request type, not just admin
		 * ones: rest_api_init fires for front-end REST traffic too.
		 */
		Router::register();

		/*
		 * Front-end search. Search_Form fills the extension points the theme
		 * left open in Phase 3; Assets loads the behaviour only where a form is
		 * actually rendered.
		 */
		Search_Form::register();
		Lead_Form::register();
		Assets::register();

		/*
		 * Lead delivery. The scheduler registers its interval and handler on
		 * every request type, because WP-Cron fires from the front end too.
		 */
		Delivery_Scheduler::register();

		/*
		 * Dynamic service/location pages. Rewrites resolves the request; Meta
		 * and Structured_Data read that one resolution rather than repeating it.
		 */
		Rewrites::register();
		Meta::register();
		Structured_Data::register();

		/*
		 * Presentation. Blocks let an editor place the application's own
		 * components on a page; Landing_Template holds the one editable
		 * layout every service/location page is rendered through. Neither
		 * owns any business logic - both call renderers that already exist.
		 */
		Blocks::register();
		Landing_Template::register();

		/*
		 * Admin screens. Admin_Menu owns the menu tree; each screen registers
		 * only its own write handlers, which are admin-post actions and so must
		 * be hooked before the menu is ever rendered.
		 */
		if ( is_admin() ) {
			Admin_Menu::register();
			Delivery_Admin::register();
			Seo_Pages_Screen::register();
			Settings_Screen::register();
		}
	}

	/**
	 * Keeps the recorded plugin version in step with the code.
	 *
	 * The activation hook records the version, but a plugin is usually updated
	 * in place rather than reactivated, which would leave the stored value
	 * behind and make the admin screen report a version that is not running.
	 * Checked on admin_init only, and written only when it has actually changed.
	 */
	public function record_version(): void {
		if ( get_option( 'threebest_core_version' ) !== THREEBEST_CORE_VERSION ) {
			update_option( 'threebest_core_version', THREEBEST_CORE_VERSION, false );
		}
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'3best-core',
			false,
			dirname( THREEBEST_CORE_BASENAME ) . '/languages'
		);
	}

	/**
	 * Prevents cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevents unserialisation of the singleton.
	 */
	public function __wakeup(): void {
		throw new \LogicException( 'Plugin cannot be unserialised.' );
	}
}
