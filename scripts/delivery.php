<?php
/**
 * Delivery setup (idempotent; run after store.php):
 *  - marks the courier method so it's offered only inside the city of Tehran,
 *  - offers «تحویل حضوری» everywhere (anyone may come to the shop),
 *  - courier is «پس‌کرایه» (customer pays the rider), no «پرداخت در محل» (no card reader).
 *
 * Run: docker compose --profile cli run --rm wpcli eval-file /scripts/delivery.php
 */
$courier = 0;
$zones   = WC_Shipping_Zones::get_zones();
foreach ( $zones as $z ) {
	foreach ( $z['shipping_methods'] as $m ) {
		if ( 'flat_rate' === $m->id && str_contains( (string) $m->get_option( 'title' ), 'پیک' ) ) {
			$courier = $m->get_instance_id();
			$s       = (array) get_option( $m->get_instance_option_key(), [] );
			$s['title'] = 'اسنپ پیک (پس‌کرایه)';
			$s['cost']  = '0';   // the customer pays the rider on delivery
			update_option( $m->get_instance_option_key(), $s );
		}
	}
}
update_option( 'boutique_courier_instance', $courier );
WP_CLI::log( "Courier method: flat_rate:$courier" );

foreach ( $zones as $z ) {
	$has = array_filter( $z['shipping_methods'], fn( $m ) => 'local_pickup' === $m->id );
	if ( ! $has ) {
		$zone = new WC_Shipping_Zone( $z['id'] );
		$iid  = $zone->add_shipping_method( 'local_pickup' );
		update_option( "woocommerce_local_pickup_{$iid}_settings", [ 'title' => 'تحویل حضوری از فروشگاه', 'cost' => '0', 'tax_status' => 'none' ] );
		WP_CLI::log( "Pickup added to zone {$z['zone_name']}" );
	}
}

// No card reader: no paying on delivery. Card-to-card now, bank gateway later.
// Tipax inside Tehran too (the shop's city zone = the zone that has the courier).
foreach ( $zones as $z ) {
	$has_courier = (bool) array_filter( $z['shipping_methods'], fn( $m ) => $m->get_instance_id() == $courier );
	$has_tipax   = (bool) array_filter( $z['shipping_methods'], fn( $m ) => 'flat_rate' === $m->id && str_contains( (string) $m->get_option( 'title' ), 'تیپاکس' ) );
	if ( $has_courier && ! $has_tipax ) {
		$zone = new WC_Shipping_Zone( $z['id'] );
		$iid  = $zone->add_shipping_method( 'flat_rate' );
		update_option( "woocommerce_flat_rate_{$iid}_settings", [ 'title' => 'تیپاکس', 'cost' => '180000', 'tax_status' => 'none' ] );
		WP_CLI::log( "Tipax added to zone {$z['zone_name']}" );
	}
}

$cod = (array) get_option( 'woocommerce_cod_settings', [] );
$cod['enabled'] = 'no';
update_option( 'woocommerce_cod_settings', $cod );
// Card-to-card: the card and the receipt choice appear after the order (inc/receipts.php).
$bacs = (array) get_option( 'woocommerce_bacs_settings', [] );
$bacs['description']  = 'بعد از ثبت سفارش، شماره کارت را می‌بینید. رسید را همان‌جا آپلود کنید یا در تلگرام / واتساپ / بله بفرستید. سفارش بعد از تأیید واریز ارسال می‌شود.';
$bacs['instructions'] = '';
update_option( 'woocommerce_bacs_settings', $bacs );

WC_Cache_Helper::get_transient_version( 'shipping', true );
WP_CLI::success( 'Delivery set up.' );
