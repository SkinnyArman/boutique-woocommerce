<?php
/**
 * «امروز»: the manager's home screen. Answers "what do I need to do right now?"
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	$waiting = count( wc_get_orders( [ 'status' => [ 'on-hold', 'processing' ], 'limit' => 50, 'return' => 'ids' ] ) );
	$badge   = $waiting ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $waiting . '</span></span>' : '';
	add_menu_page( 'امروز', 'امروز' . $badge, 'manage_woocommerce', 'boutique-today', 'boutique_today_page', 'dashicons-store', 2 );
} );

/* ---------------------------------------------------------- Order actions */
add_action( 'admin_post_boutique_order_step', function () {
	$id = absint( $_POST['order_id'] ?? 0 );
	check_admin_referer( 'boutique_order_step_' . $id );
	if ( ! current_user_can( 'edit_shop_orders' ) ) { wp_die( 'دسترسی ندارید.' ); }

	$order = wc_get_order( $id );
	$to    = sanitize_key( $_POST['to'] ?? '' );
	if ( ! $order || ! in_array( $to, [ 'processing', 'ready', 'completed', 'cancelled' ], true ) ) { wp_die( 'سفارش پیدا نشد.' ); }

	$user = wp_get_current_user()->display_name;
	if ( 'ready' === $to ) {
		// Pickup order packed and waiting at the shop. Customer sees it in «پیگیری سفارش».
		$order->update_meta_data( '_boutique_ready', time() );
		$order->save();
		$GLOBALS['boutique_note_email'] = true;   // this note is the customer's «آماده تحویل» email
		$order->add_order_note( 'سفارش شما آماده تحویل در فروشگاه است. آدرس: ' . boutique_shop_address() . ' — ساعات کاری: ' . boutique_shop_hours() . '. قبل از آمدن با ' . boutique_shop_phone() . ' تماس بگیرید.', 1 );
		$order->add_order_note( "آماده تحویل حضوری شد (ثبت توسط {$user})." );
	} elseif ( 'completed' === $to && boutique_order_is_pickup( $order ) ) {
		$order->update_status( 'completed', "در فروشگاه تحویل مشتری شد (ثبت توسط {$user})." );
	} elseif ( 'completed' === $to ) {
		$track = sanitize_text_field( boutique_latin_digits( wp_unslash( $_POST['tracking'] ?? '' ) ) );
		if ( $track ) {
			$order->update_meta_data( '_boutique_tracking', $track );
			// Customer-visible note: shows in "my account" and is emailed to them.
			$order->add_order_note( 'سفارش شما تحویل پیک شد. کد رهگیری: ' . $track, 1 );
		}
		$order->update_status( 'completed', "تحویل پیک شد (ثبت توسط {$user})." );
	} elseif ( 'processing' === $to ) {
		$order->update_status( 'processing', "واریز تأیید شد (ثبت توسط {$user})." );
	} else {
		$order->update_status( 'cancelled', "لغو شد (ثبت توسط {$user})." );
	}

	wp_safe_redirect( add_query_arg( [ 'page' => 'boutique-today', 'done' => $to, 'order' => $id ], admin_url( 'admin.php' ) ) );
	exit;
} );

function boutique_step_form( WC_Order $o, $to, $label, $class = 'b-btn', $with_tracking = false, $confirm = '' ) {
	$onsubmit = $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="b-step"' . $onsubmit . '>';
	wp_nonce_field( 'boutique_order_step_' . $o->get_id() );
	echo '<input type="hidden" name="action" value="boutique_order_step"><input type="hidden" name="order_id" value="' . (int) $o->get_id() . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '">';
	if ( $with_tracking ) {
		echo '<input type="text" name="tracking" inputmode="numeric" placeholder="کد رهگیری (اختیاری)" aria-label="کد رهگیری مرسوله">';
	}
	echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

function boutique_order_line( WC_Order $o ) {
	$items = [];
	foreach ( $o->get_items() as $item ) {
		$p       = $item->get_product();
		$v       = $p ? boutique_variation_label( $p ) : '';
		$items[] = esc_html( ( $p && $p->get_parent_id() ? wc_get_product( $p->get_parent_id() )->get_name() : $item->get_name() ) . ( $v ? " ({$v})" : '' ) . ( $item->get_quantity() > 1 ? ' × ' . boutique_fa_digits( $item->get_quantity() ) : '' ) );
	}
	$name     = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ?: 'مشتری';
	$phone    = $o->get_billing_phone();
	$shipping = $o->get_shipping_method();
	?>
	<div class="b-order__main">
		<a class="b-order__id" href="<?php echo esc_url( boutique_order_url( $o ) ); ?>">#<?php echo esc_html( boutique_fa_digits( $o->get_order_number() ) ); ?></a>
		<strong><?php echo esc_html( $name ); ?></strong>
		<?php if ( $phone ) : ?><a class="b-order__phone" href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( boutique_fa_digits( $phone ) ); ?></a><?php endif; ?>
		<span class="b-muted"><?php echo esc_html( boutique_when( $o->get_date_created() ) ); ?></span>
	</div>
	<div class="b-order__items"><?php echo implode( '، ', $items ); ?></div>
	<div class="b-order__meta">
		<span class="b-order__total"><?php echo esc_html( boutique_money( $o->get_total() ) ); ?></span>
		<?php if ( $shipping ) : ?><span class="b-chip"><?php echo esc_html( $shipping ); ?></span><?php endif; ?>
		<span class="b-chip"><?php echo esc_html( $o->get_payment_method_title() ); ?></span>
	</div>
	<?php
}

/* ------------------------------------------------------------------ Page */
function boutique_today_page() {
	$tz    = wp_timezone();
	$start = ( new DateTimeImmutable( 'today', $tz ) )->getTimestamp();

	// Same definition as «گزارش»: unconfirmed card-to-card (on-hold) isn't a sale yet.
	$today_orders = wc_get_orders( [
		'status'       => [ 'processing', 'completed' ],
		'date_created' => '>=' . $start,
		'limit'        => -1,
	] );
	$sum = $online = $instore = 0;
	foreach ( $today_orders as $o ) {
		$sum += (float) $o->get_total();
		'instore' === $o->get_created_via() ? $instore++ : $online++;
	}

	$to_confirm = wc_get_orders( [ 'status' => 'on-hold', 'limit' => 30, 'orderby' => 'date', 'order' => 'ASC' ] );
	$processing = array_filter(
		wc_get_orders( [ 'status' => 'processing', 'limit' => 60, 'orderby' => 'date', 'order' => 'ASC' ] ),
		fn( $o ) => 'instore' !== $o->get_created_via()
	);
	$to_ship = array_filter( $processing, fn( $o ) => ! boutique_order_is_pickup( $o ) );
	$pickups = array_filter( $processing, 'boutique_order_is_pickup' );

	// Sizes that are gone or about to be.
	$low = [];
	foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'type' => [ 'variable', 'simple' ] ] ) as $p ) {
		$kids = $p->is_type( 'variable' ) ? array_map( 'wc_get_product', $p->get_children() ) : [ $p ];
		foreach ( array_filter( $kids ) as $k ) {
			if ( ! $k->managing_stock() ) { continue; }
			$q = (int) $k->get_stock_quantity();
			if ( $q <= 1 ) { $low[] = [ $p, $k, $q ]; }
		}
	}
	usort( $low, fn( $a, $b ) => $a[2] <=> $b[2] );

	$done   = sanitize_key( $_GET['done'] ?? '' );
	$notice = [ 'processing' => 'واریز تأیید شد؛ سفارش به «آماده ارسال» یا «تحویل حضوری» رفت.', 'ready' => 'ثبت شد؛ مشتری در «پیگیری سفارش» می‌بیند که سفارش آماده است. یک تماس هم بگیرید.', 'completed' => 'ثبت شد؛ مشتری در «پیگیری سفارش» وضعیت جدید را می‌بیند.', 'cancelled' => 'سفارش لغو شد و موجودی برگشت.' ][ $done ] ?? '';
	?>
	<div class="wrap b-wrap">
		<header class="b-head">
			<div>
				<h1 class="b-title">امروز</h1>
				<p class="b-muted"><?php echo esc_html( wp_date( 'l j F Y' ) ); ?></p>
			</div>
			<div class="b-head__actions">
				<a class="b-btn b-btn--big" href="<?php echo esc_url( admin_url( 'admin.php?page=boutique-instore' ) ); ?>"><span class="dashicons dashicons-cart" aria-hidden="true"></span> ثبت فروش حضوری</a>
				<a class="b-btn b-btn--ghost" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> لباس جدید</a>
			</div>
		</header>

		<?php if ( $notice ) : ?><div class="notice notice-success is-dismissible b-notice"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

		<?php boutique_app_card(); ?>

		<section class="b-stats" aria-label="خلاصه امروز">
			<div class="b-stat b-stat--hero"><span>فروش امروز</span><strong><?php echo esc_html( boutique_money( $sum ) ); ?></strong></div>
			<div class="b-stat"><span>سفارش آنلاین</span><strong><?php echo esc_html( boutique_fa_digits( $online ) ); ?></strong></div>
			<div class="b-stat"><span>فروش حضوری</span><strong><?php echo esc_html( boutique_fa_digits( $instore ) ); ?></strong></div>
		</section>

		<div class="b-cols">
			<section class="b-panel" aria-labelledby="b-confirm">
				<h2 id="b-confirm">منتظر تأیید واریز <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $to_confirm ) ) ); ?></span></h2>
				<p class="b-hint">مشتری کارت‌به‌کارت کرده. رسید را (اینجا یا در پیام‌رسان) با حساب بانکی مقایسه کنید؛ اگر واریز رسیده، تأیید کنید. سفارشی که رسیدش آپلود نشده و تا ۲۴ ساعت تأیید نشود، خودکار لغو می‌شود. اگر رسید در تلگرام یا واتساپ آمده، قبل از آن تأیید کنید.</p>
				<?php if ( ! $to_confirm ) : ?>
					<p class="b-empty">چیزی منتظر تأیید نیست.</p>
				<?php else : foreach ( $to_confirm as $o ) : ?>
					<article class="b-order">
						<?php boutique_order_line( $o ); ?>
						<?php boutique_receipt_admin( $o ); boutique_unpaid_chip( $o ); ?>
						<div class="b-order__actions">
							<?php boutique_step_form( $o, 'processing', 'واریز رسید، تأیید' ); ?>
							<?php boutique_step_form( $o, 'cancelled', 'لغو', 'b-btn b-btn--quiet', false, 'این سفارش لغو شود؟ موجودی برمی‌گردد.' ); ?>
						</div>
					</article>
				<?php endforeach; endif; ?>
			</section>

			<section class="b-panel" aria-labelledby="b-ship">
				<h2 id="b-ship">آماده ارسال <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $to_ship ) ) ); ?></span></h2>
				<p class="b-hint">بسته را به پیک یا پست بدهید (برای پیک: «کپی برای پیک» را در اپ اسنپ‌باکس بچسبانید)، کد رهگیری را (اگر دارد) وارد کنید و «تحویل پیک شد» را بزنید.</p>
				<?php if ( ! $to_ship ) : ?>
					<p class="b-empty">سفارشی برای ارسال نیست.</p>
				<?php else : foreach ( $to_ship as $o ) : ?>
					<article class="b-order">
						<?php boutique_order_line( $o ); ?>
						<div class="b-order__ship"><?php echo wp_kses_post( $o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address() ); ?></div>
						<?php if ( boutique_order_is_courier( $o ) ) { boutique_location_actions( $o ); } ?>
						<div class="b-order__actions"><?php boutique_step_form( $o, 'completed', 'تحویل پیک شد', 'b-btn', true ); ?></div>
					</article>
				<?php endforeach; endif; ?>
			</section>
		</div>

		<section class="b-panel" aria-labelledby="b-pickup">
			<h2 id="b-pickup">تحویل حضوری <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $pickups ) ) ); ?></span></h2>
			<p class="b-hint">سفارش را آماده کنید و «آماده شد» را بزنید (مشتری در پیگیری سفارش می‌بیند؛ یک تماس هم بگیرید). وقتی آمد و تحویل گرفت، «تحویل داده شد». مشتری قبل از آمدن تماس می‌گیرد.</p>
			<?php if ( ! $pickups ) : ?>
				<p class="b-empty">سفارش حضوری منتظر نیست.</p>
			<?php else : foreach ( $pickups as $o ) : $ready = boutique_pickup_ready_at( $o ); ?>
				<article class="b-order">
					<?php boutique_order_line( $o ); ?>
					<?php if ( $ready ) : ?>
						<p class="b-pickup__when"><span class="b-chip">آماده؛ منتظر مشتری</span> آماده شد: <?php echo esc_html( boutique_when( new WC_DateTime( '@' . $ready ) ) ); ?></p>
					<?php endif; ?>
					<div class="b-order__actions">
						<?php if ( ! $ready ) {
							boutique_step_form( $o, 'ready', 'آماده شد' );
						} else {
							boutique_step_form( $o, 'completed', 'تحویل داده شد' );
							boutique_step_form( $o, 'cancelled', 'لغو', 'b-btn b-btn--quiet', false, 'سفارش لغو شود؟ لباس دوباره موجود می‌شود.' );
						} ?>
					</div>
				</article>
			<?php endforeach; endif; ?>
		</section>

		<section class="b-panel" aria-labelledby="b-stock">
			<h2 id="b-stock">تمام‌شده یا رو به اتمام <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $low ) ) ); ?></span></h2>
			<?php if ( ! $low ) : ?>
				<p class="b-empty">همه سایزها موجودند.</p>
			<?php else :
				$out  = array_values( array_filter( $low, fn( $r ) => $r[2] <= 0 ) );
				$last = array_values( array_filter( $low, fn( $r ) => $r[2] > 0 ) );
				$table = function ( array $rows ) { ?>
					<table class="b-table">
						<thead><tr><th scope="col">لباس</th><th scope="col">رنگ / سایز</th><th scope="col">موجودی</th><th scope="col"><span class="screen-reader-text">ویرایش</span></th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $rows, 0, 60 ) as [ $p, $k, $q ] ) : ?>
							<tr>
								<td><?php echo esc_html( $p->get_name() ); ?></td>
								<td><?php echo esc_html( boutique_variation_label( $k ) ?: '—' ); ?></td>
								<td><span class="b-stock <?php echo $q <= 0 ? 'is-out' : 'is-low'; ?>"><?php echo $q <= 0 ? 'تمام شد' : 'فقط ' . esc_html( boutique_fa_digits( $q ) ); ?></span></td>
								<td><a href="<?php echo esc_url( boutique_stock_url( $p->get_id() ) ); ?>">ویرایش موجودی</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php };
				if ( $out ) {
					$table( $out );
				} else {
					echo '<p class="b-empty">هیچ سایزی تمام نشده.</p>';
				}
				if ( $last ) : ?>
					<details class="b-more">
						<summary>فقط یکی مانده: <?php echo esc_html( boutique_fa_digits( count( $last ) ) ); ?> مورد</summary>
						<?php $table( $last ); ?>
					</details>
				<?php endif;
			endif; ?>
		</section>

		<p class="b-foot"><a href="<?php echo esc_url( boutique_orders_page_url() ); ?>">همه سفارش‌ها</a> · <a href="<?php echo esc_url( boutique_products_page_url() ); ?>">همه لباس‌ها</a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=boutique-reports' ) ); ?>">گزارش فروش</a></p>
	</div>
	<?php
}
