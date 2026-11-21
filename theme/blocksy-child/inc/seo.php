<?php
/**
 * Search engines and link previews, without an SEO plugin (they add admin clutter, upsells and
 * outside requests). What this covers:
 *  - Page titles:  «نام لباس | نام فروشگاه», home = name + what she sells + Tehran.
 *  - <meta name="description"> for every page type, written from real product data.
 *  - Open Graph / Twitter tags: a pasted link in Telegram/WhatsApp shows the dress photo.
 *  - schema.org: the shop as a ClothingStore (address, hours, phone, Instagram/Telegram) on the
 *    home and contact pages; FAQPage on the FAQ; products (WooCommerce's own markup) get a brand
 *    and lose the "offer" when the price is «استعلام قیمت» (an empty price is a Search Console error).
 *  - noindex on pages that shouldn't be in Google (track, search, sorted/filtered lists).
 *  - Sitemap (WordPress's own, /wp-sitemap.xml) without user pages or utility pages.
 *  - Product photos carry the product name as alt text (set when photos are added: the
 *    importer, «لباس جدید», and scripts/seo.php for existing ones).
 * Meta keywords / tags are not used: Google ignores them.
 */
defined( 'ABSPATH' ) || exit;

// BOUTIQUE_SEO_TAGLINE, BOUTIQUE_GOOGLE_VERIFY and the shop facts come from config/shop.php.

/* ------------------------------------------------------------- Titles */
add_filter( 'document_title_separator', fn() => '|' );
add_filter( 'document_title_parts', function ( $parts ) {
	if ( is_front_page() ) {
		return [ 'title' => get_bloginfo( 'name' ), 'tagline' => BOUTIQUE_SEO_TAGLINE ];
	}
	if ( is_product() ) {
		$p     = wc_get_product( get_queried_object_id() );
		$brand = $p ? boutique_product_brand( $p ) : '';
		if ( $brand && false === mb_stripos( $parts['title'], $brand ) ) { $parts['title'] .= ' ' . $brand; }
	}
	if ( is_product_category() ) { $parts['title'] = single_term_title( '', false ) . ' زنانه'; }
	return $parts;
} );

/* ------------------------------------------------------------- Helpers */
function boutique_product_brand( WC_Product $p ) {
	$t = taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $p->get_id(), 'product_brand', [ 'fields' => 'names' ] ) : [];
	return ( $t && ! is_wp_error( $t ) ) ? $t[0] : '';
}

function boutique_clip( $text, $max = 160 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( (string) $text ) ) ) );
	return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
}

function boutique_meta_description() {
	if ( is_front_page() ) {
		return boutique_shop( 'seo_home' );
	}
	if ( is_product() ) {
		$p = wc_get_product( get_queried_object_id() );
		if ( ! $p ) { return ''; }
		$brand = boutique_product_brand( $p );
		$sizes = [];
		foreach ( $p->is_type( 'variable' ) ? $p->get_children() : [] as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_in_stock() && ( $s = $v->get_attribute( 'pa_size' ) ) ) { $sizes[] = $s; }
		}
		$mat   = (string) get_post_meta( $p->get_id(), '_boutique_material', true );
		$price = boutique_is_ask_price( $p ) ? 'قیمت با استعلام' : ( $p->get_price() ? 'قیمت ' . wp_strip_all_tags( wc_price( $p->get_price() ) ) : '' );
		return boutique_clip( 'خرید ' . $p->get_name() . ( $brand && false === mb_stripos( $p->get_name(), $brand ) ? ' ' . $brand : '' )
			. ( $mat ? '، جنس ' . $mat : '' )
			. ( $sizes ? '، در سایز ' . implode( '، ', array_unique( $sizes ) ) : '' ) . ' از ' . boutique_shop( 'name' ) . ' ' . boutique_shop( 'city' ) . '. '
			. ( $price ? $price . '؛ ' : '' ) . 'ارسال به سراسر ایران و امکان پرو حضوری.' );
	}
	if ( is_product_category() || is_tax( 'product_brand' ) ) {
		$t = get_queried_object();
		return boutique_clip( $t->description ?: 'خرید ' . $t->name . ' زنانه در ' . boutique_shop( 'name' ) . ' ' . boutique_shop( 'city' ) . ': ' . boutique_fa( $t->count )
			. ' مدل با عکس واقعی، راهنمای سایز، ارسال به سراسر ایران و پرو حضوری.' );
	}
	if ( is_shop() ) {
		return boutique_shop( 'seo_shop' );
	}
	if ( is_singular() ) {
		$post = get_queried_object();
		return boutique_clip( $post->post_excerpt ?: $post->post_content );
	}
	return get_bloginfo( 'description' );
}

function boutique_share_image() {
	if ( is_product() && ( $p = wc_get_product( get_queried_object_id() ) ) && $p->get_image_id() ) {
		return wp_get_attachment_image_url( $p->get_image_id(), 'large' );
	}
	if ( is_product_category() && ( $id = boutique_category_image_id( get_queried_object() ) ) ) {
		return wp_get_attachment_image_url( $id, 'large' );
	}
	return get_stylesheet_directory_uri() . '/brand/instagram-profile.png';
}

/* ------------------------------------------------- <head>: description + previews */
add_action( 'wp_head', function () {
	if ( is_admin() || is_feed() ) { return; }
	$desc  = boutique_meta_description();
	$title = wp_get_document_title();
	$url   = is_singular() ? get_permalink() : ( is_tax() || is_product_category() ? get_term_link( get_queried_object() ) : home_url( add_query_arg( [] ) ) );
	$url   = is_wp_error( $url ) ? home_url( '/' ) : strtok( $url, '?' );
	$img   = boutique_share_image();
	$tags  = [
		[ 'name', 'description', $desc ],
		[ 'property', 'og:site_name', get_bloginfo( 'name' ) ],
		[ 'property', 'og:locale', 'fa_IR' ],
		[ 'property', 'og:type', is_product() ? 'product' : 'website' ],
		[ 'property', 'og:title', $title ],
		[ 'property', 'og:description', $desc ],
		[ 'property', 'og:url', $url ],
		[ 'property', 'og:image', $img ],
		[ 'name', 'twitter:card', 'summary_large_image' ],
	];
	if ( BOUTIQUE_GOOGLE_VERIFY ) { $tags[] = [ 'name', 'google-site-verification', BOUTIQUE_GOOGLE_VERIFY ]; }
	foreach ( $tags as [ $attr, $key, $val ] ) {
		if ( '' !== (string) $val ) {
			printf( '<meta %s="%s" content="%s">' . "\n", $attr, esc_attr( $key ), esc_attr( $val ) );
		}
	}
}, 2 );

/* ------------------------------------------------- schema.org */
function boutique_store_schema() {
	$icon = get_site_icon_url( 512 );
	$data = [
		'@context'  => 'https://schema.org',
		'@type'     => 'ClothingStore',
		'@id'       => home_url( '/#store' ),
		'name'      => get_bloginfo( 'name' ),
		'description' => get_bloginfo( 'description' ),
		'url'       => home_url( '/' ),
		'telephone' => '+98' . ltrim( BOUTIQUE_PHONE, '0' ),
		'email'     => boutique_shop( 'email' ),
		'image'     => get_stylesheet_directory_uri() . '/brand/instagram-profile.png',
		'address'   => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => BOUTIQUE_STREET,
			'addressLocality' => boutique_shop( 'city' ),
			'addressRegion'   => boutique_shop( 'city' ),
			'postalCode'      => boutique_shop( 'postcode' ),
			'addressCountry'  => 'IR',
		],
		'openingHoursSpecification' => [ [
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => BOUTIQUE_OPEN_DAYS,
			'opens'     => BOUTIQUE_OPEN_HOURS[0],
			'closes'    => BOUTIQUE_OPEN_HOURS[1],
		] ],
		'sameAs'    => boutique_same_as(),
		'currenciesAccepted' => 'IRR',
	];
	if ( $icon ) { $data['logo'] = $icon; }
	if ( BOUTIQUE_MAP_URL ) { $data['hasMap'] = BOUTIQUE_MAP_URL; }
	return $data;
}

/** The shop's social profiles, for search engines. */
function boutique_same_as() {
	return array_values( array_filter( [
		BOUTIQUE_INSTAGRAM ? 'https://instagram.com/' . BOUTIQUE_INSTAGRAM : '',
		BOUTIQUE_TELEGRAM ? 'https://t.me/' . BOUTIQUE_TELEGRAM : '',
	] ) );
}

function boutique_jsonld( array $data ) {
	echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

add_action( 'wp_head', function () {
	if ( is_front_page() ) {
		boutique_jsonld( [ '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'inLanguage' => 'fa-IR' ] );
	}
	if ( is_front_page() || is_page( 'contact' ) ) { boutique_jsonld( boutique_store_schema() ); }
}, 3 );

// Product markup (WooCommerce prints it): brand, and no "offer" when the price isn't public.
add_filter( 'woocommerce_structured_data_product', function ( $markup, $product ) {
	if ( boutique_is_ask_price( $product ) ) { unset( $markup['offers'] ); }
	if ( $m = get_post_meta( $product->get_id(), '_boutique_material', true ) ) { $markup['material'] = $m; }
	if ( empty( $markup['brand'] ) && ( $b = boutique_product_brand( $product ) ) ) {
		$markup['brand'] = [ '@type' => 'Brand', 'name' => $b ];
	}
	// Merchant listing fields Google asks for: price start date, delivery cost/time, return policy.
	if ( ! empty( $markup['offers'] ) ) {
		$from = $product->get_date_modified() ?: $product->get_date_created();
		$from = $from ? $from->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		foreach ( $markup['offers'] as &$offer ) {
			$offer['validFrom'] = $from;
			if ( isset( $offer['priceSpecification'] ) && is_array( $offer['priceSpecification'] ) ) {
				foreach ( $offer['priceSpecification'] as &$spec ) { $spec['validFrom'] = $from; }
				unset( $spec );
			}
			$cur = $offer['priceCurrency'] ?? 'IRR';
			if ( $ship = boutique_shipping_details( $cur ) ) { $offer['shippingDetails'] = $ship; }
			$offer['hasMerchantReturnPolicy'] = [
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => 'IR',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted', // the shop takes no returns
				'merchantReturnLink'   => home_url( '/terms/' ),
			];
		}
		unset( $offer );
	}
	return $markup;
}, 20, 2 );

/**
 * Delivery cost and time for product markup, from the live shipping settings: the cheapest paid
 * method a customer anywhere in Iran can pick (couriers paid on delivery and pickup are skipped).
 * Times match the terms page: sent within 2 working days, delivered in 1–5.
 */
function boutique_shipping_details( $currency ) {
	static $cost = null;
	if ( null === $cost ) {
		$cost = 0;
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			$paid = [];
			foreach ( $z['shipping_methods'] as $m ) {
				$c = (float) $m->get_option( 'cost' );
				if ( $m->is_enabled() && 'flat_rate' === $m->id && $c > 0 ) { $paid[] = $c; }
			}
			if ( $paid ) { $cost = max( $cost, min( $paid ) ); } // the zone where the cheapest option costs most
		}
	}
	if ( ! $cost ) { return null; }
	$value = 'IRR' === $currency && 'IRT' === get_woocommerce_currency() ? $cost * 10 : $cost;
	$days  = fn( $min, $max ) => [ '@type' => 'QuantitativeValue', 'minValue' => $min, 'maxValue' => $max, 'unitCode' => 'DAY' ];
	return [
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => [ '@type' => 'MonetaryAmount', 'value' => $value, 'currency' => $currency ],
		'shippingDestination' => [ '@type' => 'DefinedRegion', 'addressCountry' => 'IR' ],
		'deliveryTime'        => [ '@type' => 'ShippingDeliveryTime', 'handlingTime' => $days( 0, 2 ), 'transitTime' => $days( 1, 5 ) ],
	];
}

/* ------------------------------------------------- Canonical for lists
 * WordPress only prints rel=canonical on single pages. Without it Google treats the shop,
 * category and brand pages (and their ?query copies) as "duplicate without user-selected canonical". */
add_action( 'wp_head', function () {
	if ( ! ( is_shop() || is_product_taxonomy() || is_tax( 'product_brand' ) ) || is_search() || is_404() ) { return; }
	$base = is_shop() ? get_permalink( wc_get_page_id( 'shop' ) ) : get_term_link( get_queried_object() );
	if ( is_wp_error( $base ) || ! $base ) { return; }
	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$url   = $paged > 1 ? trailingslashit( $base ) . user_trailingslashit( 'page/' . $paged, 'paged' ) : $base;
	printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
}, 4 );

/* ------------------------------------------------- What Google should skip */
add_filter( 'wp_robots', function ( $robots ) {
	$utility  = is_search() || is_page( 'track' ) || is_404();
	$filtered = array_intersect( array_keys( $_GET ), [ 'orderby', 'min_price', 'max_price', 'rating_filter', 'add-to-cart' ] )
		|| preg_grep( '/^filter_/', array_keys( $_GET ) );
	if ( $utility || $filtered ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

add_filter( 'robots_txt', function ( $out, $public ) {
	if ( ! $public ) { return $out; }
	// Before the Sitemap line, inside the "User-agent: *" group.
	return preg_replace( '/\nSitemap:/', "Disallow: /*?orderby=\nDisallow: /*?s=\n\nSitemap:", $out, 1 );
}, 10, 2 );

/* ------------------------------------------------- Sitemap: content only */
add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );
add_filter( 'wp_sitemaps_taxonomies', function ( $tax ) {
	unset( $tax['product_tag'], $tax['post_tag'], $tax['post_format'] );
	if ( ! wp_count_posts( 'post' )->publish ) { unset( $tax['category'] ); }  // no blog yet
	return $tax;
} );
add_filter( 'wp_sitemaps_post_types', function ( $types ) {
	if ( ! wp_count_posts( 'post' )->publish ) { unset( $types['post'] ); }
	return $types;
} );
add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $type ) {
	if ( 'page' === $type ) {
		$skip = array_filter( [ wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ), (int) ( get_page_by_path( 'track' )->ID ?? 0 ) ] );
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? [], $skip );
	}
	return $args;
}, 10, 2 );

/* ------------------------------------------------- Image alt text */
/** Give a product's photos the product name as alt text, where they have none. */
function boutique_fill_alt( $product_id ) {
	$p = wc_get_product( $product_id );
	if ( ! $p ) { return; }
	foreach ( array_filter( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) ) as $att ) {
		if ( '' === trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $p->get_name() );
		}
	}
}
add_action( 'woocommerce_new_product', 'boutique_fill_alt' );
add_action( 'woocommerce_update_product', 'boutique_fill_alt' );


/* ------------------------------------------------- Site icon (browser tab + Google results)
 * Google wants square icons in multiples of 48px; WordPress was serving 100 and 300px copies.
 * Same logo, exact sizes, plus /favicon.ico at the site root (uploaded with the theme). */
add_filter( 'site_icon_meta_tags', function () {
	$b = get_stylesheet_directory_uri() . '/brand/';
	return [
		'<link rel="icon" href="' . esc_url( home_url( '/favicon.ico' ) ) . '" sizes="48x48">',
		'<link rel="icon" type="image/png" href="' . esc_url( $b . 'favicon-48.png' ) . '" sizes="48x48">',
		'<link rel="icon" type="image/png" href="' . esc_url( $b . 'favicon-96.png' ) . '" sizes="96x96">',
		'<link rel="icon" type="image/png" href="' . esc_url( $b . 'favicon-192.png' ) . '" sizes="192x192">',
		'<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url( get_stylesheet_directory_uri() . '/app/icon-180.png' ) . '">', // admin/login: boutique-admin swaps in its own
	];
} );

// The shop as an organization with its logo (helps Google show the brand and its logo).
add_action( 'wp_head', function () {
	if ( ! is_front_page() ) { return; }
	boutique_jsonld( [
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'@id'      => home_url( '/#org' ),
		'name'     => get_bloginfo( 'name' ),
		'alternateName' => array_values( array_filter( [ boutique_shop( 'name_latin' ), boutique_shop( 'instagram' ) ] ) ),
		'url'      => home_url( '/' ),
		'logo'     => get_stylesheet_directory_uri() . '/brand/site-icon.png',
		'sameAs'   => boutique_same_as(),
	] );
}, 3 );
