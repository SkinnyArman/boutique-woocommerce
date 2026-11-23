<?php
/**
 * Seeds the store: attributes, categories, placeholder products (with generated
 * images), shipping zones and offline payment methods.
 *
 * Run: docker compose run --rm wpcli eval-file /scripts/store.php
 * Safe to re-run: each section checks whether it already ran.
 */

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

function say( $m ) { WP_CLI::log( $m ); }

/* =========================================================================
 * 1. Attributes: size + color
 * ====================================================================== */
$attrs = [
	'size'  => [ 'name' => 'سایز', 'terms' => [ '36' => '36', '38' => '38', '40' => '40', '42' => '42', '44' => '44', '46' => '46' ] ],
	'color' => [ 'name' => 'رنگ', 'terms' => [
		'black'  => 'مشکی',
		'cream'  => 'کرم',
		'pink'   => 'صورتی',
		'navy'   => 'سرمه‌ای',
		'maroon' => 'زرشکی',
		'green'  => 'سبز',
	] ],
];
$color_hex = [
	'black' => '#2b2527', 'cream' => '#e3d3b8', 'pink' => '#e8a5b0',
	'navy'  => '#2f3a5c', 'maroon' => '#7d1f33', 'green' => '#6f8f72',
];

$attr_ids = [];
foreach ( $attrs as $slug => $a ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( [ 'name' => $a['name'], 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order' ] );
		if ( is_wp_error( $id ) ) { WP_CLI::error( $id->get_error_message() ); }
		say( "Created attribute pa_$slug" );
	}
	$attr_ids[ $slug ] = $id;
	$tax = 'pa_' . $slug;
	if ( ! taxonomy_exists( $tax ) ) {
		register_taxonomy( $tax, 'product', [ 'hierarchical' => false ] );
	}
	$order = 0;
	foreach ( $a['terms'] as $term_slug => $label ) {
		if ( ! term_exists( (string) $term_slug, $tax ) ) {
			$t = wp_insert_term( $label, $tax, [ 'slug' => (string) $term_slug ] );
			if ( ! is_wp_error( $t ) ) { update_term_meta( $t['term_id'], 'order', $order ); }
		}
		$order++;
	}
}

/* =========================================================================
 * 2. Categories
 * ====================================================================== */
// Display order = this order (WooCommerce reads term meta "order").
$cats = [
	'pants'             => 'شلوار',
	'shomiz'            => 'شومیز',
	'evening'           => 'پیراهن مجلسی',
	'blouse'            => 'بلوز',
	'denim-jacket'      => 'کت جین',
	'knit-top'          => 'تاپ بافت',
	'polo-shirt'        => 'پولوشرت',
	'blouse-skirt-set'  => 'بلوز دامن مجلسی',
	'turkish-dress'     => 'پیراهن ترک',
	'swimsuit'          => 'مایو',
	'babydoll-dress'    => 'پیراهن عروسکی',
	'bikini'            => 'بیکینی',
	'sleepwear'         => 'لباس خواب',
	'jacket'            => 'کت',
	'sweatshirt'        => 'دورس',
	'hoodie'            => 'هودی',
	'skirt'             => 'دامن',
	'casual'            => 'پیراهن روزمره',
	'manteau'           => 'مانتو',
	'sets'              => 'ست و کت‌دامن',
	'shawl'             => 'شال',
	'hat'               => 'کلاه',
	'socks'             => 'جوراب',
	'sport-dress'       => 'پیراهن اسپرت',
	'leggings'          => 'لگ',
	'sport-leggings'    => 'لگ ورزشی',
];
$cat_ids = [];
foreach ( $cats as $slug => $name ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( ! $t ) {
		$r = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug ] );
		$cat_ids[ $slug ] = $r['term_id'];
	} else {
		$cat_ids[ $slug ] = $t->term_id;
	}
	update_term_meta( $cat_ids[ $slug ], 'order', array_search( $slug, array_keys( $cats ), true ) );
}
// Rename the default "Uncategorized" so it doesn't look broken.
$unc = get_term_by( 'slug', 'uncategorized', 'product_cat' );
if ( $unc ) { wp_update_term( $unc->term_id, 'product_cat', [ 'name' => 'سایر', 'slug' => 'other' ] ); }

/* =========================================================================
 * 3. Placeholder images (GD). A simple dress silhouette per color/style.
 * ====================================================================== */
function boutique_hex( $im, $hex, $alpha = 0 ) {
	$hex = ltrim( $hex, '#' );
	return imagecolorallocatealpha( $im, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
}

function boutique_make_image( $style, $hex, $filename ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) { return 0; }
	$S = 2; $W = 800 * $S; $H = 1000 * $S;          // draw at 2x, downscale = smooth edges
	$im = imagecreatetruecolor( $W, $H );

	// Soft vertical blush gradient.
	for ( $y = 0; $y < $H; $y++ ) {
		$t = $y / $H;
		$r = (int) ( 0xfb + ( 0xf3 - 0xfb ) * $t );
		$g = (int) ( 0xf1 + ( 0xdf - 0xf1 ) * $t );
		$b = (int) ( 0xee + ( 0xdc - 0xee ) * $t );
		imageline( $im, 0, $y, $W, $y, imagecolorallocate( $im, $r, $g, $b ) );
	}
	// Halo circle.
	imagefilledellipse( $im, $W / 2, (int) ( $H * 0.52 ), (int) ( $W * 0.78 ), (int) ( $W * 0.78 ), boutique_hex( $im, '#ffffff', 60 ) );

	$c  = boutique_hex( $im, $hex );
	$sh = boutique_hex( $im, '#000000', 105 );       // subtle shadow
	$hg = boutique_hex( $im, '#b9a3a0' );
	$p  = fn( array $pts ) => array_map( fn( $v ) => (int) ( $v * $S ), $pts );

	// Hanger.
	imagesetthickness( $im, 6 * $S );
	imagearc( $im, 400 * $S, 150 * $S, 40 * $S, 40 * $S, 180, 90, $hg );
	imageline( $im, 400 * $S, 170 * $S, 300 * $S, 230 * $S, $hg );
	imageline( $im, 400 * $S, 170 * $S, 500 * $S, 230 * $S, $hg );
	imagesetthickness( $im, 1 );

	switch ( $style ) {
		case 'gown':   // long evening gown
			$body = [ 345,240, 455,240, 470,300, 452,430, 600,905, 200,905, 348,430, 330,300 ];
			$hem  = 905; break;
		case 'dress':  // knee-length day dress with short sleeves
			$body = [ 330,235, 470,235, 530,290, 500,320, 462,300, 455,430, 560,760, 240,760, 345,430, 338,300, 300,320, 270,290 ];
			$hem  = 760; break;
		case 'coat':   // long manteau with long sleeves
			$body = [ 330,230, 470,230, 545,300, 575,620, 535,625, 500,360, 492,880, 308,880, 300,360, 265,625, 225,620, 255,300 ];
			$hem  = 880; break;
		default:       // 'set': jacket + skirt
			$body = [ 325,235, 475,235, 540,300, 560,560, 522,562, 495,350, 492,520, 308,520, 305,350, 278,562, 240,560, 260,300 ];
			$hem  = 520;
			imagefilledpolygon( $im, $p( [ 322,535, 478,535, 530,820, 270,820 ] ), $c );
	}
	// Shadow offset, then body.
	$shadow = $body;
	for ( $i = 0; $i < count( $shadow ); $i += 2 ) { $shadow[ $i ] += 10; $shadow[ $i + 1 ] += 12; }
	imagefilledpolygon( $im, $p( $shadow ), $sh );
	imagefilledpolygon( $im, $p( $body ), $c );

	// Waist detail / neckline highlight.
	$hl = boutique_hex( $im, '#ffffff', 95 );
	if ( 'coat' === $style ) {
		imagesetthickness( $im, 3 * $S );
		imageline( $im, 400 * $S, 240 * $S, 400 * $S, $hem * $S, boutique_hex( $im, '#000000', 90 ) );
		imagesetthickness( $im, 1 );
	} else {
		imagefilledpolygon( $im, $p( [ 345,418, 455,418, 457,440, 343,440 ] ), $hl );
	}
	imagefilledellipse( $im, 400 * $S, 236 * $S, 70 * $S, 36 * $S, boutique_hex( $im, '#000000', 80 ) );

	$out = imagecreatetruecolor( 800, 1000 );
	imagecopyresampled( $out, $im, 0, 0, 0, 0, 800, 1000, $W, $H );
	$tmp = wp_tempnam( $filename );
	imagejpeg( $out, $tmp, 85 );
	imagedestroy( $im ); imagedestroy( $out );

	$id = media_handle_sideload( [ 'name' => $filename . '.jpg', 'tmp_name' => $tmp ], 0 );
	return is_wp_error( $id ) ? 0 : $id;
}

/* =========================================================================
 * 4. Placeholder products
 * ====================================================================== */
$products = [
	[ 'نیلوفر',  'پیراهن مجلسی ساتن',      'evening', 'gown',  4850000, 0,       [ 'maroon', 'black' ], 'پیراهن مجلسی بلند از پارچه ساتن براق با یقه قایقی و دامن کلوش. مناسب مهمانی‌های رسمی و عروسی.' ],
	[ 'شب‌تاب',  'پیراهن مجلسی پولکی',     'evening', 'gown',  6200000, 5400000, [ 'black', 'navy' ],    'پیراهن پولکی دست‌دوز با آستر نرم. درخشش ملایم زیر نور، بدون سنگینی.' ],
	[ 'مهتاب',   'پیراهن ماکسی حریر',      'evening', 'gown',  5300000, 0,       [ 'navy', 'green' ],    'ماکسی حریر دولایه با کمر چین‌دار؛ سبک و روان، مناسب مهمانی‌های عصرانه.' ],
	[ 'یاس',     'پیراهن کرپ',              'casual',  'dress', 2350000, 0,       [ 'cream', 'pink' ],    'پیراهن کرپ روزمره با آستین کوتاه و قد تا زانو. راحت برای استفاده هر روز.' ],
	[ 'بهار',    'پیراهن نخی گلدار',        'casual',  'dress', 1890000, 1590000, [ 'pink', 'green' ],    'پیراهن نخی خنک با طرح گل‌های ریز؛ مناسب روزهای گرم.' ],
	[ 'آیلین',   'مانتو کتان',              'manteau', 'coat',  2750000, 0,       [ 'cream', 'black' ],   'مانتو کتان جلوبسته با جیب‌های مخفی و دوخت تمیز.' ],
	[ 'ترمه',    'مانتو بلند جلوباز',       'manteau', 'coat',  3100000, 0,       [ 'green', 'navy' ],    'مانتو بلند جلوباز از پارچه مازراتی با کمربند هم‌رنگ.' ],
	[ 'رز',      'کت و دامن',               'sets',    'set',   4200000, 3650000, [ 'pink', 'cream' ],    'ست کت کوتاه و دامن میدی؛ رسمی ولی نرم و زنانه.' ],
];
$sizes = [ '38', '40', '42', '44' ];

if ( get_option( 'boutique_seeded_products' ) ) {
	say( 'Products already seeded, skipping.' );
} else {
	mt_srand( 1405 );
	$cat_thumb = [];
	foreach ( $products as $i => [ $name, $type, $cat, $style, $price, $sale, $colors, $desc ] ) {
		$title = "$type «{$name}»";

		// One image per color.
		$img = [];
		foreach ( $colors as $col ) {
			$img[ $col ] = boutique_make_image( $style, $color_hex[ $col ], sanitize_title( "dress-$i-$col" ) );
		}

		$p = new WC_Product_Variable();
		$p->set_name( $title );
		$p->set_status( 'publish' );
		$p->set_category_ids( [ $cat_ids[ $cat ] ] );
		$p->set_short_description( $desc );
		$p->set_description( $desc . "\n\nجنس پارچه، قد و جزئیات دوخت را در اینجا بنویسید. شستشو: با آب سرد و دست." );
		$p->set_image_id( reset( $img ) );
		$p->set_gallery_image_ids( array_values( array_slice( $img, 1 ) ) );
		if ( $i < 4 ) { $p->set_featured( true ); }

		$size_attr = new WC_Product_Attribute();
		$size_attr->set_id( $attr_ids['size'] );
		$size_attr->set_name( 'pa_size' );
		$size_attr->set_options( array_map( fn( $s ) => get_term_by( 'slug', $s, 'pa_size' )->term_id, $sizes ) );
		$size_attr->set_visible( true );
		$size_attr->set_variation( true );
		$size_attr->set_position( 0 );

		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( $attr_ids['color'] );
		$color_attr->set_name( 'pa_color' );
		$color_attr->set_options( array_map( fn( $c ) => get_term_by( 'slug', $c, 'pa_color' )->term_id, $colors ) );
		$color_attr->set_visible( true );
		$color_attr->set_variation( true );
		$color_attr->set_position( 1 );

		$p->set_attributes( [ $color_attr, $size_attr ] );
		$p->set_default_attributes( [ 'pa_color' => $colors[0] ] );
		$pid = $p->save();

		foreach ( $colors as $col ) {
			foreach ( $sizes as $sz ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $pid );
				$v->set_attributes( [ 'pa_color' => $col, 'pa_size' => $sz ] );
				$v->set_regular_price( (string) $price );
				if ( $sale ) { $v->set_sale_price( (string) $sale ); }
				$v->set_manage_stock( true );
				$v->set_stock_quantity( mt_rand( 0, 4 ) );   // some sizes sold out on purpose
				$v->set_image_id( $img[ $col ] );
				$v->set_status( 'publish' );
				$v->save();
			}
		}
		WC_Product_Variable::sync( $pid );
		if ( ! isset( $cat_thumb[ $cat ] ) ) { $cat_thumb[ $cat ] = reset( $img ); }
		say( "Product: $title (#$pid)" );
	}
	foreach ( $cat_thumb as $cat => $att ) {
		update_term_meta( $cat_ids[ $cat ], 'thumbnail_id', $att );
	}
	update_option( 'boutique_seeded_products', 1 );
}

/* =========================================================================
 * 5. Shipping zones (placeholder prices in toman; edit in
 *    WooCommerce > Settings > Shipping)
 * ====================================================================== */
function boutique_add_method( WC_Shipping_Zone $z, $type, array $settings ) {
	$iid = $z->add_shipping_method( $type );
	update_option( "woocommerce_{$type}_{$iid}_settings", array_merge( [ 'tax_status' => 'none' ], $settings ) );
}

if ( get_option( 'boutique_seeded_shipping' ) ) {
	say( 'Shipping already set up, skipping.' );
} else {
	// Zone 1: the shop's own city (Tehran as placeholder; change the region later).
	$city = new WC_Shipping_Zone();
	$city->set_zone_name( 'شهر فروشگاه (' . boutique_shop( 'city' ) . ')' );
	$city->set_zone_order( 1 );
	$city->add_location( 'IR:' . boutique_shop( 'state_code' ), 'state' );
	$city->save();
	boutique_add_method( $city, 'flat_rate',    [ 'title' => 'پیک درون‌شهری (اسنپ‌باکس / الوپیک)', 'cost' => '120000' ] );
	boutique_add_method( $city, 'local_pickup', [ 'title' => 'تحویل حضوری از فروشگاه', 'cost' => '0' ] );
	boutique_add_method( $city, 'flat_rate',    [ 'title' => 'پست پیشتاز', 'cost' => '60000' ] );

	// Zone 2: rest of Iran.
	$iran = new WC_Shipping_Zone();
	$iran->set_zone_name( 'سراسر ایران' );
	$iran->set_zone_order( 2 );
	$iran->add_location( 'IR', 'country' );
	$iran->save();
	boutique_add_method( $iran, 'flat_rate', [ 'title' => 'پست پیشتاز', 'cost' => '90000' ] );
	boutique_add_method( $iran, 'flat_rate', [ 'title' => 'تیپاکس', 'cost' => '150000' ] );

	update_option( 'boutique_seeded_shipping', 1 );
	say( 'Shipping zones created.' );
}

/* =========================================================================
 * 6. Offline payments until eNamad + gateway are approved
 * ====================================================================== */
update_option( 'woocommerce_bacs_settings', [
	'enabled'      => 'yes',
	'title'        => 'کارت به کارت',
	'description'  => 'مبلغ سفارش را به شماره کارت زیر واریز کنید و تصویر رسید را در واتساپ/تلگرام برای ما بفرستید. سفارش پس از تأیید واریز ارسال می‌شود.',
	'instructions' => "شماره کارت: ۶۰۳۷-۰۰۰۰-۰۰۰۰-۰۰۰۰ (به نام: ...)\nپس از واریز، شماره سفارش و تصویر رسید را به ۰۹۱۲۰۰۰۰۰۰۰ بفرستید.",
	'account_details' => '',
] );
update_option( 'woocommerce_bacs_accounts', [] );
update_option( 'woocommerce_cod_settings', [
	'enabled'            => 'yes',
	'title'              => 'پرداخت در محل (هنگام تحویل)',
	'description'        => 'هزینه سفارش را هنگام تحویل به پیک یا در فروشگاه پرداخت کنید.',
	'instructions'       => 'هزینه سفارش را هنگام تحویل پرداخت کنید.',
	'enable_for_methods' => [],
	'enable_for_virtual' => 'no',
] );
update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'no' ] );

say( 'Store seeding done.' );
