<?php
/**
 * The shop's own details (name, phone, address, card, socials, wording) from ONE file:
 *   wp-content/shop-config/shop.php          (the repo's config/shop.php; yours, gitignored)
 * falling back key by key to shop.example.php next to it. Loaded by this plugin and, if the
 * plugin is off, by the theme, so both read the same values.
 *
 * boutique_shop()          → the whole array
 * boutique_shop( 'phone' ) → one value
 * The BOUTIQUE_* constants below are kept for the code that already uses them.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'boutique_shop' ) ) {

	/** Folder holding shop.php / shop.example.php. Override with define( 'BOUTIQUE_CONFIG_DIR', … ) in wp-config.php. */
	function boutique_shop_dir() {
		return defined( 'BOUTIQUE_CONFIG_DIR' ) ? BOUTIQUE_CONFIG_DIR : WP_CONTENT_DIR . '/shop-config';
	}

	/** True when the real config is missing and the placeholder example is in use. */
	function boutique_shop_is_example() {
		return ! is_readable( boutique_shop_dir() . '/shop.php' );
	}

	function boutique_shop( $key = null ) {
		static $c = null;
		if ( null === $c ) {
			$dir  = boutique_shop_dir();
			$base = is_readable( "$dir/shop.example.php" ) ? (array) require "$dir/shop.example.php" : [];
			$own  = is_readable( "$dir/shop.php" ) ? (array) require "$dir/shop.php" : [];
			$c    = array_replace( $base, $own );
			$c['colors'] = array_replace( (array) ( $base['colors'] ?? [] ), (array) ( $own['colors'] ?? [] ) );
			// Values made from others when left empty.
			$c += [ 'whatsapp' => '', 'street' => '', 'courier_city' => '', 'notify_phones' => [] ];
			if ( '' === $c['whatsapp'] && ! empty( $c['phone'] ) ) { $c['whatsapp'] = '98' . ltrim( $c['phone'], '0' ); }
			if ( false === $c['whatsapp'] ) { $c['whatsapp'] = ''; }
			if ( '' === $c['street'] ) { $c['street'] = $c['address'] ?? ''; }
			if ( '' === $c['courier_city'] ) { $c['courier_city'] = $c['city'] ?? ''; }
			if ( ! $c['notify_phones'] && ! empty( $c['phone'] ) ) { $c['notify_phones'] = [ $c['phone'] ]; }
			$c = apply_filters( 'boutique_shop', $c );
		}
		return null === $key ? $c : ( $c[ $key ] ?? '' );
	}

	foreach ( [
		'ADDRESS' => 'address', 'HOURS' => 'hours', 'STREET' => 'street', 'OPEN_DAYS' => 'open_days',
		'OPEN_HOURS' => 'open_hours', 'MAP_URL' => 'map_url', 'MAP_QUERY' => 'map_query',
		'INSTAGRAM' => 'instagram', 'TELEGRAM' => 'telegram_channel', 'ORDER_TG' => 'telegram_order',
		'PHONE' => 'phone', 'WHATSAPP' => 'whatsapp', 'BALE' => 'bale',
		'CARD_NUMBER' => 'card_number', 'CARD_HOLDER' => 'card_holder',
		'COURIER_CITY' => 'courier_city', 'SEO_TAGLINE' => 'seo_tagline', 'GOOGLE_VERIFY' => 'google_verify',
	] as $const => $key ) {
		if ( ! defined( "BOUTIQUE_$const" ) ) { define( "BOUTIQUE_$const", boutique_shop( $key ) ); }
	}

	// Admins see a reminder while the placeholder details are live.
	add_action( 'admin_notices', function () {
		if ( boutique_shop_is_example() && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>اطلاعات فروشگاه هنوز نمونه است. فایل <code>wp-content/shop-config/shop.php</code> را از روی <code>shop.example.php</code> بسازید.</p></div>';
		}
	} );

	/** Web app manifests (customer + manager icons), built from the config. */
	add_action( 'init', function () {
		$which = $_GET['boutique_manifest'] ?? '';
		if ( ! in_array( $which, [ 'shop', 'admin' ], true ) ) { return; }
		$c     = boutique_shop();
		$admin = 'admin' === $which;
		$base  = $admin ? ( defined( 'BOUTIQUE_ADMIN_URL' ) ? BOUTIQUE_ADMIN_URL . 'assets/app/' : '' ) : get_stylesheet_directory_uri() . '/app/';
		$icon  = fn( $f, $s, $p ) => [ 'src' => $base . $f, 'sizes' => $s, 'type' => 'image/png', 'purpose' => $p ];
		$start = $admin ? '/wp-admin/admin.php?page=boutique-today' : '/';
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( [
			'id'               => $start,
			'name'             => $admin ? 'مدیریت ' . $c['name'] : $c['name'],
			'short_name'       => $admin ? 'مدیریت ' . $c['short_name'] : $c['name'],
			'description'      => $admin ? 'سفارش‌ها، فروش حضوری و موجودی ' . $c['name'] : 'فروشگاه آنلاین ' . $c['name'],
			'lang'             => 'fa',
			'dir'              => 'rtl',
			'start_url'        => $start,
			'scope'            => $admin ? '/wp-admin/' : '/',
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => $admin ? '#f0f0f1' : $c['colors']['background'],
			'theme_color'      => $admin ? $c['colors']['admin_theme'] : $c['colors']['theme'],
			'icons'            => [
				$icon( 'icon-192.png', '192x192', 'any' ),
				$icon( 'icon-512.png', '512x512', 'any' ),
				$icon( 'icon-maskable-192.png', '192x192', 'maskable' ),
				$icon( 'icon-maskable-512.png', '512x512', 'maskable' ),
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}, 0 );
}
