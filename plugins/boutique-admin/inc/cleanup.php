<?php
/**
 * A calm admin for the shop manager: only what running the shop needs.
 * Full administrators keep everything (they just lose the ads and the WP welcome panel).
 */
defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------- Everyone */

// WordPress welcome panel.
remove_action( 'welcome_panel', 'wp_welcome_panel' );

// Persian WooCommerce's promo banners (.pw_notice) on every screen.
add_action( 'admin_head', function () {
	echo '<style>.pw_notice{display:none!important}</style>';
} );

/* ------------------------------------------------------------ Shop manager */

// Land on «امروز» after login, and whenever the old dashboard is opened.
add_filter( 'login_redirect', function ( $to, $requested, $user ) {
	if ( $user instanceof WP_User && $user->has_cap( 'manage_woocommerce' ) && ! $user->has_cap( 'manage_options' ) ) {
		return admin_url( 'admin.php?page=boutique-today' );
	}
	return $to;
}, 10, 3 );

add_action( 'load-index.php', function () {
	if ( boutique_is_manager() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=boutique-today' ) );
		exit;
	}
} );

add_action( 'admin_menu', function () {
	if ( ! boutique_is_manager() ) { return; }

	foreach ( [
		'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php',
		'themes.php', 'tools.php', 'persian-wc', 'persian-woocommerce-revenue-report',
		'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM', 'woocommerce-marketing',
	] as $slug ) {
		remove_menu_page( $slug );
	}
	foreach ( [ 'wc-admin', 'wc-settings', 'wc-status', 'wc-addons', 'wc-admin&path=/extensions', 'wc-reports' ] as $sub ) {
		remove_submenu_page( 'woocommerce', $sub );
	}
	// Products: tags and attributes are never used by hand (sizes and colors come from «لباس جدید»).
	remove_submenu_page( 'edit.php?post_type=product', 'edit-tags.php?taxonomy=product_tag&amp;post_type=product' );
	remove_submenu_page( 'edit.php?post_type=product', 'edit-tags.php?taxonomy=product_tag&post_type=product' );
	remove_submenu_page( 'edit.php?post_type=product', 'product_attributes' );
	// Customers are under orders; her own password is in the top bar's profile link.
	remove_menu_page( 'users.php' );
	// "ووکامرس" means nothing to her: the menu holds orders and customers, so call it that.
	global $menu;
	foreach ( (array) $menu as $k => $item ) {
		if ( 'woocommerce' === ( $item[2] ?? '' ) ) { $menu[ $k ][0] = 'سفارش‌ها'; }
	}
}, 999 );

// Admin bar: drop WordPress logo, comments, updates and "new post/page" for the manager.
add_action( 'admin_bar_menu', function ( WP_Admin_Bar $bar ) {
	if ( ! boutique_is_manager() ) { return; }
	foreach ( [ 'wp-logo', 'comments', 'updates', 'new-post', 'new-page', 'new-media', 'new-user' ] as $id ) {
		$bar->remove_node( $id );
	}
}, 999 );

// WooCommerce's "Home" task list / inbox / marketing nags.
add_filter( 'woocommerce_admin_features', function ( $features ) {
	if ( ! boutique_is_manager() ) { return $features; }
	return array_values( array_diff( $features, [ 'marketing', 'remote-inbox-notifications', 'onboarding', 'onboarding-tasks' ] ) );
} );

// Hiding a menu isn't access control: WooCommerce lets shop managers edit themes, menus and
// widgets (edit_theme_options). Take that away so Appearance can't be opened by URL either.
add_filter( 'user_has_cap', function ( $allcaps, $caps, $args, $user ) {
	if ( $user instanceof WP_User && in_array( 'shop_manager', (array) $user->roles, true ) ) {
		$allcaps['edit_theme_options'] = false;
		$allcaps['customize']          = false;
	}
	return $allcaps;
}, 10, 4 );

// No Gravatar: avatars load from a foreign server, which hangs pages in Iran when it's
// slow or cut off (admin bar on every admin page, and product reviews on the storefront).
add_filter( 'pre_option_show_avatars', '__return_zero' );
