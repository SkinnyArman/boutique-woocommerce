<?php
/**
 * Plugin Name: Boutique Admin
 * Description: ساده‌سازی پیشخوان برای مدیر فروشگاه: صفحه «امروز»، ثبت فروش حضوری، منوی خلوت.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Text Domain: boutique-admin
 */

defined( 'ABSPATH' ) || exit;

define( 'BOUTIQUE_ADMIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BOUTIQUE_ADMIN_DIR', __DIR__ );

require_once __DIR__ . '/inc/shop.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/cleanup.php';
require_once __DIR__ . '/inc/today.php';
require_once __DIR__ . '/inc/instore.php';
require_once __DIR__ . '/inc/stock.php';
require_once __DIR__ . '/inc/product-editor.php';
require_once __DIR__ . '/inc/reports.php';
require_once __DIR__ . '/inc/coupons.php';
require_once __DIR__ . '/inc/shipping.php';
require_once __DIR__ . '/inc/tracking.php';
require_once __DIR__ . '/inc/delivery.php';
require_once __DIR__ . '/inc/receipts.php';
require_once __DIR__ . '/inc/bale.php';
require_once __DIR__ . '/inc/customer-emails.php';
require_once __DIR__ . '/inc/lists.php';
require_once __DIR__ . '/inc/app.php';

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$css = BOUTIQUE_ADMIN_DIR . '/assets/admin.css';
	wp_enqueue_style( 'boutique-admin', BOUTIQUE_ADMIN_URL . 'assets/admin.css', [], filemtime( $css ) );
} );
