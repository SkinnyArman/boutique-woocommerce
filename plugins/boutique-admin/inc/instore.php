<?php
/**
 * «ثبت فروش حضوری»: record a sale made in the physical shop so website stock stays true.
 * Creates a completed WooCommerce order (created_via = instore); WooCommerce lowers stock.
 */
defined( 'ABSPATH' ) || exit;

// No card reader in the shop: card-to-card or cash.
const BOUTIQUE_PAY_METHODS = [
	'transfer' => 'کارت به کارت',
	'cash'     => 'نقدی',
];

add_action( 'admin_menu', function () {
	add_menu_page( 'ثبت فروش حضوری', 'فروش حضوری', 'edit_shop_orders', 'boutique-instore', 'boutique_instore_page', 'dashicons-cart', 3 );
} );

// No "new order" email to the shop for sales it just made by hand.
add_filter( 'woocommerce_email_enabled_new_order', function ( $enabled, $order ) {
	return ( $order instanceof WC_Order && 'instore' === $order->get_created_via() ) ? false : $enabled;
}, 10, 2 );

/** Catalog as compact JSON for the picker. Small shop: load it all once. */
function boutique_instore_catalog() {
	$out = [];
	foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'type' => [ 'variable', 'simple' ], 'orderby' => 'date', 'order' => 'DESC' ] ) as $p ) {
		$row = [
			'id'    => $p->get_id(),
			'name'  => $p->get_name(),
			'img'   => $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' ),
			'vars'  => [],
		];
		$kids = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : [ $p ];
		foreach ( $kids as $k ) {
			$attrs = $k->is_type( 'variation' ) ? $k->get_attributes() : [];
			$color = isset( $attrs['pa_color'] ) ? ( get_term_by( 'slug', $attrs['pa_color'], 'pa_color' )->name ?? $attrs['pa_color'] ) : '';
			$size  = isset( $attrs['pa_size'] ) ? ( get_term_by( 'slug', $attrs['pa_size'], 'pa_size' )->name ?? $attrs['pa_size'] ) : '';
			$row['vars'][] = [
				'id'    => $k->get_id(),
				'color' => $color,
				'size'  => $size,
				'label' => boutique_variation_label( $k ),
				'price' => (float) $k->get_price(),
				'stock' => $k->managing_stock() ? (int) $k->get_stock_quantity() : null,
			];
		}
		if ( $row['vars'] ) { $out[] = $row; }
	}
	return $out;
}

/* --------------------------------------------------------------- Handler */
add_action( 'admin_post_boutique_instore_sale', function () {
	check_admin_referer( 'boutique_instore_sale' );
	if ( ! current_user_can( 'edit_shop_orders' ) ) { wp_die( 'دسترسی ندارید.' ); }

	$lines = json_decode( wp_unslash( $_POST['lines'] ?? '[]' ), true );
	$pay   = sanitize_key( $_POST['pay'] ?? 'transfer' );
	$pay   = isset( BOUTIQUE_PAY_METHODS[ $pay ] ) ? $pay : 'transfer';
	$back  = admin_url( 'admin.php?page=boutique-instore' );

	if ( ! is_array( $lines ) || ! $lines ) {
		wp_safe_redirect( add_query_arg( 'err', 'empty', $back ) );
		exit;
	}

	$order = wc_create_order( [ 'created_via' => 'instore', 'customer_id' => 0 ] );
	if ( is_wp_error( $order ) ) { wp_die( esc_html( $order->get_error_message() ) ); }

	foreach ( array_slice( $lines, 0, 50 ) as $l ) {
		$prod  = wc_get_product( absint( $l['id'] ?? 0 ) );
		$qty   = max( 1, min( 99, absint( $l['qty'] ?? 1 ) ) );
		$price = max( 0, (float) boutique_latin_digits( $l['price'] ?? 0 ) );
		if ( ! $prod || ! ( $prod->is_type( 'variation' ) || $prod->is_type( 'simple' ) ) ) { continue; }
		$order->add_product( $prod, $qty, [ 'subtotal' => $price * $qty, 'total' => $price * $qty ] );
	}
	if ( ! $order->get_items() ) {
		$order->delete( true );
		wp_safe_redirect( add_query_arg( 'err', 'empty', $back ) );
		exit;
	}

	$name  = sanitize_text_field( wp_unslash( $_POST['customer_name'] ?? '' ) );
	$phone = sanitize_text_field( boutique_latin_digits( wp_unslash( $_POST['customer_phone'] ?? '' ) ) );
	if ( $name ) { $order->set_billing_first_name( $name ); }
	if ( $phone ) { $order->set_billing_phone( $phone ); }
	$order->set_billing_country( 'IR' );
	$order->set_payment_method( 'instore_' . $pay );
	$order->set_payment_method_title( 'حضوری — ' . BOUTIQUE_PAY_METHODS[ $pay ] );
	$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
	$order->calculate_totals( false );
	$order->set_date_paid( time() );
	$order->save();

	if ( $note ) { $order->add_order_note( $note ); }
	// Completing the order makes WooCommerce reduce stock (wc_maybe_reduce_stock_levels).
	$order->update_status( 'completed', 'فروش حضوری، ثبت توسط ' . wp_get_current_user()->display_name . '.' );

	wp_safe_redirect( add_query_arg( 'sold', $order->get_id(), $back ) );
	exit;
} );

/* ------------------------------------------------------------------ Page */
function boutique_instore_page() {
	$sold = absint( $_GET['sold'] ?? 0 );
	$err  = sanitize_key( $_GET['err'] ?? '' );
	?>
	<div class="wrap b-wrap b-pos">
		<header class="b-head">
			<div>
				<h1 class="b-title">ثبت فروش حضوری</h1>
				<p class="b-muted">لباسی که در فروشگاه فروختید را اینجا ثبت کنید تا موجودی سایت درست بماند.</p>
			</div>
		</header>

		<?php if ( $sold && ( $o = wc_get_order( $sold ) ) ) : ?>
			<div class="b-done" role="status">
				<strong>فروش ثبت شد. #<?php echo esc_html( boutique_fa_digits( $o->get_order_number() ) ); ?> · <?php echo esc_html( boutique_money( $o->get_total() ) ); ?></strong>
				<ul>
				<?php foreach ( $o->get_items() as $item ) : $p = $item->get_product(); ?>
					<li><?php echo esc_html( $item->get_name() ); ?> — موجودی جدید: <?php echo esc_html( $p && $p->managing_stock() ? boutique_fa_digits( (int) $p->get_stock_quantity() ) : '—' ); ?></li>
				<?php endforeach; ?>
				</ul>
				<a href="<?php echo esc_url( boutique_order_url( $o ) ); ?>">مشاهده سفارش</a>
			</div>
		<?php elseif ( 'empty' === $err ) : ?>
			<div class="notice notice-error"><p>هیچ لباسی انتخاب نشده بود.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="b-pos-form" class="b-pos__grid">
			<?php wp_nonce_field( 'boutique_instore_sale' ); ?>
			<input type="hidden" name="action" value="boutique_instore_sale">
			<input type="hidden" name="lines" id="b-pos-lines" value="[]">

			<section class="b-panel b-pos__pick" aria-labelledby="b-pick-title">
				<h2 id="b-pick-title">۱. لباس را پیدا کنید</h2>
				<label class="screen-reader-text" for="b-pos-search">جستجوی لباس</label>
				<input type="search" id="b-pos-search" class="b-pos__search" placeholder="اسم لباس… مثلاً نیلوفر" autocomplete="off">
				<div id="b-pos-products" class="b-pos__products" role="list"></div>
				<div id="b-pos-variant" class="b-pos__variant" hidden></div>
			</section>

			<section class="b-panel b-pos__cart" aria-labelledby="b-cart-title">
				<h2 id="b-cart-title">۲. فاکتور</h2>
				<div id="b-pos-cart"><p class="b-empty">هنوز چیزی اضافه نشده.</p></div>
				<div class="b-pos__total"><span>جمع</span><strong id="b-pos-total">۰ تومان</strong></div>

				<fieldset class="b-pos__pay">
					<legend>پرداخت</legend>
					<?php $first = true; foreach ( BOUTIQUE_PAY_METHODS as $k => $label ) : ?>
						<label><input type="radio" name="pay" value="<?php echo esc_attr( $k ); ?>" <?php checked( $first ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php $first = false; endforeach; ?>
				</fieldset>

				<details class="b-pos__more">
					<summary>مشخصات مشتری (اختیاری)</summary>
					<label>نام <input type="text" name="customer_name" autocomplete="off"></label>
					<label>موبایل <input type="tel" name="customer_phone" inputmode="tel" autocomplete="off"></label>
					<label>یادداشت <textarea name="note" rows="2"></textarea></label>
				</details>

				<button type="submit" class="b-btn b-btn--big b-pos__submit" id="b-pos-submit" disabled>ثبت فروش</button>
			</section>
		</form>
	</div>
	<script>window.BOUTIQUE_CATALOG = <?php echo wp_json_encode( boutique_instore_catalog(), JSON_UNESCAPED_UNICODE ); ?>;</script>
	<?php
	wp_enqueue_script( 'boutique-instore', BOUTIQUE_ADMIN_URL . 'assets/instore.js', [], filemtime( BOUTIQUE_ADMIN_DIR . '/assets/instore.js' ), true );
}
