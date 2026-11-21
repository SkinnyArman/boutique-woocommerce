<?php
/**
 * «پیگیری سفارش»: customers check their order with order number + the mobile used at checkout.
 *
 * Customer-facing steps:
 *   در انتظار تأیید پرداخت (on-hold) → ثبت شده، در حال آماده‌سازی (processing) → تحویل پیک شد (completed)
 *   plus لغو شده (cancelled/failed/refunded). No returns: the shop doesn't take them.
 * Pickup orders: ثبت شده → آماده تحویل در فروشگاه (owner taps «آماده شد») → تحویل داده شد; in-store sales are complete at the till.
 *
 * Privacy: never shows the address or full name. Wrong guesses are rate-limited per visitor.
 */
defined( 'ABSPATH' ) || exit;

const BOUTIQUE_TRACK_MAX_TRIES = 8;   // per visitor per 15 minutes

/** Customer-facing label + step index (0..2) or -1 for a stopped order. */
function boutique_track_state( WC_Order $o ) {
	$pickup = $o->has_shipping_method( 'local_pickup' );
	switch ( $o->get_status() ) {
		case 'pending':
		case 'on-hold':
			return [ 0, 'در انتظار تأیید پرداخت', 'پرداخت شما بررسی می‌شود. بعد از تأیید، سفارش آماده می‌شود.' ];
		case 'processing':
			if ( $pickup && function_exists( 'boutique_pickup_ready_at' ) && boutique_pickup_ready_at( $o ) ) {
				return [ 2, 'آماده تحویل در فروشگاه', 'آدرس: ' . boutique_shop_address() . ' — ساعات کاری: ' . boutique_shop_hours()
					. '. قبل از آمدن با ' . boutique_fa_digits( boutique_shop_phone() ) . ' تماس بگیرید.' ];
			}
			return [ 1, 'ثبت شده، در حال آماده‌سازی', $pickup ? 'وقتی آماده شد همین‌جا می‌بینید. قبل از آمدن با ' . ( function_exists( 'boutique_shop_phone' ) ? boutique_fa_digits( boutique_shop_phone() ) : 'ما' ) . ' تماس بگیرید.' : 'سفارش شما در حال بسته‌بندی است.' ];
		case 'completed':
			if ( 'instore' === $o->get_created_via() ) {
				return [ 2, 'خرید حضوری انجام شد', '' ];
			}
			return $pickup
				? [ 3, 'تحویل داده شد', 'سفارش در فروشگاه به شما تحویل داده شد.' ]
				: [ 2, 'تحویل پیک شد', 'سفارش به پیک/پست تحویل داده شد و در راه است.' ];
		case 'refunded':
			return [ -1, 'لغو شده', 'این سفارش لغو و مبلغ آن به شما برگشت داده شده است.' ];
		default: // cancelled, failed
			if ( $o->get_meta( '_boutique_unpaid_cancel' ) ) {
				return [ -1, 'لغو شده', 'رسید واریز تا ' . boutique_fa_digits( 24 ) . ' ساعت نرسید و سفارش لغو شد. اگر واریز کرده‌اید، با ما تماس بگیرید تا سفارش را دوباره ثبت کنیم.' ];
			}
			return [ -1, 'لغو شده', 'این سفارش لغو شده است. اگر سؤالی دارید با ما تماس بگیرید.' ];
	}
}

function boutique_track_url( $order_id = 0 ) {
	$page = get_page_by_path( 'track' );
	$url  = $page ? get_permalink( $page ) : home_url( '/track/' );
	return $order_id ? add_query_arg( 'order', (int) $order_id, $url ) : $url;
}

/** Last 10 digits of an Iranian mobile, Persian or Latin digits, with or without +98/0. */
function boutique_phone_key( $phone ) {
	$d = preg_replace( '/\D/', '', boutique_latin_digits( (string) $phone ) );
	return strlen( $d ) >= 10 ? substr( $d, -10 ) : '';
}

function boutique_track_visitor_key() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	return 'b_track_' . md5( $ip . wp_salt( 'nonce' ) );
}

/** Look up an order. Returns [WC_Order|null, error message|''] */
function boutique_track_lookup( $number, $phone ) {
	$key   = boutique_track_visitor_key();
	$tries = (int) get_transient( $key );
	if ( $tries >= BOUTIQUE_TRACK_MAX_TRIES ) {
		return [ null, 'تعداد تلاش‌ها زیاد بود. چند دقیقه دیگر دوباره امتحان کنید.' ];
	}
	$id    = absint( boutique_latin_digits( $number ) );
	$order = $id ? wc_get_order( $id ) : null;
	$ok    = $order instanceof WC_Order
		&& 'shop_order' === $order->get_type()
		&& boutique_phone_key( $phone ) !== ''
		&& boutique_phone_key( $order->get_billing_phone() ) === boutique_phone_key( $phone );
	if ( ! $ok ) {
		set_transient( $key, $tries + 1, 15 * MINUTE_IN_SECONDS );
		return [ null, 'سفارشی با این شماره و موبایل پیدا نشد. هر دو را دوباره بررسی کنید.' ];
	}
	return [ $order, '' ];
}

/* ------------------------------------------------------------- Page */
add_shortcode( 'boutique_track', function () {
	$number = sanitize_text_field( wp_unslash( $_REQUEST['order'] ?? '' ) );
	$phone  = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
	$order  = null;
	$error  = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['b_track'] ) ) {
		[ $order, $error ] = boutique_track_lookup( $number, $phone );
	}

	ob_start(); ?>
	<div class="b-track">
		<form method="post" class="b-track__form" action="<?php echo esc_url( boutique_track_url() ); ?>">
			<input type="hidden" name="b_track" value="1">
			<label><span>شماره سفارش</span>
				<input type="text" inputmode="numeric" name="order" required value="<?php echo esc_attr( $number ); ?>" placeholder="مثلاً ۱۴۲" autocomplete="off"></label>
			<label><span>موبایلی که با آن سفارش دادید</span>
				<input type="tel" inputmode="tel" name="phone" required value="<?php echo esc_attr( $phone ); ?>" placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" autocomplete="tel"></label>
			<button type="submit" class="button">پیگیری</button>
		</form>

		<?php $rc = sanitize_key( $_GET['receipt'] ?? '' ); if ( $rc && ! $order ) : ?>
			<p class="<?php echo 'ok' === $rc ? 'b-track__ok' : 'b-track__error'; ?>" role="status" id="b-receipt"><?php echo 'ok' === $rc ? 'رسید شما رسید ✓ بعد از بررسی واریز، سفارش تأیید می‌شود.' : 'آپلود رسید انجام نشد. سفارش را دوباره پیدا کنید و فقط عکس (JPG یا PNG، تا ۱۰ مگابایت) بفرستید، یا رسید را در پیام‌رسان بفرستید.'; ?></p>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<p class="b-track__error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php elseif ( $order ) :
			[ $step, $label, $hint ] = boutique_track_state( $order );
			$steps    = $order->has_shipping_method( 'local_pickup' )
				? [ 'در انتظار تأیید پرداخت', 'ثبت شده', 'آماده تحویل', 'تحویل داده شد' ]
				: [ 'در انتظار تأیید پرداخت', 'ثبت شده', 'تحویل پیک شد' ];
			$tracking = $order->get_meta( '_boutique_tracking' ); ?>
			<section class="b-track__result" aria-live="polite">
				<header>
					<h2>سفارش <bdi>#<?php echo esc_html( $order->get_order_number() ); ?></bdi></h2>
					<p><?php echo esc_html( wp_date( 'j F Y', $order->get_date_created()->getTimestamp() ) ); ?></p>
				</header>
				<p class="b-track__status <?php echo $step < 0 ? 'is-stopped' : ''; ?>" data-step="<?php echo (int) $step; ?>"><strong><?php echo esc_html( $label ); ?></strong><?php if ( $hint ) : ?><span><?php echo esc_html( $hint ); ?></span><?php endif; ?></p>
				<?php if ( $step >= 0 && 'instore' !== $order->get_created_via() ) : ?>
					<ol class="b-track__steps">
						<?php foreach ( $steps as $i => $s ) : ?>
							<li class="<?php echo $i < $step ? 'is-done' : ( $i === $step ? 'is-now' : '' ); ?>"><?php echo esc_html( $s ); ?></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
				<?php if ( $tracking ) : ?>
					<p class="b-track__code">کد رهگیری مرسوله: <bdi dir="ltr"><?php echo esc_html( $tracking ); ?></bdi></p>
				<?php endif; ?>
				<ul class="b-track__items">
					<?php foreach ( $order->get_items() as $item ) : ?>
						<li><span><?php echo esc_html( $item->get_name() ); ?><?php echo $item->get_quantity() > 1 ? ' × ' . (int) $item->get_quantity() : ''; ?></span><span><?php echo wp_kses_post( wc_price( $item->get_total() ) ); ?></span></li>
					<?php endforeach; ?>
					<?php if ( $order->get_shipping_method() ) : ?>
						<li><span><?php echo esc_html( $order->get_shipping_method() ); ?></span><span><?php echo wp_kses_post( wc_price( $order->get_shipping_total() ) ); ?></span></li>
					<?php endif; ?>
					<li class="b-track__total"><span>مجموع</span><span><?php echo wp_kses_post( wc_price( $order->get_total() ) ); ?></span></li>
				</ul>
			</section>
			<?php if ( function_exists( 'boutique_receipt_box' ) ) { boutique_receipt_box( $order, boutique_track_url( $order->get_id() ) ); } ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
} );

/* ---------------------------------------- After checkout: show how to track */
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	if ( ! $order_id ) { return; }
	echo '<p class="b-track__thanks">شماره سفارش شما <strong><bdi>#' . esc_html( $order_id ) . '</bdi></strong> است. وضعیت سفارش را هر وقت خواستید از '
		. '<a href="' . esc_url( boutique_track_url( $order_id ) ) . '">پیگیری سفارش</a> با همین شماره و موبایلتان ببینید.</p>';
}, 5 );

/* ------------------------- Customer-facing status names (my account, emails) */
add_filter( 'wc_order_statuses', function ( $s ) {
	if ( is_admin() && ! wp_doing_ajax() ) { return $s; }
	$s['wc-on-hold']    = 'در انتظار تأیید پرداخت';
	$s['wc-processing'] = 'ثبت شده، در حال آماده‌سازی';
	$s['wc-completed']  = 'تحویل پیک شد';
	return $s;
} );
