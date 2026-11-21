<?php
/**
 * Apply channel changes to an existing shop without resetting stock or the owner's edits
 * (safe for the live site, unlike a full import-products.php run).
 *
 *   wp eval-file sync-changes.php remove=3185,3222 prices=3189,3194
 *
 * remove=  posts deleted from the Telegram channel: their products go to the trash (restorable).
 * prices=  posts whose price changed: every size/color gets the price from products.json.
 *          Stock, sizes and any sale price stay as they are.
 * New posts: import-products.php only=3352,3357,...
 */
$data = json_decode( (string) file_get_contents( __DIR__ . '/products.json' ), true );
$opt  = [];
foreach ( $args ?? [] as $a ) {
	[ $k, $v ] = array_pad( explode( '=', $a, 2 ), 2, '' );
	$opt[ $k ] = array_filter( array_map( 'intval', explode( ',', $v ) ) );
}
$by_post = function ( $post ) {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
		'meta_key' => '_boutique_tg_post', 'meta_value' => (string) $post ] );
	return $ids ? wc_get_product( $ids[0] ) : null;
};

foreach ( $opt['remove'] ?? [] as $post ) {
	$p = $by_post( $post );
	if ( ! $p ) { WP_CLI::log( "remove #$post: not on this site" ); continue; }
	wp_trash_post( $p->get_id() );
	WP_CLI::log( "trashed  #$post " . $p->get_name() );
}

foreach ( $opt['prices'] ?? [] as $post ) {
	$row = current( array_filter( $data, fn( $r ) => (int) $r['post'] === $post ) );
	$p   = $by_post( $post );
	if ( ! $row || ! $p || empty( $row['price'] ) ) { WP_CLI::warning( "price #$post: product or price missing" ); continue; }
	$ids = $p->is_type( 'variable' ) ? $p->get_children() : [ $p->get_id() ];
	foreach ( $ids as $id ) {
		$v = wc_get_product( $id );
		$v->set_regular_price( (string) $row['price'] );
		$v->save();
	}
	if ( $p->is_type( 'variable' ) ) { WC_Product_Variable::sync( $p->get_id() ); }
	wc_delete_product_transients( $p->get_id() );
	WP_CLI::log( "price    #$post " . $p->get_name() . ' -> ' . $row['price'] );
}
WP_CLI::success( 'Done.' );
