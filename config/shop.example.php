<?php
/**
 * Your shop's details: the ONE file to fill in after forking.
 *
 *   cp config/shop.example.php config/shop.php     # then edit config/shop.php
 *
 * config/shop.php is gitignored, so your phone, card number and address never reach GitHub.
 * Anything you leave out falls back to the value in this file. Most changes show up on the
 * next page load; the seeded pages (about, contact, FAQ intro, terms) pick them up when you
 * re-run setup (or `wp eval-file /scripts/pages.php`).
 *
 * Production: upload this folder as wp-content/shop-config/ (so the file ends up at
 * wp-content/shop-config/shop.php).
 */
return [

	/* ------------------------------------------------------------ Identity */
	'name'        => 'فروشگاه نمونه',            // site title, emails, page titles, app icon name
	'name_latin'  => 'Sample Boutique',          // for search engines (alternate name)
	'short_name'  => 'نمونه',                    // the big word in the home headline; admin icon = «مدیریت <short_name>»
	'description' => 'لباس زنانه، مجلسی و اسپرت', // WordPress tagline
	'email'       => 'info@example.com',         // shown on contact page, used as admin + email sender address

	/* ------------------------------------------------------------ Contact */
	'phone'            => '09120000000',         // mobile, as people dial it
	'whatsapp'         => '',                    // wa.me number without + (e.g. 989120000000); '' = made from phone; false = no WhatsApp
	'telegram_order'   => 'sample_shop',         // Telegram account customers message to order / ask a price
	'telegram_channel' => 'sample_shop_channel', // public channel (also what scripts/import/telegram_fetch.py reads)
	'instagram'        => 'sample_shop',
	'bale'             => '',                    // Bale username (ble.ir/<this>); '' hides the button

	/* ------------------------------------------------------------ The physical shop */
	'address'    => 'تهران، خیابان نمونه، پلاک ۱',  // full address, as customers read it
	'street'     => 'خیابان نمونه، پلاک ۱',        // the address without the city (search engines)
	'city'       => 'تهران',
	'state_code' => 'THR',                        // WooCommerce province code (Persian WooCommerce: THR, ESF, KHR, FAR, …)
	'postcode'   => '1234567890',
	'hours'      => 'شنبه تا پنجشنبه، ساعت ۱۰ تا ۲۰',
	'open_days'  => [ 'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday' ],
	'open_hours' => [ '10:00', '20:00' ],
	'map_label'  => 'فروشگاه نمونه',               // caption under the map
	'map_query'  => 'میدان آزادی، تهران',           // what Google Maps should search for
	'map_url'    => '',                           // Neshan link (nshn.ir/…); '' hides «مسیریابی با نشان»

	/* ------------------------------------------------------------ Card-to-card payment */
	'card_number' => '0000-0000-0000-0000',       // shown after an order is placed
	'card_holder' => 'نام صاحب کارت',

	/* ------------------------------------------------------------ Delivery */
	'courier_city' => '',                         // city where the courier (پیک) is offered; '' = same as city

	/* ------------------------------------------------------------ Order alerts (Bale / Telegram bot) */
	'notify_phones' => [],                        // phones allowed to connect to the bot; [] = phone above

	/* ------------------------------------------------------------ Words on the site */
	'hero_title'  => 'لباس مجلسی و اسپرت',          // home headline, after «<short_name>؛»
	'hero_lede'   => 'انتخاب‌شده با وسواس؛ ارسال به سراسر ایران و پرو حضوری در فروشگاه.',
	'visit_title' => 'از نزدیک ببینید،<br>با خیال راحت پرو کنید.',
	'seo_tagline' => 'لباس زنانه، مجلسی و اسپرت در تهران',   // home page <title>: «<name> | <seo_tagline>»
	'seo_home'    => 'فروشگاه نمونه: لباس مجلسی و اسپرت زنانه. خرید آنلاین با ارسال به سراسر ایران و پرو حضوری.',
	'seo_shop'    => 'همه لباس‌های فروشگاه نمونه. ارسال به سراسر ایران.',
	'about'       => [                            // «درباره ما» page, one paragraph per item
		'سال‌هاست که به‌صورت حضوری در خدمت مشتریان هستیم و حالا فروشگاه اینترنتی‌مان را راه‌اندازی کرده‌ایم.',
	],
	'faq_brands'  => 'بله. لباس‌های اسپرت ما اورجینال‌اند و برند هر لباس در صفحه‌اش نوشته شده است.',

	/* ------------------------------------------------------------ Look */
	'colors' => [
		'theme'       => '#a85562',  // phone status bar / app icon color for customers
		'background'  => '#fffaf7',
		'admin_theme' => '#3b2a2d',  // the shop manager's app icon
	],
	// Logos and icons are image files, not settings: replace the PNGs in theme/blocksy-child/brand/,
	// theme/blocksy-child/app/ and plugins/boutique-admin/assets/app/ with your own (same names and sizes).

	/* ------------------------------------------------------------ Search engines */
	'google_verify' => '',  // Search Console "HTML tag" code
];
