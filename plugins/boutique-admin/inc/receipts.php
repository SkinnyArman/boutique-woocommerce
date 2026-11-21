<?php
/**
 * Card-to-card receipts.
 *
 * After the order is placed (thank-you page, and «پیگیری سفارش» while unpaid) the customer sees
 * the amount and card number, then picks one of two ways to send the receipt:
 *   1. «رسید را همین‌جا آپلود می‌کنم»: a photo, shown to the owner on «امروز» next to «واریز رسید، تأیید».
 *   2. «در تلگرام / واتساپ / بله می‌فرستم»: buttons that open a chat with the order number filled in.
 *
 * Upload happens after the order exists (not inside checkout) so nobody pays for a size that
 * sold out while they were typing.
 *
 * Safety: the order key proves it's their order; images only (checked by decoding them),
 * max 10 MB, re-saved as a ~1600px JPEG (drops hidden data, saves disk); at most 5 uploads per
 * order. Files live in uploads/boutique-receipts/ with random names, blocked from the web;
 * only shop staff can open them, through admin-post.php.
 */
defined( 'ABSPATH' ) || exit;

const BOUTIQUE_RECEIPT_MAX_BYTES   = 10 * 1024 * 1024;
const BOUTIQUE_RECEIPT_MAX_UPLOADS = 5;
/** Card-to-card orders with no confirmed payment are cancelled after this many hours, so the
 *  sizes they hold go back on sale. An uploaded receipt stops the clock (the owner decides). */
const BOUTIQUE_UNPAID_HOURS = 24;

function boutique_receipt_dir() {
	$dir = wp_upload_dir()['basedir'] . '/boutique-receipts';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( "$dir/.htaccess" ) ) {
		file_put_contents( "$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		file_put_contents( "$dir/index.html", '' );
	}
	return $dir;
}

function boutique_receipt_file( WC_Order $o ) {
	$name = basename( (string) $o->get_meta( '_boutique_receipt' ) );
	$path = $name ? boutique_receipt_dir() . '/' . $name : '';
	return ( $path && is_file( $path ) ) ? $path : '';
}

function boutique_receipt_view_url( WC_Order $o ) {
	return admin_url( 'admin-post.php?action=boutique_receipt_view&order=' . $o->get_id() );
}

function boutique_receipt_needed( WC_Order $o ) {
	return 'bacs' === $o->get_payment_method() && $o->has_status( [ 'on-hold', 'pending' ] );
}

function boutique_card_number() { return defined( 'BOUTIQUE_CARD_NUMBER' ) ? BOUTIQUE_CARD_NUMBER : ''; }
function boutique_card_holder() { return defined( 'BOUTIQUE_CARD_HOLDER' ) ? BOUTIQUE_CARD_HOLDER : ''; }

/** Chat links with the order already typed in. */
function boutique_receipt_chats( WC_Order $o ) {
	// Plain text (wc_price would leak "&nbsp;" into the chat); Persian digits like the rest of the site.
	$text = rawurlencode( "سلام، رسید واریز سفارش شماره " . boutique_fa_digits( $o->get_order_number() ) . ' به مبلغ ' . boutique_money( $o->get_total() ) . ' را می‌فرستم.' );
	$out  = [];
	if ( defined( 'BOUTIQUE_ORDER_TG' ) && BOUTIQUE_ORDER_TG ) { $out['تلگرام'] = 'https://t.me/' . BOUTIQUE_ORDER_TG . '?text=' . $text; }
	if ( defined( 'BOUTIQUE_WHATSAPP' ) && BOUTIQUE_WHATSAPP ) { $out['واتساپ'] = 'https://wa.me/' . BOUTIQUE_WHATSAPP . '?text=' . $text; }
	if ( defined( 'BOUTIQUE_BALE' ) && BOUTIQUE_BALE ) { $out['بله'] = 'https://ble.ir/' . BOUTIQUE_BALE; }
	return $out;
}

/** The customer-facing box. $back = where to return after uploading. */
function boutique_receipt_box( WC_Order $o, $back ) {
	if ( ! boutique_receipt_needed( $o ) ) { return; }
	$flash = sanitize_key( $_GET['receipt'] ?? '' );
	$has   = (bool) boutique_receipt_file( $o );
	$errors = [
		'type'  => 'فقط عکس (JPG یا PNG) قابل قبول است.',
		'size'  => 'حجم عکس بیشتر از ۱۰ مگابایت است.',
		'many'  => 'تعداد آپلودها زیاد بود؛ رسید را در پیام‌رسان بفرستید.',
		'fail'  => 'آپلود انجام نشد؛ دوباره امتحان کنید یا رسید را در پیام‌رسان بفرستید.',
	];
	$card = boutique_card_number();
	?>
	<section class="b-receipt" id="b-receipt" aria-labelledby="b-receipt-title">
		<h2 id="b-receipt-title">پرداخت کارت به کارت</h2>
		<div class="b-receipt__pay">
			<div><span>مبلغ</span><strong><?php echo wp_kses_post( wc_price( $o->get_total() ) ); ?></strong></div>
			<div><span>شماره کارت</span><strong><bdi dir="ltr" class="b-receipt__card"><?php echo esc_html( boutique_fa_digits( $card ) ); ?></bdi></strong>
				<button type="button" class="b-receipt__copy" data-copy="<?php echo esc_attr( preg_replace( '/\D/', '', $card ) ); ?>">کپی</button></div>
			<div><span>به نام</span><strong><?php echo esc_html( boutique_card_holder() ); ?></strong></div>
		</div>

		<?php if ( 'ok' === $flash || $has ) : ?>
			<p class="b-receipt__done" role="status"><strong>رسید شما رسید ✓</strong> بعد از بررسی واریز، سفارش تأیید می‌شود و در «پیگیری سفارش» می‌بینید.</p>
		<?php endif; ?>
		<?php if ( isset( $errors[ $flash ] ) ) : ?>
			<p class="b-receipt__error" role="alert"><?php echo esc_html( $errors[ $flash ] ); ?></p>
		<?php endif; ?>

		<?php if ( ! $has && 'ok' !== $flash ) : ?>
			<p class="b-receipt__deadline">لباس‌های این سفارش تا <strong><?php echo esc_html( boutique_fa_digits( wp_date( 'l، ساعت H:i', boutique_unpaid_deadline( $o ) ) ) ); ?></strong> برای شما نگه داشته می‌شود. اگر تا آن موقع رسید نرسد، سفارش خودکار لغو می‌شود.</p>
		<?php endif; ?>
		<p class="b-receipt__lead"><?php echo $has ? 'اگر رسید دیگری دارید:' : 'بعد از واریز، رسید را چطور می‌فرستید؟'; ?></p>
		<div class="b-receipt__ways">
			<input type="radio" name="b-receipt-way" id="b-way-up" checked>
			<label for="b-way-up">رسید را همین‌جا آپلود می‌کنم</label>
			<input type="radio" name="b-receipt-way" id="b-way-chat">
			<label for="b-way-chat">در تلگرام / واتساپ / بله می‌فرستم</label>

			<form class="b-receipt__panel b-receipt__panel--up" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="boutique_receipt">
				<input type="hidden" name="order" value="<?php echo (int) $o->get_id(); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $o->get_order_key() ); ?>">
				<input type="hidden" name="back" value="<?php echo esc_url( $back ); ?>">
				<label class="b-receipt__file"><span>عکس یا اسکرین‌شات رسید</span>
					<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required></label>
				<button type="submit" class="button">ارسال رسید</button>
			</form>
			<div class="b-receipt__panel b-receipt__panel--chat">
				<p>شماره سفارش <bdi>#<?php echo esc_html( $o->get_order_number() ); ?></bdi> را با رسید بفرستید (خودکار نوشته می‌شود):</p>
				<div class="b-receipt__chats">
					<?php foreach ( boutique_receipt_chats( $o ) as $label => $url ) : ?>
						<a class="button" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</section>
	<script>
	document.querySelectorAll('.b-receipt__copy').forEach(function (b) {
		b.addEventListener('click', function () {
			var t = b.getAttribute('data-copy'), ok = function () { b.textContent = 'کپی شد ✓'; setTimeout(function () { b.textContent = 'کپی'; }, 1800); };
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(ok); return; }
			var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); ok();
		});
	});
	</script>
	<?php
}

/* -------------------------------------------- Where the box appears */
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$o = $order_id ? wc_get_order( $order_id ) : null;
	if ( $o ) { boutique_receipt_box( $o, $o->get_checkout_order_received_url() ); }
}, 1 );

// WooCommerce's own bank-transfer text would repeat the card; ours replaces it.
add_action( 'init', function () {
	if ( class_exists( 'WC_Payment_Gateways' ) && function_exists( 'WC' ) ) {
		$g = WC()->payment_gateways() ? ( WC()->payment_gateways()->payment_gateways()['bacs'] ?? null ) : null;
		if ( $g ) { remove_action( 'woocommerce_thankyou_bacs', [ $g, 'thankyou_page' ] ); }
	}
}, 20 );

// The on-hold email carries the card number and where to send the receipt.
add_action( 'woocommerce_email_before_order_table', function ( $o, $sent_to_admin, $plain ) {
	if ( $sent_to_admin || ! $o instanceof WC_Order || ! boutique_receipt_needed( $o ) ) { return; }
	$url = $o->get_checkout_order_received_url();
	if ( $plain ) {
		echo 'مبلغ ' . boutique_money( $o->get_total() ) . ' را به کارت ' . boutique_card_number() . ' (به نام ' . boutique_card_holder() . ') واریز کنید.' . "\n" . 'رسید را از این صفحه آپلود کنید: ' . $url . "\n\n";
		return;
	}
	echo '<div class="b-mail-box">مبلغ <strong>' . esc_html( boutique_money( $o->get_total() ) ) . '</strong> را به این کارت واریز کنید:'
		. '<div class="b-mail-card"><b>' . esc_html( boutique_fa_digits( boutique_card_number() ) ) . '</b><span class="b-mail-muted">به نام ' . esc_html( boutique_card_holder() ) . '</span></div>'
		. 'بعد از واریز، رسید را بفرستید. اگر تا ۲۴ ساعت رسید نرسد، سفارش خودکار لغو می‌شود.<br>'
		. '<a class="b-mail-btn" href="' . esc_url( $url ) . '">آپلود رسید</a></div>';
}, 5, 3 );

/* -------------------------------------------- Upload */
function boutique_receipt_upload() {
	$id   = absint( $_POST['order'] ?? 0 );
	$o    = $id ? wc_get_order( $id ) : null;
	$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ?? '' ) ), home_url( '/' ) );
	$go   = function ( $code ) use ( $back ) {
		wp_safe_redirect( add_query_arg( 'receipt', $code, $back ) . '#b-receipt' );
		exit;
	};
	if ( ! $o || ! hash_equals( $o->get_order_key(), (string) wp_unslash( $_POST['key'] ?? '' ) ) || ! boutique_receipt_needed( $o ) ) { $go( 'fail' ); }
	if ( (int) $o->get_meta( '_boutique_receipt_count' ) >= BOUTIQUE_RECEIPT_MAX_UPLOADS ) { $go( 'many' ); }

	$f = $_FILES['receipt'] ?? null;
	if ( ! $f || UPLOAD_ERR_OK !== ( $f['error'] ?? 1 ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
		$go( ( $f && in_array( $f['error'] ?? 0, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) ? 'size' : 'fail' );
	}
	if ( $f['size'] > BOUTIQUE_RECEIPT_MAX_BYTES ) { $go( 'size' ); }
	$info = @getimagesize( $f['tmp_name'] );
	if ( ! $info || ! in_array( $info[2], [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ], true ) ) { $go( 'type' ); }

	$dir  = boutique_receipt_dir();
	$name = $o->get_id() . '-' . wp_generate_password( 24, false ) . '.jpg';
	$ed   = wp_get_image_editor( $f['tmp_name'] );
	if ( is_wp_error( $ed ) ) { $go( 'type' ); }
	$ed->resize( 1600, 1600 );
	$ed->set_quality( 80 );
	$saved = $ed->save( "$dir/$name", 'image/jpeg' );
	if ( is_wp_error( $saved ) ) { $go( 'fail' ); }

	if ( $old = boutique_receipt_file( $o ) ) { wp_delete_file( $old ); }
	$o->update_meta_data( '_boutique_receipt', $name );
	$o->update_meta_data( '_boutique_receipt_at', time() );
	$o->update_meta_data( '_boutique_receipt_count', (int) $o->get_meta( '_boutique_receipt_count' ) + 1 );
	$o->add_order_note( 'مشتری رسید واریز را آپلود کرد.' );
	$o->save();
	do_action( 'boutique_receipt_uploaded', $o );
	$go( 'ok' );
}
add_action( 'admin_post_nopriv_boutique_receipt', 'boutique_receipt_upload' );
add_action( 'admin_post_boutique_receipt', 'boutique_receipt_upload' );

/* -------------------------------------------- Owner: view */
add_action( 'admin_post_boutique_receipt_view', function () {
	if ( ! current_user_can( 'edit_shop_orders' ) ) { wp_die( 'دسترسی ندارید.', 403 ); }
	$o    = wc_get_order( absint( $_GET['order'] ?? 0 ) );
	$path = $o ? boutique_receipt_file( $o ) : '';
	if ( ! $path ) { wp_die( 'رسیدی نیست.', 404 ); }
	nocache_headers();
	header( 'Content-Type: image/jpeg' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path );
	exit;
} );

/** Thumbnail + link for the owner (Today screen, order page). */
function boutique_receipt_admin( WC_Order $o ) {
	if ( 'bacs' !== $o->get_payment_method() ) { return; }
	if ( boutique_receipt_file( $o ) ) {
		$url = esc_url( boutique_receipt_view_url( $o ) );
		echo '<a class="b-receipt-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="رسید واریز سفارش ' . esc_attr( $o->get_order_number() ) . '" loading="lazy"><span>رسید آپلود شده — بزرگ کنید</span></a>';
	} else {
	}
}

add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $o ) {
	if ( $o instanceof WC_Order && 'bacs' === $o->get_payment_method() ) {
		echo '<p><strong>رسید کارت به کارت:</strong></p>';
		boutique_receipt_admin( $o );
	}
} );


/* -------------------------------------------- Receipt never arrives */
function boutique_unpaid_deadline( WC_Order $o ) {
	return $o->get_date_created()->getTimestamp() + BOUTIQUE_UNPAID_HOURS * HOUR_IN_SECONDS;
}

/**
 * Hourly: cancel card-to-card orders still waiting after BOUTIQUE_UNPAID_HOURS with no uploaded
 * receipt. Cancelling puts the stock back (WooCommerce does that on cancel); the customer sees
 * «لغو شده» with the reason in order tracking.
 * Orders whose receipt came by Telegram/WhatsApp: the owner confirms them on «امروز» before the
 * deadline (the panel shows how long each one has left).
 */
function boutique_cancel_unpaid() {
	$cutoff = time() - BOUTIQUE_UNPAID_HOURS * HOUR_IN_SECONDS;
	$orders = wc_get_orders( [ 'status' => 'on-hold', 'payment_method' => 'bacs', 'date_created' => '<' . $cutoff, 'limit' => 50 ] );
	foreach ( $orders as $o ) {
		if ( boutique_receipt_file( $o ) || 'instore' === $o->get_created_via() ) { continue; }
		$o->update_meta_data( '_boutique_unpaid_cancel', 1 );
		$o->add_order_note( 'رسید واریز تا ' . BOUTIQUE_UNPAID_HOURS . ' ساعت نرسید؛ سفارش لغو شد و لباس‌ها دوباره موجود شدند. اگر واریز کرده‌اید با ما تماس بگیرید.', 1 );
		$o->update_status( 'cancelled', 'لغو خودکار: رسید نرسید.' );
	}
	return count( $orders );
}
add_action( 'boutique_cancel_unpaid', 'boutique_cancel_unpaid' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'boutique_cancel_unpaid' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'boutique_cancel_unpaid' );
	}
} );

/** Owner: how long an order has before it's cancelled (Today screen). */
function boutique_unpaid_chip( WC_Order $o ) {
	if ( 'bacs' !== $o->get_payment_method() || boutique_receipt_file( $o ) ) { return; }
	$left = boutique_unpaid_deadline( $o ) - time();
	$h    = max( 0, (int) ceil( $left / HOUR_IN_SECONDS ) );
	echo '<span class="b-chip ' . ( $h <= 6 ? 'b-chip--late' : 'b-chip--warn' ) . '">'
		. ( $h > 0 ? 'رسید هنوز نیامده؛ ' . esc_html( boutique_fa_digits( $h ) ) . ' ساعت دیگر خودکار لغو می‌شود' : 'در حال لغو خودکار' ) . '</span>';
}
