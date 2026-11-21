<?php
/**
 * «کد تخفیف»: create and manage discount codes without WooCommerce's coupon editor.
 * Options: percent or fixed amount, minimum cart total, limited or unlimited uses,
 * once per customer, optional expiry. Stored as normal WooCommerce coupons.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'کد تخفیف', 'کد تخفیف', 'manage_woocommerce', 'boutique-coupons', 'boutique_coupons_page', 'dashicons-tickets-alt', 6 );
}, 20 );

// Coupons must be switched on for the cart/checkout field to exist.
add_filter( 'pre_option_woocommerce_enable_coupons', fn() => 'yes' );

function boutique_coupons_url( $args = [] ) {
	return add_query_arg( [ 'page' => 'boutique-coupons' ] + $args, admin_url( 'admin.php' ) );
}

/** [state, label]: active | used | expired */
function boutique_coupon_state( WC_Coupon $c ) {
	if ( $c->get_date_expires() && $c->get_date_expires()->getTimestamp() < time() ) { return [ 'expired', 'تمام شده (تاریخ)' ]; }
	if ( $c->get_usage_limit() > 0 && $c->get_usage_count() >= $c->get_usage_limit() ) { return [ 'used', 'ظرفیت پر شد' ]; }
	return [ 'active', 'فعال' ];
}

/* --------------------------------------------------------------- Save */
add_action( 'admin_post_boutique_coupon_save', function () {
	check_admin_referer( 'boutique_coupon_save' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی ندارید.' ); }

	$num  = fn( $k ) => (float) preg_replace( '/[^\d.]/', '', boutique_latin_digits( wp_unslash( $_POST[ $k ] ?? '' ) ) );
	$code = wc_format_coupon_code( boutique_latin_digits( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ) );
	$code = preg_replace( '/\s+/', '', $code );
	$type = 'fixed' === ( $_POST['type'] ?? '' ) ? 'fixed_cart' : 'percent';
	$amt  = $num( 'amount' );
	$fail = function ( $err ) {
		wp_safe_redirect( boutique_coupons_url( [ 'err' => $err ] ) );
		exit;
	};

	if ( '' === $code || $amt <= 0 ) { $fail( 'required' ); }
	if ( 'percent' === $type && $amt > 100 ) { $fail( 'percent' ); }
	if ( wc_get_coupon_id_by_code( $code ) ) { $fail( 'exists' ); }

	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_discount_type( $type );
	$c->set_amount( $amt );
	$c->set_minimum_amount( $num( 'min' ) ?: '' );
	$c->set_usage_limit( 'limited' === ( $_POST['cap'] ?? '' ) ? max( 1, (int) $num( 'limit' ) ) : 0 );
	$c->set_usage_limit_per_user( empty( $_POST['once'] ) ? 0 : 1 );
	$days = (int) $num( 'days' );
	if ( $days > 0 ) {
		// Valid through the end of the last day, shop time.
		$c->set_date_expires( ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . ( $days + 1 ) . ' days' )->getTimestamp() - 1 );
	}
	$c->set_individual_use( true ); // one code per order
	$c->save();

	wp_safe_redirect( boutique_coupons_url( [ 'made' => rawurlencode( $code ) ] ) );
	exit;
} );

add_action( 'admin_post_boutique_coupon_delete', function () {
	$id = absint( $_POST['coupon_id'] ?? 0 );
	check_admin_referer( 'boutique_coupon_delete_' . $id );
	if ( ! current_user_can( 'manage_woocommerce' ) || 'shop_coupon' !== get_post_type( $id ) ) { wp_die( 'دسترسی ندارید.' ); }
	( new WC_Coupon( $id ) )->delete( true );
	wp_safe_redirect( boutique_coupons_url( [ 'gone' => 1 ] ) );
	exit;
} );

/* --------------------------------------------------------------- Page */
function boutique_coupons_page() {
	$ids  = get_posts( [ 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] );
	$err  = sanitize_key( $_GET['err'] ?? '' );
	$made = sanitize_text_field( wp_unslash( $_GET['made'] ?? '' ) );
	$errs = [
		'required' => 'کد و مقدار تخفیف را وارد کنید.',
		'percent'  => 'درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.',
		'exists'   => 'این کد قبلاً ساخته شده. یک کد دیگر انتخاب کنید.',
	];
	?>
	<div class="wrap b-wrap b-cp">
		<header class="b-head">
			<div>
				<h1 class="b-title">کد تخفیف</h1>
				<p class="b-muted">کد بسازید و به مشتری بدهید؛ مشتری آن را در سبد خرید وارد می‌کند.</p>
			</div>
		</header>

		<?php if ( $made ) : ?>
			<div class="b-done" role="status"><strong>کد «<bdi dir="ltr"><?php echo esc_html( strtoupper( $made ) ); ?></bdi>» ساخته شد و از همین حالا قابل استفاده است.</strong></div>
		<?php elseif ( isset( $errs[ $err ] ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $errs[ $err ] ); ?></p></div>
		<?php elseif ( isset( $_GET['gone'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>کد حذف شد.</p></div>
		<?php endif; ?>

		<div class="b-cp__grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b-panel b-cp__form" id="b-cp-form">
				<?php wp_nonce_field( 'boutique_coupon_save' ); ?>
				<input type="hidden" name="action" value="boutique_coupon_save">
				<h2>کد جدید</h2>

				<label class="b-field"><span>کد (حروف انگلیسی و عدد)</span>
					<span class="b-cp__code"><input type="text" name="code" id="b-cp-code" dir="ltr" required autocomplete="off" placeholder="YALDA20">
					<button type="button" class="b-btn b-btn--ghost" id="b-cp-random">کد تصادفی</button></span>
				</label>

				<fieldset class="b-pos__pay"><legend>نوع تخفیف</legend>
					<label><input type="radio" name="type" value="percent" checked> درصدی</label>
					<label><input type="radio" name="type" value="fixed"> مبلغ ثابت (تومان)</label>
				</fieldset>
				<label class="b-field"><span id="b-cp-amount-label">چند درصد؟</span>
					<input type="text" inputmode="numeric" name="amount" id="b-cp-amount" required placeholder="۲۰">
				</label>

				<label class="b-field"><span>فقط برای سبدهای بالای… (تومان، اختیاری)</span>
					<input type="text" inputmode="numeric" name="min" id="b-cp-min" placeholder="خالی = بدون حداقل">
				</label>

				<fieldset class="b-pos__pay"><legend>ظرفیت</legend>
					<label><input type="radio" name="cap" value="unlimited" checked> نامحدود</label>
					<label><input type="radio" name="cap" value="limited"> محدود</label>
				</fieldset>
				<label class="b-field" id="b-cp-limit-wrap" hidden><span>چند بار قابل استفاده باشد؟</span>
					<input type="text" inputmode="numeric" name="limit" placeholder="۵۰">
				</label>

				<label class="b-cp__check"><input type="checkbox" name="once" value="1"> هر مشتری فقط یک بار</label>

				<label class="b-field"><span>تا چند روز دیگر اعتبار دارد؟ (اختیاری)</span>
					<input type="text" inputmode="numeric" name="days" placeholder="خالی = بدون تاریخ انقضا">
				</label>

				<button type="submit" class="b-btn b-btn--big">ساخت کد</button>
			</form>

			<section class="b-panel" aria-labelledby="b-cp-list">
				<h2 id="b-cp-list">کدهای موجود <span class="b-count"><?php echo esc_html( boutique_fa_digits( count( $ids ) ) ); ?></span></h2>
				<?php if ( ! $ids ) : ?>
					<p class="b-empty">هنوز کدی نساخته‌اید.</p>
				<?php else : ?>
					<div class="b-sp__scroll"><table class="b-table b-cp__table">
						<thead><tr><th scope="col">کد</th><th scope="col">تخفیف</th><th scope="col">شرط</th><th scope="col">استفاده</th><th scope="col">وضعیت</th><th scope="col"><span class="screen-reader-text">حذف</span></th></tr></thead>
						<tbody>
						<?php foreach ( $ids as $id ) : $c = new WC_Coupon( $id ); [ $state, $label ] = boutique_coupon_state( $c ); ?>
							<tr data-code="<?php echo esc_attr( $c->get_code() ); ?>">
								<td><bdi dir="ltr" class="b-cp__chip"><?php echo esc_html( strtoupper( $c->get_code() ) ); ?></bdi></td>
								<td><?php echo esc_html( 'percent' === $c->get_discount_type() ? boutique_fa_digits( (float) $c->get_amount() ) . '٪' : boutique_money( $c->get_amount() ) ); ?></td>
								<td>
									<?php
									$cond = [];
									if ( (float) $c->get_minimum_amount() > 0 ) { $cond[] = 'سبد بالای ' . boutique_money( $c->get_minimum_amount() ); }
									if ( $c->get_usage_limit_per_user() ) { $cond[] = 'هر مشتری یک بار'; }
									if ( $c->get_date_expires() ) { $cond[] = 'تا ' . wp_date( 'j F', $c->get_date_expires()->getTimestamp() ); }
									echo esc_html( $cond ? implode( ' · ', $cond ) : '—' );
									?>
								</td>
								<td><?php echo esc_html( boutique_fa_digits( $c->get_usage_count() ) . ( $c->get_usage_limit() ? ' از ' . boutique_fa_digits( $c->get_usage_limit() ) : ' (نامحدود)' ) ); ?></td>
								<td><span class="b-stock <?php echo 'active' === $state ? 'is-ok' : 'is-out'; ?>"><?php echo esc_html( $label ); ?></span></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('این کد حذف شود؟ دیگر قابل استفاده نخواهد بود.')">
										<?php wp_nonce_field( 'boutique_coupon_delete_' . $id ); ?>
										<input type="hidden" name="action" value="boutique_coupon_delete"><input type="hidden" name="coupon_id" value="<?php echo (int) $id; ?>">
										<button type="submit" class="b-btn b-btn--quiet">حذف</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php endif; ?>
			</section>
		</div>
	</div>
	<script>
	(function () {
		var f = document.getElementById('b-cp-form');
		var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
		var latin = function (s) { return String(s).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }); };
		document.getElementById('b-cp-random').addEventListener('click', function () {
			var abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789', s = '';
			for (var i = 0; i < 7; i++) s += abc[Math.floor(Math.random() * abc.length)];
			document.getElementById('b-cp-code').value = s;
		});
		f.addEventListener('change', function () {
			var fixed = f.querySelector('[name=type]:checked').value === 'fixed';
			document.getElementById('b-cp-amount-label').textContent = fixed ? 'چند تومان؟' : 'چند درصد؟';
			document.getElementById('b-cp-amount').placeholder = fixed ? '۲۰۰٬۰۰۰' : '۲۰';
			document.getElementById('b-cp-limit-wrap').hidden = f.querySelector('[name=cap]:checked').value !== 'limited';
		});
		f.querySelectorAll('input[inputmode=numeric]').forEach(function (i) {
			i.addEventListener('input', function () {
				var n = latin(i.value).replace(/[^\d]/g, '');
				i.value = n ? fa(Number(n).toLocaleString('en-US')) : '';
			});
		});
		f.addEventListener('submit', function () {
			f.querySelectorAll('input[inputmode=numeric]').forEach(function (i) { i.value = latin(i.value).replace(/[^\d]/g, ''); });
		});
	})();
	</script>
	<?php
}
