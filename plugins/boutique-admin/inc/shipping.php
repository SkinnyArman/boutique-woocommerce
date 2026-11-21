<?php
/**
 * «هزینه ارسال»: edit the name, price and on/off state of every delivery option
 * (courier, Post, Tipax, pickup) on one screen, without WooCommerce's shipping-zone settings.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'هزینه ارسال', 'هزینه ارسال', 'manage_woocommerce', 'boutique-shipping', 'boutique_shipping_page', 'dashicons-location-alt', 7 );
}, 20 );

/** Every shipping method of every zone: [ zone name, WC_Shipping_Method ]. */
function boutique_shipping_methods() {
	$out = [];
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		foreach ( $z['shipping_methods'] as $m ) { $out[] = [ $z['zone_name'], $m ]; }
	}
	return $out;
}

/** Methods with no price to set: courier (پس‌کرایه, paid to the rider) and pickup. Returns the text to show instead. */
function boutique_shipping_cost_fixed( $m ) {
	if ( 'local_pickup' === $m->id ) { return 'رایگان'; }
	if ( function_exists( 'boutique_courier_instance' ) && 'flat_rate' === $m->id && (int) $m->get_instance_id() === boutique_courier_instance() ) {
		return 'پس‌کرایه: مشتری هنگام تحویل به پیک می‌دهد';
	}
	return '';
}

add_action( 'admin_post_boutique_shipping_save', function () {
	check_admin_referer( 'boutique_shipping_save' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'دسترسی ندارید.' ); }
	global $wpdb;

	$rows = (array) ( $_POST['m'] ?? [] );
	foreach ( boutique_shipping_methods() as [ , $m ] ) {
		$iid = $m->get_instance_id();
		if ( ! isset( $rows[ $iid ] ) ) { continue; }
		$in       = $rows[ $iid ];
		$settings = (array) get_option( $m->get_instance_option_key(), [] );
		$title    = sanitize_text_field( wp_unslash( $in['title'] ?? '' ) );
		if ( '' !== $title ) { $settings['title'] = $title; }
		$settings['cost'] = boutique_shipping_cost_fixed( $m ) ? '0'   // courier: customer pays the rider; pickup: free
			: (string) (int) preg_replace( '/[^\d]/', '', boutique_latin_digits( wp_unslash( $in['cost'] ?? '0' ) ) );
		update_option( $m->get_instance_option_key(), $settings );
		$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", [ 'is_enabled' => empty( $in['on'] ) ? 0 : 1 ], [ 'instance_id' => $iid ] );
	}
	// Free-shipping threshold (toman); 0 = off.
	if ( isset( $_POST['free_min'] ) ) {
		update_option( 'boutique_free_ship_min', (int) preg_replace( '/[^\d]/', '', boutique_latin_digits( wp_unslash( $_POST['free_min'] ) ) ) );
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true ); // carts recalculate with the new prices

	wp_safe_redirect( add_query_arg( [ 'page' => 'boutique-shipping', 'ok' => 1 ], admin_url( 'admin.php' ) ) );
	exit;
} );

function boutique_shipping_page() {
	$zone = null;
	?>
	<div class="wrap b-wrap b-ship">
		<header class="b-head">
			<div>
				<h1 class="b-title">هزینه ارسال</h1>
				<p class="b-muted">قیمت هر روش ارسال را به تومان وارد کنید. عدد ۰ یعنی رایگان. روشی که خاموش باشد به مشتری نشان داده نمی‌شود.</p>
			</div>
		</header>
		<?php if ( isset( $_GET['ok'] ) ) : ?><div class="b-done" role="status"><strong>ذخیره شد. مشتری‌ها از همین حالا قیمت جدید را می‌بینند.</strong></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b-ship__form">
			<?php wp_nonce_field( 'boutique_shipping_save' ); ?>
			<input type="hidden" name="action" value="boutique_shipping_save">
			<section class="b-panel b-ship__free">
				<h2>ارسال رایگان</h2>
				<label class="b-field b-ship__cost"><span>سفارش‌های بالای این مبلغ (تومان)، با هر روش ارسال، رایگان فرستاده می‌شوند. ۰ یعنی خاموش.</span>
					<input type="text" inputmode="numeric" name="free_min" id="b-free-min" value="<?php echo esc_attr( boutique_fa_digits( number_format( boutique_free_ship_min() ) ) ); ?>">
				</label>
			</section>
			<?php foreach ( boutique_shipping_methods() as [ $zname, $m ] ) :
				$iid = $m->get_instance_id();
				if ( $zname !== $zone ) {
					if ( null !== $zone ) { echo '</section>'; }
					$zone = $zname;
					echo '<section class="b-panel"><h2>' . esc_html( $zname ) . '</h2>';
				} ?>
				<div class="b-ship__row" data-instance="<?php echo (int) $iid; ?>">
					<label class="b-ship__on"><input type="checkbox" name="m[<?php echo (int) $iid; ?>][on]" value="1" <?php checked( 'yes', $m->enabled ); ?>><span class="screen-reader-text">فعال</span></label>
					<label class="b-field b-ship__title"><span>نام روش</span><input type="text" name="m[<?php echo (int) $iid; ?>][title]" value="<?php echo esc_attr( $m->get_title() ); ?>"></label>
					<?php if ( $fixed = boutique_shipping_cost_fixed( $m ) ) : ?>
						<p class="b-field b-ship__fixed"><span>قیمت</span><strong><?php echo esc_html( $fixed ); ?></strong></p>
					<?php else : ?>
						<label class="b-field b-ship__cost"><span>قیمت (تومان)</span><input type="text" inputmode="numeric" name="m[<?php echo (int) $iid; ?>][cost]" value="<?php echo esc_attr( boutique_fa_digits( number_format( (int) $m->get_option( 'cost', 0 ) ) ) ); ?>"></label>
					<?php endif; ?>
				</div>
			<?php endforeach;
			if ( null !== $zone ) { echo '</section>'; } ?>
			<div class="b-savebar is-dirty"><button type="submit" class="b-btn b-btn--big">ذخیره قیمت‌ها</button></div>
		</form>
	</div>
	<script>
	(function () {
		var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
		var latin = function (s) { return String(s).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }); };
		document.querySelectorAll('.b-ship__cost input').forEach(function (i) {
			i.addEventListener('input', function () { var n = latin(i.value).replace(/[^\d]/g, ''); i.value = n === '' ? '' : fa(Number(n).toLocaleString('en-US')); });
			i.addEventListener('focus', function () { i.select(); });
		});
	})();
	</script>
	<?php
}
