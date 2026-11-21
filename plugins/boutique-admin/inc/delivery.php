<?php
/**
 * Delivery rules, customer side and owner side.
 *
 * پیک (Snapp Box / Alopeyk), «پس‌کرایه»: the customer pays the rider; no price on the site.
 *   - Offered only when the address is in the city of Tehran (not the whole province).
 *   - At checkout the customer can send their exact location: «موقعیت من» (phone GPS) or by
 *     pasting a Neshan / Balad / Google Maps link. Stored on the order.
 *   - The owner gets «نشان» / «گوگل‌مپ» buttons and «کپی برای پیک» (name, mobile, address,
 *     map link in one paste for the courier app).
 *
 * تحویل حضوری:
 *   - Offered everywhere (anyone may come to the shop). No street address needed.
 *   - Customer calls before coming. Owner flow: «آماده شد» → customer sees «آماده تحویل در
 *     فروشگاه» with address, hours and the shop's phone → «تحویل داده شد».
 *
 * No external requests: GPS is the browser's own; map links only open when clicked.
 */
defined( 'ABSPATH' ) || exit;

// BOUTIQUE_COURIER_CITY and the shop's province (state_code) come from config/shop.php.

/* ----------------------------------------------------------- Helpers */

/** Instance id of the courier method (set by scripts/delivery.php). */
function boutique_courier_instance() {
	return (int) get_option( 'boutique_courier_instance', 0 );
}

function boutique_is_courier_rate( $rate ) {
	return $rate instanceof WC_Shipping_Rate && 'flat_rate' === $rate->get_method_id()
		&& (int) $rate->get_instance_id() === boutique_courier_instance();
}

function boutique_order_is_courier( WC_Order $o ) {
	foreach ( $o->get_shipping_methods() as $m ) {
		if ( 'flat_rate' === $m->get_method_id() && (int) $m->get_instance_id() === boutique_courier_instance() ) { return true; }
	}
	return false;
}

function boutique_order_is_pickup( WC_Order $o ) {
	return $o->has_shipping_method( 'local_pickup' ) || $o->has_shipping_method( 'pickup_location' );
}

/** "تهران " / "تهران‌" / Arabic ye/kaf → "تهران" for comparing city names. */
function boutique_norm_city( $s ) {
	return str_replace( [ 'ي', 'ك', "\u{200C}", ' ' ], [ 'ی', 'ک', '', '' ], trim( (string) $s ) );
}

/**
 * Coordinates from what a customer pastes: "35.79, 51.47", a Google Maps / Neshan URL with
 * "@lat,lng" or "q=lat,lng", or a Balad URL with latitude=…&longitude=…. Only Iran's bounding
 * box is accepted. Returns [lat, lng] or null (short links like nshn.ir can't be read; the raw
 * link is kept for the owner anyway).
 */
function boutique_parse_location( $s ) {
	$s = boutique_latin_digits( rawurldecode( (string) $s ) );
	if ( preg_match( '/latitude=(-?\d+\.\d+).*?longitude=(-?\d+\.\d+)/', $s, $m )
		|| preg_match( '/(-?\d{2}\.\d{3,})\s*[,،\s]\s*(-?\d{2}\.\d{3,})/u', $s, $m ) ) {
		[ , $lat, $lng ] = $m;
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( $lat > 44 && $lng < 40 ) { [ $lat, $lng ] = [ $lng, $lat ]; } // pasted as lng,lat
		if ( $lat >= 24 && $lat <= 40 && $lng >= 44 && $lng <= 64 ) {
			return [ round( $lat, 6 ), round( $lng, 6 ) ];
		}
	}
	return null;
}

function boutique_map_links( $lat, $lng ) {
	return [
		'neshan' => "https://neshan.org/maps/@{$lat},{$lng},17.0z,0.0p",
		'google' => "https://www.google.com/maps?q={$lat},{$lng}",
	];
}

/** [lat, lng] stored on an order, or null. */
function boutique_order_location( WC_Order $o ) {
	$v = (string) $o->get_meta( '_boutique_loc' );
	return preg_match( '/^(-?[\d.]+),(-?[\d.]+)$/', $v, $m ) ? [ (float) $m[1], (float) $m[2] ] : null;
}

function boutique_pickup_ready_at( WC_Order $o ) {
	return (int) $o->get_meta( '_boutique_ready' );
}

function boutique_shop_address() { return defined( 'BOUTIQUE_ADDRESS' ) ? BOUTIQUE_ADDRESS : ''; }
function boutique_shop_hours()   { return defined( 'BOUTIQUE_HOURS' ) ? BOUTIQUE_HOURS : ''; }
function boutique_shop_phone()   { return defined( 'BOUTIQUE_PHONE' ) ? BOUTIQUE_PHONE : ''; }

/* ------------------------------------------------- Courier: Tehran city only */
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	$state = $package['destination']['state'] ?? '';
	$city  = boutique_norm_city( $package['destination']['city'] ?? '' );
	// Empty city = the cart page's estimate before an address exists: still show it for Tehran.
	$ok = boutique_shop( 'state_code' ) === $state && ( '' === $city || boutique_norm_city( BOUTIQUE_COURIER_CITY ) === $city );
	if ( ! $ok ) {
		foreach ( $rates as $key => $rate ) {
			if ( boutique_is_courier_rate( $rate ) ) { unset( $rates[ $key ] ); }
		}
	}
	return $rates;
}, 20, 2 );

/* ------------------------------------------------- Checkout: what the customer sees */
add_action( 'woocommerce_after_checkout_billing_form', function () {
	$courier = 'flat_rate:' . boutique_courier_instance();
	?>
	<div class="b-deliv b-deliv--courier" data-b-courier="<?php echo esc_attr( $courier ); ?>" hidden>
		<h3>موقعیت دقیق برای پیک</h3>
		<p><strong>تحویل فوری:</strong> بعد از تأیید پرداخت، سفارش را با اسنپ‌باکس می‌فرستیم و معمولاً همان روز، ظرف چند ساعت به دستتان می‌رسد. کرایه پیک را هنگام تحویل خودتان به پیک می‌دهید. با فرستادن لوکیشن، پیک خانه را راحت‌تر پیدا می‌کند.</p>
		<div class="b-deliv__row">
			<button type="button" class="button b-deliv__gps">موقعیت من</button>
			<span class="b-deliv__status" role="status" aria-live="polite"></span>
		</div>
		<p class="form-row form-row-wide">
			<label for="b_loc_link">یا لینک لوکیشن از نشان، بلد یا گوگل‌مپ <span class="optional">(اختیاری)</span></label>
			<input type="text" class="input-text" name="b_loc_link" id="b_loc_link" dir="ltr" autocomplete="off" placeholder="https://nshn.ir/…">
		</p>
		<input type="hidden" name="b_loc_lat" id="b_loc_lat"><input type="hidden" name="b_loc_lng" id="b_loc_lng">
	</div>
	<div class="b-deliv b-deliv--pickup" hidden>
		<h3>تحویل حضوری از فروشگاه</h3>
		<dl>
			<div><dt>آدرس</dt><dd><?php echo esc_html( boutique_shop_address() ); ?></dd></div>
			<div><dt>ساعات کاری</dt><dd><?php echo esc_html( boutique_shop_hours() ); ?></dd></div>
		</dl>
		<p>لطفاً قبل از آمدن با <a href="tel:<?php echo esc_attr( boutique_shop_phone() ); ?>"><bdi dir="ltr"><?php echo esc_html( boutique_fa_digits( boutique_shop_phone() ) ); ?></bdi></a> تماس بگیرید تا سفارشتان آماده باشد. پرو در فروشگاه ممکن است.</p>
	</div>
	<?php
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) { return; }
	$f = BOUTIQUE_ADMIN_DIR . '/assets/checkout.js';
	wp_enqueue_script( 'boutique-checkout', BOUTIQUE_ADMIN_URL . 'assets/checkout.js', [ 'jquery', 'wc-checkout' ], filemtime( $f ), true );
} );

// Pickup: no street address or postcode needed.
// (Only while the order is being placed; the form itself always renders the fields, and
// checkout.js hides them when pickup is chosen.)
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	$chosen = (array) wp_unslash( $_POST['shipping_method'] ?? [] );
	if ( empty( $_POST['woocommerce-process-checkout-nonce'] ) || ! $chosen
		|| ! preg_match( '/^(local_pickup|pickup_location)/', (string) reset( $chosen ) ) ) {
		return $fields;
	}
	foreach ( [ 'billing_address_1', 'billing_address_2', 'billing_postcode' ] as $k ) {
		if ( isset( $fields['billing'][ $k ] ) ) {
			$fields['billing'][ $k ]['required'] = false;
			$fields['billing'][ $k ]['validate'] = [];
		}
	}
	return $fields;
}, 1000 );

// Courier: keep the location on the order.
add_action( 'woocommerce_checkout_create_order', function ( WC_Order $order ) {
	if ( ! boutique_order_is_courier( $order ) ) { return; }
	$lat  = (float) boutique_latin_digits( wp_unslash( $_POST['b_loc_lat'] ?? '' ) );
	$lng  = (float) boutique_latin_digits( wp_unslash( $_POST['b_loc_lng'] ?? '' ) );
	$link = trim( sanitize_text_field( wp_unslash( $_POST['b_loc_link'] ?? '' ) ) );
	$loc  = ( $lat && $lng ) ? boutique_parse_location( "$lat,$lng" ) : null;
	if ( ! $loc && $link ) { $loc = boutique_parse_location( $link ); }
	if ( $loc ) { $order->update_meta_data( '_boutique_loc', $loc[0] . ',' . $loc[1] ); }
	if ( $link && preg_match( '#^https?://#i', $link ) ) { $order->update_meta_data( '_boutique_loc_link', esc_url_raw( $link ) ); }
} );

// Thank-you page: pickup orders get the address and what happens next.
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$o = $order_id ? wc_get_order( $order_id ) : null;
	if ( ! $o || ! boutique_order_is_pickup( $o ) ) { return; }
	echo '<div class="b-deliv b-deliv--pickup"><h3>تحویل حضوری</h3><dl><div><dt>آدرس</dt><dd>' . esc_html( boutique_shop_address() )
		. '</dd></div><div><dt>ساعات کاری</dt><dd>' . esc_html( boutique_shop_hours() ) . '</dd></div></dl>'
		. '<p>لطفاً قبل از آمدن با <a href="tel:' . esc_attr( boutique_shop_phone() ) . '"><bdi dir="ltr">' . esc_html( boutique_fa_digits( boutique_shop_phone() ) ) . '</bdi></a> تماس بگیرید.</p></div>';
}, 6 );

/* ------------------------------------------------- Owner side */

/** Map buttons + "copy for the courier" for one order (Today screen and order page). */
function boutique_location_actions( WC_Order $o ) {
	$loc  = boutique_order_location( $o );
	$link = (string) $o->get_meta( '_boutique_loc_link' );
	$map  = $loc ? boutique_map_links( $loc[0], $loc[1] )['neshan'] : $link;
	$copy = implode( "\n", array_filter( [
		trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
		$o->get_billing_phone(),
		trim( wp_strip_all_tags( str_replace( '<br/>', '، ', $o->get_formatted_shipping_address() ?: $o->get_formatted_billing_address() ) ) ),
		$map ? 'لوکیشن: ' . $map : '',
	] ) );
	echo '<div class="b-loc">';
	if ( $loc ) {
		$l = boutique_map_links( $loc[0], $loc[1] );
		echo '<a class="b-btn b-btn--quiet" href="' . esc_url( $l['neshan'] ) . '" target="_blank" rel="noopener">باز کردن در نشان</a>';
		echo '<a class="b-btn b-btn--quiet" href="' . esc_url( $l['google'] ) . '" target="_blank" rel="noopener">گوگل‌مپ</a>';
	} elseif ( $link ) {
		echo '<a class="b-btn b-btn--quiet" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">لینک لوکیشن مشتری</a>';
	} else {
		echo '<span class="b-chip b-chip--warn">لوکیشن نفرستاده؛ قبل از ارسال تماس بگیرید</span>';
	}
	echo '<button type="button" class="b-btn b-btn--quiet b-copy" data-copy="' . esc_attr( $copy ) . '">کپی برای پیک</button>';
	echo '</div>';
}

// The full order page (WooCommerce's own) shows the location too.
add_action( 'woocommerce_admin_order_data_after_shipping_address', function ( $o ) {
	if ( $o instanceof WC_Order && boutique_order_is_courier( $o ) ) {
		echo '<p><strong>لوکیشن پیک:</strong></p>';
		boutique_location_actions( $o );
	}
} );

// «کپی برای پیک» button (Today screen + order page).
add_action( 'admin_footer', function () {
	?>
	<script>
	document.addEventListener('click', function (e) {
		var b = e.target.closest('.b-copy'); if (!b) return;
		var t = b.getAttribute('data-copy'), done = function () { var o = b.textContent; b.textContent = 'کپی شد ✓'; setTimeout(function () { b.textContent = o; }, 1800); };
		if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done); return; }
		var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); done();
	});
	</script>
	<?php
} );

/* ------------------------------------------------- Checkout: terms in a dialog */
// WooCommerce unfolds the whole terms page inside the checkout form; show it in a dialog instead.
add_action( 'wp_loaded', function () {
	remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );
} );
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) { return; }
	$page = get_post( wc_terms_and_conditions_page_id() );
	if ( ! $page ) { return; }
	echo '<dialog class="b-terms" id="b-terms" aria-labelledby="b-terms-title"><div class="b-terms__head"><h2 id="b-terms-title">'
		. esc_html( $page->post_title ) . '</h2><button type="button" class="b-terms__close" aria-label="بستن">×</button></div><div class="b-terms__body">'
		. wc_format_content( wp_kses_post( $page->post_content ) ) . '</div></dialog>';
} );

/* ------------------------------------------------- Cart: full address from the address dialog */
// WooCommerce's cart calculator only knows province/city/postcode; our dialog adds the street.
add_action( 'woocommerce_calculated_shipping', function () {
	if ( ! isset( $_POST['calc_shipping_address_1'] ) || ! WC()->customer ) { return; }
	$street = sanitize_text_field( wp_unslash( $_POST['calc_shipping_address_1'] ) );
	WC()->customer->set_shipping_address_1( $street );
	WC()->customer->set_billing_address_1( $street );   // checkout starts filled in
	WC()->customer->save();
} );

// Open/close the address dialog (the cart totals are re-rendered by AJAX, so delegate).
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) { return; }
	?>
	<script>
	document.addEventListener('click', function (e) {
		var open = e.target.closest('.b-addr-open');
		if (open) { var d = open.parentNode.querySelector('dialog.b-addr'); if (d && d.showModal) { e.preventDefault(); d.showModal(); } return; }
		var d2 = e.target.closest('dialog.b-addr');
		if (d2 && (e.target.closest('.b-terms__close') || e.target === d2)) d2.close();
	});
	document.addEventListener('submit', function (e) {
		var d = e.target.closest('dialog.b-addr'); if (d) setTimeout(function () { d.close(); }, 0);
	}, true);
	</script>
	<?php
} );


/* ------------------------------------------------- Short note under each delivery option */
add_action( 'woocommerce_after_shipping_rate', function ( $rate ) {
	if ( boutique_is_courier_rate( $rate ) ) {
		$note = apply_filters( 'boutique_courier_note', 'تحویل فوری، معمولاً همان روز · کرایه هنگام تحویل با شما' );
	} elseif ( in_array( $rate->get_method_id(), [ 'local_pickup', 'pickup_location' ], true ) ) {
		$note = 'رایگان · قبل از آمدن تماس بگیرید';
	} else {
		return;
	}
	echo '<small class="b-rate-note">' . esc_html( $note ) . '</small>';
} );


/* ------------------------------------------------- Free shipping over a set amount */
/** Threshold in toman (editable on «هزینه ارسال»); 0 = no free shipping. */
function boutique_free_ship_min() {
	return (int) get_option( 'boutique_free_ship_min', 6000000 );
}

/** "۶ میلیون تومان" for round millions, else "۶,۵۰۰,۰۰۰ تومان". */
function boutique_money_short( $amount ) {
	$amount = (int) $amount;
	return ( $amount >= 1000000 && 0 === $amount % 1000000 ) ? boutique_fa_digits( $amount / 1000000 ) . ' میلیون تومان' : boutique_money( $amount );
}

/** Cart value that counts toward free shipping: items after discount codes, without shipping. */
function boutique_cart_value() {
	return WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0.0;
}

// Over the threshold every delivery option is free, including Snapp (the shop pays the rider).
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	$min = boutique_free_ship_min();
	if ( ! $min || (float) ( $package['contents_cost'] ?? 0 ) < $min ) { return $rates; }
	foreach ( $rates as $rate ) {
		$rate->set_cost( 0 );
		$rate->set_taxes( [] );
		if ( boutique_is_courier_rate( $rate ) ) {
			$rate->set_label( str_replace( '(پس‌کرایه)', '(رایگان)', $rate->get_label() ) );
		}
	}
	return $rates;
}, 30, 2 );

// Courier note under the option changes too when it's free.
add_filter( 'boutique_courier_note', function ( $note ) {
	$min = boutique_free_ship_min();
	return ( $min && boutique_cart_value() >= $min ) ? 'تحویل فوری، معمولاً همان روز · کرایه با ما' : $note;
} );

/** "Add X more for free shipping" / "this order ships free", with a progress bar. */
function boutique_free_ship_bar() {
	$min = boutique_free_ship_min();
	if ( ! $min || ! WC()->cart || WC()->cart->is_empty() ) { return ''; }
	$have = boutique_cart_value();
	$pct  = min( 100, (int) floor( $have / $min * 100 ) );
	if ( $have >= $min ) {
		$text = '<strong>ارسال این سفارش رایگان است.</strong> با هر روشی که انتخاب کنید.';
	} else {
		$text = 'با افزودن <strong>' . esc_html( boutique_money( $min - $have ) ) . '</strong> دیگر به سبد، ارسال سفارش‌تان <strong>رایگان</strong> می‌شود.';
	}
	return '<div class="b-freeship' . ( $have >= $min ? ' is-done' : '' ) . '" role="status"><p>' . $text . '</p>'
		. '<span class="b-freeship__bar" aria-hidden="true"><span style="width:' . $pct . '%"></span></span></div>';
}
// Cart page (inside the totals box, so it updates when quantities change).
add_action( 'woocommerce_before_cart_totals', function () { echo boutique_free_ship_bar(); } );
// Checkout (inside the order review, which refreshes with the address and delivery choice).
add_action( 'woocommerce_review_order_before_shipping', function () {
	$bar = boutique_free_ship_bar();
	if ( $bar ) { echo '<tr class="b-freeship-row"><td colspan="2">' . $bar . '</td></tr>'; }
} );
// Mini-cart that opens from the header.
add_action( 'woocommerce_widget_shopping_cart_before_buttons', function () { echo boutique_free_ship_bar(); } );
