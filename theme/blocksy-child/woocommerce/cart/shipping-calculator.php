<?php
/**
 * Cart: "change address" as a dialog with the FULL address (province, city, street, postcode),
 * replacing WooCommerce's inline country/state/postcode calculator.
 * Submitting goes through WooCommerce's own AJAX calculator (same form class + nonce), so the
 * delivery options and prices refresh; the street is saved by boutique-admin (delivery.php)
 * and carried into checkout.
 *
 * @var string $button_text
 */
defined( 'ABSPATH' ) || exit;

$c      = WC()->customer;
$states = WC()->countries->get_states( 'IR' ) ?: [];
do_action( 'woocommerce_before_shipping_calculator' ); ?>

<button type="button" class="b-addr-open"><?php echo esc_html( ! empty( $button_text ) ? $button_text : 'وارد کردن آدرس' ); ?></button>

<dialog class="b-terms b-addr" aria-labelledby="b-addr-title">
	<form class="woocommerce-shipping-calculator" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
		<div class="b-terms__head"><h2 id="b-addr-title">آدرس ارسال</h2><button type="button" class="b-terms__close" aria-label="بستن">×</button></div>
		<div class="b-terms__body">
			<input type="hidden" name="calc_shipping_country" id="calc_shipping_country" value="IR">
			<p class="form-row form-row-wide">
				<label for="calc_shipping_state">استان</label>
				<select name="calc_shipping_state" id="calc_shipping_state" required>
					<option value="">انتخاب استان…</option>
					<?php foreach ( $states as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $c->get_shipping_state(), $code ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-wide">
				<label for="calc_shipping_city">شهر</label>
				<input type="text" class="input-text" name="calc_shipping_city" id="calc_shipping_city" value="<?php echo esc_attr( $c->get_shipping_city() ); ?>" placeholder="مثلاً تهران" required>
			</p>
			<p class="form-row form-row-wide">
				<label for="calc_shipping_address_1">خیابان، کوچه، پلاک و واحد</label>
				<input type="text" class="input-text" name="calc_shipping_address_1" id="calc_shipping_address_1" value="<?php echo esc_attr( $c->get_shipping_address_1() ); ?>" placeholder="مثلاً اقدسیه، خیابان ...، پلاک ۴، واحد ۲">
			</p>
			<p class="form-row form-row-wide">
				<label for="calc_shipping_postcode">کد پستی <span class="optional">(اختیاری)</span></label>
				<input type="text" class="input-text" inputmode="numeric" dir="ltr" name="calc_shipping_postcode" id="calc_shipping_postcode" value="<?php echo esc_attr( $c->get_shipping_postcode() ); ?>">
			</p>
			<p class="b-addr__note">هزینه و روش‌های ارسال با این آدرس دوباره حساب می‌شود. پیک فقط داخل شهر تهران است.</p>
			<button type="submit" name="calc_shipping" value="1" class="button">ذخیره آدرس</button>
			<?php wp_nonce_field( 'woocommerce-shipping-calculator', 'woocommerce-shipping-calculator-nonce' ); ?>
		</div>
	</form>
</dialog>
<?php do_action( 'woocommerce_after_shipping_calculator' ); ?>
