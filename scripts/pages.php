<?php
/**
 * Creates content pages, the home page, menus; switches cart/checkout to the
 * classic shortcodes (Persian WooCommerce's province/city fields and most
 * Iranian gateway plugins work with the classic checkout).
 *
 * Run: docker compose run --rm wpcli eval-file /scripts/pages.php
 */

function boutique_page( $slug, $title, $content, $hide_title = false ) {
	$existing = get_page_by_path( $slug );
	$data = [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	];
	if ( $existing ) {
		$data['ID'] = $existing->ID;
		$id = wp_update_post( $data );
	} else {
		$id = wp_insert_post( $data );
	}
	if ( $hide_title ) {
		update_post_meta( $id, 'blocksy_post_meta_options', [ 'has_hero_section' => 'disabled' ] );
	}
	WP_CLI::log( "Page: $title (#$id)" );
	return $id;
}

function p( $text ) { return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->\n\n"; }
function h( $text, $level = 2, $class = '' ) {
	$attrs = $level !== 2 ? [ 'level' => $level ] : [];
	if ( $class ) { $attrs['className'] = $class; }
	$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) : '';
	$cls  = 'wp-block-heading' . ( $class ? " $class" : '' );
	return "<!-- wp:heading$json -->\n<h$level class=\"$cls\">$text</h$level>\n<!-- /wp:heading -->\n\n";
}
function sc( $code ) { return "<!-- wp:shortcode -->\n$code\n<!-- /wp:shortcode -->\n\n"; }
function ul( array $items ) {
	$li = implode( '', array_map( fn( $i ) => "<!-- wp:list-item -->\n<li>$i</li>\n<!-- /wp:list-item -->\n", $items ) );
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">$li</ul>\n<!-- /wp:list -->\n\n";
}

// WordPress's sample post ("Hello world!") and page: not content, and they'd show up in Google.
foreach ( [ [ 'hello-world', 'post' ], [ 'sample-page', 'page' ] ] as [ $slug, $type ] ) {
	$sample = get_page_by_path( $slug, OBJECT, $type );
	if ( $sample ) { wp_delete_post( $sample->ID, true ); }
}

/* ---------------------------------------------------------------- Home */
// Sections are rendered by the child theme (theme/blocksy-child/inc/home.php) from live
// store data, so prices, sale %, and category counts are always real.
// Order: hero -> promise ribbon -> SALE (deliberately 2nd) -> categories -> new -> visit.
$home = '';
foreach ( [ 'boutique_hero', 'boutique_ribbon', 'boutique_sale', 'boutique_categories', 'boutique_new', 'boutique_visit' ] as $code ) {
	$home .= sc( "[$code]" );
}

$home_id = boutique_page( 'home', 'خانه', $home, true );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );

/* ---------------------------------------------------------- Info pages */
$about_id = boutique_page( 'about', 'درباره ما',
	implode( '', array_map( 'p', (array) boutique_shop( 'about' ) ) )
);

// Contact: info on one side, a compact map on the other (stacks on phones).
function grp( $class, $inner ) {
	return "<!-- wp:group {\"className\":\"$class\"} -->\n<div class=\"wp-block-group $class\">$inner</div>\n<!-- /wp:group -->\n\n";
}
$contact_id = boutique_page( 'contact', 'تماس با ما',
	grp( 'b-contact',
		grp( 'b-contact__info',
			p( 'برای مشاوره سایز، پیگیری سفارش یا هر سؤالی با ما در تماس باشید.' ) .
			sc( '[boutique_contact]' ) .
			sc( '[boutique_social]' ) .
			p( 'جواب بیشتر سؤال‌ها را در <a href="/faq/">سؤالات متداول</a> نوشته‌ایم.' )
		) .
		sc( '[boutique_map]' )
	)
);
// Wider page so the two columns fit (Blocksy's "normal" content width).
update_post_meta( $contact_id, 'blocksy_post_meta_options', [ 'page_structure_type' => 'type-4' ] );

$faq_id = boutique_page( 'faq', 'سؤالات متداول',
	p( 'اگر جواب سؤالتان اینجا نبود، در <a href="https://t.me/' . BOUTIQUE_ORDER_TG . '">تلگرام</a> یا با <a href="tel:' . BOUTIQUE_PHONE . '">' . boutique_fa( BOUTIQUE_PHONE ) . '</a> بپرسید.' ) .
	sc( '[boutique_faq]' )
);

$cats_page_id = boutique_page( 'categories', 'دسته‌بندی‌ها', sc( '[boutique_all_categories]' ) );
$track_id     = boutique_page( 'track', 'پیگیری سفارش',
	p( 'شماره سفارش و موبایلی که با آن خرید کردید را وارد کنید.' ) . sc( '[boutique_track]' ) );

$terms_id = boutique_page( 'terms', 'قوانین و مقررات',
	h( 'ثبت سفارش' ) .
	p( 'ثبت سفارش در سایت به معنای پذیرش این قوانین است. قیمت‌ها به تومان و شامل همه هزینه‌ها به جز هزینه ارسال است.' ) .
	h( 'پرداخت' ) .
	p( 'پرداخت با کارت به کارت (و به‌زودی درگاه بانکی) است. سفارش‌های کارت به کارت پس از تأیید واریز پردازش می‌شوند. اگر رسید واریز تا ۲۴ ساعت بعد از ثبت سفارش نرسد، سفارش خودکار لغو می‌شود تا لباس برای دیگران آزاد شود. کرایه اسنپ پیک را مشتری هنگام تحویل به پیک می‌پردازد.' ) .
	h( 'ارسال' ) .
	p( 'سفارش‌ها حداکثر ظرف ۲ روز کاری پس از تأیید پرداخت ارسال می‌شوند. زمان تحویل بسته به روش ارسال و شهر مقصد ۱ تا ۵ روز کاری است. ارسال با پیک فقط داخل شهر ' . BOUTIQUE_COURIER_CITY . ' است.' ) .
	h( 'تحویل حضوری' ) .
	p( 'برای تحویل حضوری، لطفاً قبل از مراجعه با فروشگاه تماس بگیرید تا سفارش آماده باشد.' ) .
	h( 'حریم خصوصی' ) .
	p( 'اطلاعات شما فقط برای پردازش و ارسال سفارش استفاده می‌شود و در اختیار شخص دیگری قرار نمی‌گیرد.' )
);

// No returns (مرجوعی): the old returns page is removed.
$old_returns = get_page_by_path( 'returns' );
if ( $old_returns ) { wp_delete_post( $old_returns->ID, true ); }

/* --------------------------- Cart / Checkout: classic shortcodes */
wp_update_post( [ 'ID' => wc_get_page_id( 'cart' ),     'post_content' => sc( '[woocommerce_cart]' ) ] );
wp_update_post( [ 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => sc( '[woocommerce_checkout]' ) ] );
update_option( 'woocommerce_terms_page_id', $terms_id );

// WooCommerce created these pages before the site was Persian; give them Persian titles.
foreach ( [ 'shop' => 'همه لباس‌ها', 'cart' => 'سبد خرید', 'checkout' => 'تسویه حساب', 'myaccount' => 'حساب کاربری' ] as $key => $title ) {
	wp_update_post( [ 'ID' => wc_get_page_id( $key ), 'post_title' => $title ] );
}
// Privacy policy: WordPress created it in English before the site was Persian. Checkout links to
// it ("your data is used only for…"), so it stays, rewritten for this shop.
$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
if ( $privacy ) {
	wp_update_post( [ 'ID' => $privacy, 'post_title' => 'حریم خصوصی', 'post_status' => 'publish', 'post_content' =>
		p( boutique_shop( 'name' ) . ' فقط اطلاعاتی را می‌گیرد که برای ثبت و ارسال سفارش لازم است، و آن را به کسی نمی‌فروشد.' ) .
		h( 'چه اطلاعاتی می‌گیریم' ) .
		ul( [
			'نام، شماره موبایل و آدرس شما برای ارسال سفارش و تماس درباره آن.',
			'لوکیشنی که برای پیک می‌فرستید (اختیاری).',
			'تصویر رسید کارت به کارت، اگر آن را در سایت آپلود کنید.',
			'اطلاعات سفارش‌هایتان، برای پیگیری سفارش.',
		] ) .
		h( 'با چه کسی به اشتراک می‌گذاریم' ) .
		p( 'فقط با پیک، پست یا تیپاکس، و فقط همان نام، شماره و آدرسی که برای رساندن بسته لازم است.' ) .
		h( 'نگهداری و امنیت' ) .
		p( 'اطلاعات روی سرور داخل ایران نگهداری می‌شود. رسیدهای واریز در پوشه‌ای خصوصی ذخیره می‌شوند که فقط مدیر فروشگاه به آن دسترسی دارد.' ) .
		h( 'کوکی‌ها' ) .
		p( 'سایت فقط برای نگه داشتن سبد خرید، ورود به حساب کاربری و انتخاب حالت روشن یا تیره از کوکی و حافظه مرورگر استفاده می‌کند. هیچ ردیاب تبلیغاتی نداریم.' ) .
		h( 'حذف اطلاعات' ) .
		p( 'اگر می‌خواهید اطلاعاتتان حذف شود، از طریق <a href="/contact/">صفحه تماس</a> به ما بگویید.' ),
	] );
}

// Checkout: phone required (WooCommerce's own setting wins over field filters).
update_option( 'woocommerce_checkout_phone_field', 'required' );
update_option( 'woocommerce_checkout_company_field', 'hidden' );
update_option( 'woocommerce_checkout_address_2_field', 'optional' );
update_option( 'woocommerce_checkout_privacy_policy_text', 'اطلاعات شما فقط برای پردازش سفارش و ارسال آن استفاده می‌شود. جزئیات بیشتر در [privacy_policy].' );
update_option( 'woocommerce_registration_privacy_policy_text', 'اطلاعات شما فقط برای مدیریت حساب کاربری و سفارش‌ها استفاده می‌شود. جزئیات بیشتر در [privacy_policy].' );

/* -------------------------------------------------------------- Menus */
function boutique_menu( $name, array $items ) {
	$m = wp_get_nav_menu_object( $name );
	if ( $m ) { wp_delete_nav_menu( $m->term_id ); }
	$mid = wp_create_nav_menu( $name );
	foreach ( $items as $it ) {
		wp_update_nav_menu_item( $mid, 0, array_merge( [ 'menu-item-status' => 'publish' ], $it ) );
	}
	return $mid;
}
$page_item = fn( $id ) => [ 'menu-item-object' => 'page', 'menu-item-object-id' => $id, 'menu-item-type' => 'post_type' ];
$cat_item  = function ( $slug ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	return [ 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $t->term_id, 'menu-item-type' => 'taxonomy' ];
};

// Main menu: خانه · فروشگاه · دسته‌بندی‌ها ▾ (every category) · حراج · درباره ما · تماس
$m = wp_get_nav_menu_object( 'منوی اصلی' );
if ( $m ) { wp_delete_nav_menu( $m->term_id ); }
$main = wp_create_nav_menu( 'منوی اصلی' );
$add  = fn( array $it ) => wp_update_nav_menu_item( $main, 0, [ 'menu-item-status' => 'publish' ] + $it );
$add( $page_item( $home_id ) );
$add( $page_item( wc_get_page_id( 'shop' ) ) + [ 'menu-item-title' => 'همه لباس‌ها' ] );
$parent = $add( [ 'menu-item-type' => 'custom', 'menu-item-title' => 'دسته‌بندی‌ها', 'menu-item-url' => get_permalink( $cats_page_id ), 'menu-item-classes' => 'b-menu-cats' ] );
foreach ( get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_product_cat' ) ], 'orderby' => 'meta_value_num', 'meta_key' => 'order' ] ) as $t ) {
	$add( [ 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $t->term_id, 'menu-item-type' => 'taxonomy', 'menu-item-parent-id' => $parent ] );
}
$add( [ 'menu-item-type' => 'custom', 'menu-item-title' => 'حراج', 'menu-item-url' => home_url( '/#b-sale' ) ] );
$add( $page_item( $about_id ) );
$add( $page_item( $contact_id ) );
$footer = boutique_menu( 'منوی فوتر', [
	$page_item( $about_id ),
	$page_item( $contact_id ),
	$page_item( $terms_id ),
	$page_item( $faq_id ),
	$page_item( $track_id ),
	$page_item( wc_get_page_id( 'myaccount' ) ),
] );

$locations = get_theme_mod( 'nav_menu_locations', [] );
$locations['menu_1']      = $main;
$locations['menu_mobile'] = $main;
$locations['footer']      = $footer;
set_theme_mod( 'nav_menu_locations', $locations );

WP_CLI::success( 'Pages and menus ready.' );
