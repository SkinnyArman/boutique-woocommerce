<?php
/**
 * Customer emails: one clear email per step, only when the customer gave an email address.
 *
 *   on-hold (card-to-card) → «ثبت شد؛ منتظر رسید واریز»  (+ card number, receipt upload link: receipts.php)
 *   processing             → «پرداخت تأیید شد» + what happens next (Snapp / Post / pickup)
 *   «آماده شد» (pickup)     → «آماده تحویل است» (sent via the customer-note email, see today.php)
 *   completed              → «ارسال شد» (+ tracking code) or «تحویل داده شد» (pickup)
 *   cancelled              → three versions: receipt never came / after payment / other
 *
 * Edge cases:
 *   - No email address (it's optional at checkout) → nothing is sent.
 *   - In-store sales never email (the customer is standing at the till).
 *   - Our own order notes don't trigger WooCommerce's extra "note added" email (no doubles);
 *     only the pickup-ready step uses it, on purpose.
 *   - Every email links to «پیگیری سفارش» and says how to reach the shop.
 */
defined( 'ABSPATH' ) || exit;

function boutique_order_kind( WC_Order $o ) {
	if ( function_exists( 'boutique_order_is_pickup' ) && boutique_order_is_pickup( $o ) ) { return 'pickup'; }
	if ( function_exists( 'boutique_order_is_courier' ) && boutique_order_is_courier( $o ) ) { return 'courier'; }
	return 'post';
}

/* ---------- Who gets emails ---------- */
// The customer cancellation email exists in WooCommerce but is off by default.
add_filter( 'woocommerce_email_enabled_customer_cancelled_order', '__return_true' );

// Never email in-store sales; only valid addresses.
foreach ( [ 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_cancelled_order', 'customer_refunded_order', 'customer_note' ] as $id ) {
	add_filter( "woocommerce_email_recipient_{$id}", function ( $to, $o ) {
		if ( ! $o instanceof WC_Order || 'instore' === $o->get_created_via() ) { return ''; }
		return is_email( $to ) ? $to : '';
	}, 20, 2 );
}

// The "note added" email only for the pickup-ready step (flag set in today.php); our other notes
// duplicate a status email.
add_filter( 'woocommerce_email_enabled_customer_note', fn( $on ) => $on && ! empty( $GLOBALS['boutique_note_email'] ), 20 );

/* ---------- Subjects and headings ---------- */
$boutique_texts = [
	'customer_on_hold_order'    => fn( $o ) => [ 'سفارش #' . $o->get_order_number() . ' ثبت شد؛ منتظر رسید واریز', 'سفارش شما ثبت شد' ],
	'customer_processing_order' => fn( $o ) => [ 'پرداخت سفارش #' . $o->get_order_number() . ' تأیید شد', 'پرداخت شما تأیید شد' ],
	'customer_completed_order'  => fn( $o ) => 'pickup' === boutique_order_kind( $o )
		? [ 'سفارش #' . $o->get_order_number() . ' تحویل داده شد', 'ممنون از خریدتان' ]
		: [ 'سفارش #' . $o->get_order_number() . ' ارسال شد', 'سفارش شما در راه است' ],
	'customer_cancelled_order'  => fn( $o ) => [ 'سفارش #' . $o->get_order_number() . ' لغو شد', 'سفارش شما لغو شد' ],
	'customer_note'             => fn( $o ) => [ 'سفارش #' . $o->get_order_number() . ' آماده تحویل است', 'سفارش شما آماده تحویل است' ],
];
foreach ( $boutique_texts as $id => $make ) {
	add_filter( "woocommerce_email_subject_{$id}", fn( $s, $o ) => $o instanceof WC_Order ? '[' . get_bloginfo( 'name' ) . '] ' . $make( $o )[0] : $s, 20, 2 );
	add_filter( "woocommerce_email_heading_{$id}", fn( $h, $o ) => $o instanceof WC_Order ? $make( $o )[1] : $h, 20, 2 );
}
// Plain closing line instead of WooCommerce's generic additional content.
foreach ( array_keys( $boutique_texts ) as $id ) {
	add_filter( "woocommerce_email_additional_content_{$id}", fn() => 'customer_cancelled_order' === $id
		? 'هر سؤالی داشتید در خدمتیم.'
		: 'ممنون که از ' . get_bloginfo( 'name' ) . ' خرید کردید.', 20 );
}

/* ---------- The message itself (above the order table) ---------- */
add_action( 'woocommerce_email_before_order_table', function ( $o, $sent_to_admin, $plain, $email = null ) {
	if ( $sent_to_admin || ! $o instanceof WC_Order || ! $email ) { return; }
	$kind  = boutique_order_kind( $o );
	$phone = function_exists( 'boutique_shop_phone' ) ? boutique_fa_digits( boutique_shop_phone() ) : '';
	$lines = [];
	switch ( $email->id ) {
		case 'customer_processing_order':
			$lines[] = [ 'pickup' => 'سفارش‌تان را آماده می‌کنیم و وقتی آماده شد با ایمیل و در «پیگیری سفارش» خبرتان می‌کنیم. لطفاً قبل از آمدن با ' . $phone . ' تماس بگیرید.',
				'courier' => 'سفارش‌تان به‌زودی با اسنپ پیک فرستاده می‌شود؛ معمولاً همان روز.',
				'post'    => 'سفارش‌تان حداکثر ظرف ۲ روز کاری با پست فرستاده می‌شود و کد رهگیری را برایتان می‌فرستیم.' ][ $kind ];
			break;
		case 'customer_completed_order':
			if ( 'pickup' === $kind ) {
				$lines[] = 'سفارش‌تان در فروشگاه تحویل داده شد. امیدواریم از آن لذت ببرید.';
			} else {
				$lines[] = 'courier' === $kind ? 'سفارش‌تان به اسنپ پیک تحویل داده شد و در راه است.' : 'سفارش‌تان به پست تحویل داده شد و در راه است.';
				if ( $code = $o->get_meta( '_boutique_tracking' ) ) { $lines[] = 'کد رهگیری مرسوله: <strong dir="ltr">' . esc_html( $code ) . '</strong>'; }
			}
			break;
		case 'customer_cancelled_order':
			if ( $o->get_meta( '_boutique_unpaid_cancel' ) ) {
				$lines[] = 'رسید واریز این سفارش تا ۲۴ ساعت به دستمان نرسید، برای همین سفارش لغو شد و لباس‌ها دوباره در فروشگاه موجود شدند.';
				$lines[] = 'اگر مبلغ را واریز کرده‌اید، لطفاً با ' . $phone . ' تماس بگیرید تا سفارش را دوباره ثبت کنیم.';
			} elseif ( $o->get_date_paid() ) {
				$lines[] = 'این سفارش لغو شد. مبلغی را که پرداخت کرده‌اید به شما برمی‌گردانیم؛ برای هماهنگی با شما تماس می‌گیریم.';
			} else {
				$lines[] = 'این سفارش لغو شد. اگر سؤالی دارید با ' . $phone . ' تماس بگیرید.';
			}
			break;
		case 'customer_note':
			$lines[] = 'آدرس: ' . esc_html( function_exists( 'boutique_shop_address' ) ? boutique_shop_address() : '' );
			$lines[] = 'ساعات کاری: ' . esc_html( function_exists( 'boutique_shop_hours' ) ? boutique_shop_hours() : '' );
			$lines[] = 'لطفاً قبل از آمدن با ' . $phone . ' تماس بگیرید.';
			break;
	}
	if ( ! $lines ) { return; }
	// Every customer email except the cancellation: a button to the order's tracking page.
	$track = ( function_exists( 'boutique_track_url' ) && 'customer_cancelled_order' !== $email->id ) ? boutique_track_url( $o->get_id() ) : '';
	if ( $plain ) {
		echo wp_strip_all_tags( implode( "\n", $lines ) ) . ( $track ? "\nپیگیری سفارش: {$track}" : '' ) . "\n\n";
	} else {
		echo '<div class="b-mail-box">' . implode( '<br>', $lines )
			. ( $track ? '<br><a class="b-mail-btn" href="' . esc_url( $track ) . '">پیگیری سفارش</a>' : '' ) . '</div>';
	}
}, 20, 4 );

/* ---------- Samples (admin button): every customer email for a pretend order, never saved ---------- */
function boutique_email_sample_order( $kind ) {
	$o = new WC_Order();
	$o->set_id( 1602 );
	$o->set_billing_first_name( 'سارا' );
	$o->set_billing_last_name( 'محمدی' );
	$o->set_billing_phone( '09123456789' );
	$o->set_billing_country( 'IR' );
	$o->set_billing_state( 'THR' );
	$o->set_billing_city( 'تهران' );
	$o->set_billing_address_1( 'اقدسیه، خیابان نمونه، پلاک ۱۲' );
	$o->set_payment_method( 'bacs' );
	$o->set_payment_method_title( 'کارت به کارت' );
	$o->set_created_via( 'checkout' );
	$o->set_date_created( time() );
	$item = new WC_Order_Item_Product();
	$item->set_name( 'پلیور بافت Esmara - 42' );
	$item->set_quantity( 1 );
	$item->set_subtotal( 3280000 );
	$item->set_total( 3280000 );
	$o->add_item( $item );
	$ship = new WC_Order_Item_Shipping();
	if ( 'pickup' === $kind ) {
		$ship->set_props( [ 'method_title' => 'تحویل حضوری از فروشگاه', 'method_id' => 'local_pickup', 'total' => 0 ] );
	} elseif ( 'courier' === $kind ) {
		$ship->set_props( [ 'method_title' => 'اسنپ پیک (پس‌کرایه)', 'method_id' => 'flat_rate', 'instance_id' => function_exists( 'boutique_courier_instance' ) ? boutique_courier_instance() : 0, 'total' => 0 ] );
	} else {
		$ship->set_props( [ 'method_title' => 'پست پیشتاز', 'method_id' => 'flat_rate', 'total' => 60000 ] );
	}
	$o->add_item( $ship );
	$o->set_shipping_total( (float) $ship->get_total() );
	$o->set_total( 3280000 + (float) $ship->get_total() );
	return $o;
}

function boutique_send_email_samples( $to ) {
	$mailer = WC()->mailer();
	$mails  = $mailer->get_emails();
	$sent   = 0;
	$cases  = [
		[ 'WC_Email_Customer_On_Hold_Order', 'post', [ 'status' => 'on-hold' ] ],
		[ 'WC_Email_Customer_Processing_Order', 'courier', [ 'status' => 'processing' ] ],
		[ 'WC_Email_Customer_Note', 'pickup', [ 'status' => 'processing', 'note' => 'سفارش شما آماده تحویل در فروشگاه است.' ] ],
		[ 'WC_Email_Customer_Completed_Order', 'post', [ 'status' => 'completed', 'tracking' => '0000123456789' ] ],
		[ 'WC_Email_Customer_Completed_Order', 'pickup', [ 'status' => 'completed' ] ],
		[ 'WC_Email_Customer_Cancelled_Order', 'post', [ 'status' => 'cancelled', 'unpaid' => 1 ] ],
	];
	foreach ( $cases as [ $class, $kind, $opt ] ) {
		$e = $mails[ $class ] ?? null;
		if ( ! $e ) { continue; }
		$o = boutique_email_sample_order( $kind );
		$o->set_status( $opt['status'] );
		if ( ! empty( $opt['tracking'] ) ) { $o->update_meta_data( '_boutique_tracking', $opt['tracking'] ); }
		if ( ! empty( $opt['unpaid'] ) ) { $o->update_meta_data( '_boutique_unpaid_cancel', 1 ); }
		$e->object                         = $o;
		$e->recipient                      = $to;
		$e->customer_note                  = $opt['note'] ?? '';
		$e->placeholders['{order_number}'] = $o->get_order_number();
		$e->placeholders['{order_date}']   = wc_format_datetime( $o->get_date_created() );
		$subject = '(نمونه) ' . $e->get_subject();
		if ( $e->send( $to, $subject, $e->get_content(), $e->get_headers(), [] ) ) { $sent++; }
	}
	return $sent;
}

add_action( 'admin_post_boutique_email_samples', function () {
	check_admin_referer( 'boutique_email_samples' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'دسترسی ندارید.' ); }
	$to  = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
	$n   = is_email( $to ) ? boutique_send_email_samples( $to ) : 0;
	$msg = $n ? "ok:{$n} ایمیل نمونه به {$to} فرستاده شد." : 'err:ایمیل فرستاده نشد. آدرس را بررسی کنید.';
	wp_safe_redirect( add_query_arg( [ 'page' => 'boutique-bale', 'msg' => rawurlencode( $msg ) ], admin_url( 'admin.php' ) ) );
	exit;
} );

add_action( 'boutique_notify_page_end', function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }   // previews: for the site admin, not the shop manager
	?>
	<section class="b-panel">
		<h2>ایمیل به مشتری</h2>
		<p class="b-hint">اگر مشتری موقع خرید ایمیل وارد کند، در هر مرحله (ثبت، تأیید پرداخت، آماده تحویل، ارسال، لغو) خودکار ایمیل می‌گیرد. برای دیدن نمونه‌ها:</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b-bale__token">
			<?php wp_nonce_field( 'boutique_email_samples' ); ?>
			<input type="hidden" name="action" value="boutique_email_samples">
			<label class="b-field"><span class="screen-reader-text">ایمیل</span><input type="email" name="to" dir="ltr" required placeholder="you@example.com"></label>
			<button type="submit" class="b-btn b-btn--ghost">ارسال نمونه ایمیل‌ها</button>
		</form>
	</section>
	<?php
} );


/* ================================================================ Design
 * The shop's look in every WooCommerce email: rose header bar, cream page, ink text, Tahoma
 * (email apps block web fonts; Tahoma draws Persian well everywhere), right-to-left throughout
 * (the header alignment used to come from a saved "left" setting). */
$boutique_mail_opts = [
	'woocommerce_email_base_color'            => '#a85562',
	'woocommerce_email_background_color'      => '#f3e7e4',
	'woocommerce_email_body_background_color' => '#fffaf7',
	'woocommerce_email_text_color'            => '#3b2a2d',
	'woocommerce_email_header_text_color'     => '#ffffff',
	'woocommerce_email_footer_text_color'     => '#8a7577',
	'woocommerce_email_header_alignment'      => 'right',
	'woocommerce_email_font_family'           => 'Tahoma',
];
foreach ( $boutique_mail_opts as $opt => $val ) {
	add_filter( "pre_option_{$opt}", fn() => $val );
}
add_filter( 'pre_option_woocommerce_email_header_image', fn() => get_stylesheet_directory_uri() . '/brand/site-icon.png' );
add_filter( 'pre_option_woocommerce_email_footer_text', fn() => esc_html( boutique_shop( 'name' ) . ' · ' . boutique_shop( 'address' ) ) . ' · <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a>' );

add_filter( 'woocommerce_email_styles', function ( $css ) {
	return $css . '
	#wrapper, #template_container, #template_header, #template_body, #body_content, #body_content_inner, #template_footer,
	#body_content table, #body_content td, #body_content th, #body_content p, h1, h2, h3 { direction: rtl !important; text-align: right !important; }
	#wrapper, #wrapper * { font-family: Tahoma, "Vazirmatn", Arial, sans-serif !important; }
	#template_container { border: 0 !important; border-radius: 10px !important; box-shadow: 0 6px 24px rgba(59, 42, 45, .08) !important; overflow: hidden; }
	#template_header { border-radius: 0 !important; }
	#template_header h1 { font-size: 22px !important; font-weight: bold !important; line-height: 1.6 !important; letter-spacing: 0 !important; }
	#template_header_image { text-align: right !important; padding: 24px 32px 8px !important; }
	#template_header_image p, #template_header_image a { margin: 0 !important; }
	#template_header_image img { width: 56px !important; height: 56px !important; border-radius: 12px !important; }
	#body_content_inner { font-size: 14px !important; line-height: 1.9 !important; color: #3b2a2d !important; }
	#body_content h2 { color: #3b2a2d !important; font-size: 16px !important; margin: 24px 0 10px !important; }
	#body_content .td, #body_content table.td { border-color: #ebd9d6 !important; }
	#body_content a { color: #a85562 !important; }
	#template_footer #credit, #template_footer #credit p { text-align: center !important; font-size: 12px !important; line-height: 1.9 !important; }
	.b-mail-box { margin: 0 0 22px; padding: 16px 18px; background: #f8efec; border-radius: 8px; line-height: 2; }
	#body_content a.b-mail-btn, a.b-mail-btn { color: #ffffff !important; }
	.b-mail-btn { display: inline-block; margin-top: 6px; padding: 10px 22px; background: #a85562; color: #ffffff !important; border-radius: 6px; text-decoration: none !important; font-weight: bold; }
	.b-mail-card { margin: 8px 0; padding: 12px 14px; background: #ffffff; border: 1px solid #ebd9d6; border-radius: 6px; }
	.b-mail-card b { display: block; font-size: 19px; letter-spacing: 1px; direction: ltr; text-align: right; color: #3b2a2d; }
	.b-mail-muted { color: #8a7577; font-size: 12px; }';
} );
