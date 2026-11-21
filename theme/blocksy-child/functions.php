<?php
/**
 * Boutique child theme.
 */

defined( 'ABSPATH' ) || exit;

// Shop details (config/shop.php). Normally loaded by the boutique-admin plugin already.
if ( ! function_exists( 'boutique_shop' ) && is_readable( WP_PLUGIN_DIR . '/boutique-admin/inc/shop.php' ) ) {
	require_once WP_PLUGIN_DIR . '/boutique-admin/inc/shop.php';
}
require_once __DIR__ . '/inc/home.php';
require_once __DIR__ . '/inc/seo.php';
require_once __DIR__ . '/inc/faq.php';
require_once __DIR__ . '/inc/darkmode.php';
require_once __DIR__ . '/inc/app.php';

/* -------------------------------------------------------------------------
 * Styles: parent first, then ours (so our tokens win).
 * ---------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	$ver = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'boutique', get_stylesheet_uri(), [ 'ct-main-styles' ], $ver . '.' . filemtime( get_stylesheet_directory() . '/style.css' ) );
}, 20 );

/* Preload the font so text doesn't flash. */
add_action( 'wp_head', function () {
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( get_stylesheet_directory_uri() . '/fonts/Estedad-var.woff2' )
	);
}, 1 );

/* -------------------------------------------------------------------------
 * No Google Fonts / external font CDNs.
 * During international internet cutoffs these hang page loads in Iran.
 * ---------------------------------------------------------------------- */
add_filter( 'style_loader_src', function ( $src ) {
	if ( is_string( $src ) && preg_match( '#//(fonts\.googleapis\.com|fonts\.gstatic\.com)#', $src ) ) {
		return false;
	}
	return $src;
}, 99 );
add_filter( 'wp_resource_hints', function ( $urls, $type ) {
	return array_values( array_filter( $urls, function ( $u ) {
		$href = is_array( $u ) ? ( $u['href'] ?? '' ) : $u;
		return ! preg_match( '#fonts\.(googleapis|gstatic)\.com#', (string) $href );
	} ) );
}, 99, 2 );

/* -------------------------------------------------------------------------
 * Size guide: per product, filled in «لباس جدید» (meta _boutique_size_guide).
 * The tab only appears on products that have one; empty columns are dropped.
 * ---------------------------------------------------------------------- */
function boutique_size_guide( $product_id ) {
	$g = get_post_meta( $product_id, '_boutique_size_guide', true );
	return ( is_array( $g ) && ( ! empty( $g['rows'] ) || ! empty( $g['note'] ) ) ) ? $g : null;
}

add_filter( 'woocommerce_product_tabs', function ( $tabs ) {
	global $product;
	if ( $product && boutique_size_guide( $product->get_id() ) ) {
		$tabs['size_guide'] = [ 'title' => 'راهنمای سایز', 'priority' => 25, 'callback' => 'boutique_size_guide_tab' ];
	}
	return $tabs;
} );

function boutique_size_guide_tab() {
	global $product;
	$g    = boutique_size_guide( $product->get_id() );
	$cols = [ 'bust' => 'دور سینه', 'waist' => 'دور کمر', 'hip' => 'دور باسن', 'length' => 'قد لباس' ];
	$rows = (array) ( $g['rows'] ?? [] );
	$used = array_filter( $cols, fn( $k ) => (bool) array_filter( array_column( $rows, $k ) ), ARRAY_FILTER_USE_KEY );
	echo '<h2>راهنمای سایز همین لباس</h2>';
	if ( $rows && $used ) {
		echo '<p>اندازه‌ها به سانتی‌متر است. اگر بین دو سایز هستید، سایز بزرگ‌تر را انتخاب کنید یا از ما بپرسید.</p>';
		echo '<div class="b-size-wrap"><table class="b-size-table"><thead><tr><th>سایز</th>';
		foreach ( $used as $label ) { echo '<th>' . esc_html( $label ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $size => $r ) {
			// Plain digits: the site font draws them as Persian (and keeps them evenly spaced).
			echo '<tr><th scope="row">' . esc_html( $size ) . '</th>';
			foreach ( array_keys( $used ) as $k ) { echo '<td dir="ltr">' . esc_html( $r[ $k ] ?? '—' ) . '</td>'; }
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
	if ( ! empty( $g['note'] ) ) {
		echo '<p class="b-size-note">' . esc_html( $g['note'] ) . '</p>';
	}
}

/* Fabric («جنس»), under the price. */
add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	$m = $product ? get_post_meta( $product->get_id(), '_boutique_material', true ) : '';
	if ( $m ) { echo '<p class="b-material"><span>جنس:</span> ' . esc_html( $m ) . '</p>'; }
}, 11 );

/* -------------------------------------------------------------------------
 * Small UX tweaks
 * ---------------------------------------------------------------------- */

// 4 products per row on shop/category pages, 12 per page.
add_filter( 'loop_shop_per_page', fn() => 12 );

// Mobile number is what Iranians actually use; make phone required, email optional.
add_filter( 'woocommerce_billing_fields', function ( $fields ) {
	// Runs late (priority 99) so Persian WooCommerce's own field tweaks don't override it.
	if ( isset( $fields['billing_phone'] ) ) {
		$fields['billing_phone']['required'] = true;
		$fields['billing_phone']['label']    = 'شماره موبایل';
		$fields['billing_phone']['priority'] = 25;
	}
	if ( isset( $fields['billing_email'] ) ) {
		$fields['billing_email']['required'] = false;
	}
	unset( $fields['billing_company'] );
	return $fields;
}, 99 );

// WooCommerce's checkout JS relabels address fields (incl. phone) from the locale data.
$boutique_phone_label = function ( $fields ) {
	if ( isset( $fields['phone'] ) ) {
		$fields['phone']['label'] = 'شماره موبایل';
	}
	return $fields;
};
add_filter( 'woocommerce_default_address_fields', $boutique_phone_label, 999 );
add_filter( 'woocommerce_get_country_locale_default', $boutique_phone_label, 999 );

// Final word on checkout labels (runs after Persian WooCommerce + core locale).
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['label']       = 'شماره موبایل';
		$fields['billing']['billing_phone']['placeholder'] = '۰۹۱۲۳۴۵۶۷۸۹';
	}
	return $fields;
}, 999 );

/* ------------------------------------------------------------------------
 * «استعلام قیمت»: products without a public price. Not buyable online; the
 * product page lists available sizes and offers Telegram / phone instead.
 * --------------------------------------------------------------------- */
add_filter( 'woocommerce_get_price_html', function ( $html, $p ) {
	return boutique_is_ask_price( $p ) ? '<span class="b-ask">استعلام قیمت</span>' : $html;
}, 20, 2 );
add_filter( 'woocommerce_is_purchasable', fn( $ok, $p ) => boutique_is_ask_price( $p ) ? false : $ok, 20, 2 );
add_filter( 'woocommerce_variation_is_purchasable', fn( $ok, $p ) => boutique_is_ask_price( $p ) ? false : $ok, 20, 2 );

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! boutique_is_ask_price( $product ) ) { return; }
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	$sizes = [];
	foreach ( $product->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v && $v->is_in_stock() ) { $sizes[] = boutique_variation_label_front( $v ); }
	}
	$msg = rawurlencode( 'سلام، قیمت و امکان پرو «' . $product->get_name() . '» را می‌خواستم. ' . get_permalink( $product->get_id() ) );
	echo '<div class="b-askbox">';
	echo $sizes ? '<p><strong>موجود در سایز:</strong> ' . esc_html( implode( '، ', array_unique( $sizes ) ) ) . '</p>' : '<p>فعلاً موجود نیست.</p>';
	echo '<p>برای قیمت، پرو و سفارش این لباس به ما پیام دهید:</p><div class="b-askbox__actions">';
	echo '<a class="button" href="https://t.me/' . esc_attr( BOUTIQUE_ORDER_TG ) . '?text=' . $msg . '" target="_blank" rel="noopener">پیام در تلگرام</a>';
	echo '<a class="button b-askbox__call" href="tel:' . esc_attr( BOUTIQUE_PHONE ) . '">تماس: <bdi dir="ltr">' . esc_html( boutique_fa( BOUTIQUE_PHONE ) ) . '</bdi></a>';
	echo '</div></div>';
}, 25 );

// Ask-price pages: mark the body so the variation form and its "out of stock" line can go.
add_filter( 'body_class', function ( $c ) {
	if ( is_product() && boutique_is_ask_price( wc_get_product( get_queried_object_id() ) ) ) { $c[] = 'b-is-ask'; }
	return $c;
} );

// «حراج» in the menu only while something is actually on sale.
add_filter( 'wp_nav_menu_objects', function ( $items ) {
	if ( wc_get_product_ids_on_sale() ) { return $items; }
	return array_values( array_filter( $items, fn( $i ) => ! str_contains( (string) $i->url, '#b-sale' ) ) );
} );

function boutique_variation_label_front( WC_Product $v ) {
	$parts = [];
	foreach ( $v->get_attributes() as $tax => $slug ) {
		$t       = taxonomy_exists( $tax ) ? get_term_by( 'slug', $slug, $tax ) : false;
		$parts[] = $t ? $t->name : $slug;
	}
	return boutique_fa( implode( ' ', $parts ) );
}

// Hide the SKU line on the storefront (keep it in admin for accounting sync later).
add_filter( 'wc_product_sku_enabled', fn( $enabled ) => is_admin() ? $enabled : false );

// Footer copyright (Blocksy's default ends with an empty "theme by" credit in Persian).
add_filter( 'blocksy:footer:copyright:default-value', function () {
	// Blocksy's default footer has no menu, so the footer menu's links go on this line.
	$links = '';
	$loc   = get_nav_menu_locations();
	if ( ! empty( $loc['footer'] ) ) {
		foreach ( (array) wp_get_nav_menu_items( $loc['footer'] ) as $item ) {
			$links .= '<a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a>';
		}
	}
	return ( $links ? '<nav class="b-footlinks" aria-label="پیوندهای پایین صفحه">' . $links . '</nav>' : '' )
		. '<span>© {current_year} ' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
} );

// My account: no "downloads" (nothing digital is sold here).
add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	unset( $items['downloads'] );
	return $items;
} );

// Hide WordPress version in page source.
remove_action( 'wp_head', 'wp_generator' );
