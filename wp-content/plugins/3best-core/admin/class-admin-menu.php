<?php
/**
 * Admin menu tree.
 *
 * @package ThreeBest\Core
 */

declare( strict_types = 1 );

namespace ThreeBest\Core\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every 3Best admin screen in one place.
 *
 * Before Phase 8 the status screen registered its own top-level menu, which was
 * fine while there was one screen. With four, a single owner of the tree means
 * the order is obvious, the capability is applied uniformly, and adding a
 * screen does not mean hunting for where a menu was attached.
 *
 * THE TOP-LEVEL SLUG IS UNCHANGED. It is still `threebest-core` pointing at the
 * status screen, so existing bookmarks and the redirects Phase 6 already sends
 * after a queue run keep working.
 *
 * Every screen carries the same capability, and each render method checks it
 * again for itself. add_menu_page() hiding a link is navigation, not access
 * control — the check that matters is the one inside the screen.
 */
final class Admin_Menu {

	public const CAPABILITY = 'manage_options';

	/**
	 * Hooks the menu.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
	}

	/**
	 * Builds the tree.
	 */
	public static function add_menus(): void {
		add_menu_page(
			__( '3Best', '3best-core' ),
			__( '3Best', '3best-core' ),
			self::CAPABILITY,
			Status_Page::MENU_SLUG,
			array( Status_Page::class, 'render' ),
			'dashicons-location-alt',
			58
		);

		/*
		 * WordPress adds a submenu duplicating the parent with the parent's
		 * label. Re-registering it with the same slug renames that first entry
		 * to something meaningful instead of a second "3Best".
		 */
		add_submenu_page(
			Status_Page::MENU_SLUG,
			__( '3Best Overview', '3best-core' ),
			__( 'Overview', '3best-core' ),
			self::CAPABILITY,
			Status_Page::MENU_SLUG,
			array( Status_Page::class, 'render' )
		);

		add_submenu_page(
			Status_Page::MENU_SLUG,
			__( '3Best Leads', '3best-core' ),
			__( 'Leads', '3best-core' ),
			Leads_Screen::CAPABILITY,
			Leads_Screen::MENU_SLUG,
			array( Leads_Screen::class, 'render' )
		);

		add_submenu_page(
			Status_Page::MENU_SLUG,
			__( '3Best SEO Pages', '3best-core' ),
			__( 'SEO Pages', '3best-core' ),
			Seo_Pages_Screen::CAPABILITY,
			Seo_Pages_Screen::MENU_SLUG,
			array( Seo_Pages_Screen::class, 'render' )
		);

		add_submenu_page(
			Status_Page::MENU_SLUG,
			__( '3Best Settings', '3best-core' ),
			__( 'Settings', '3best-core' ),
			Settings_Screen::CAPABILITY,
			Settings_Screen::MENU_SLUG,
			array( Settings_Screen::class, 'render' )
		);
	}
}
