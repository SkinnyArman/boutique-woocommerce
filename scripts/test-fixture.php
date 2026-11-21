<?php
/**
 * Test fixture for the end-to-end suite (tests/). Not for production.
 *   create: one known dress «لباس تست خودکار» with fixed prices/stock; prints its ids as JSON.
 *   delete: removes that dress, anything named «تست خودکار…», their orders, and test colors.
 */
$cmd = $args[0] ?? 'create';

$cleanup = function () {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'تست خودکار' ] );
	$all = $ids;
	foreach ( $ids as $id ) { $all = array_merge( $all, wc_get_product( $id )->get_children() ); }
	if ( $all ) {
		foreach ( wc_get_orders( [ 'limit' => -1, 'status' => 'any' ] ) as $o ) {
			foreach ( $o->get_items() as $item ) {
				if ( in_array( $item->get_product_id(), $all, true ) || in_array( $item->get_variation_id(), $all, true ) ) { $o->delete( true ); break; }
			}
		}
	}
	foreach ( $ids as $id ) { wc_get_product( $id )->delete( true ); }
	foreach ( get_posts( [ 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'e2etest' ] ) as $cid ) { wp_delete_post( $cid, true ); }
	foreach ( get_terms( [ 'taxonomy' => 'pa_color', 'hide_empty' => false, 'search' => 'رنگ‌تست' ] ) as $t ) { wp_delete_term( $t->term_id, 'pa_color' ); }
};
$cleanup();
if ( 'delete' === $cmd ) { echo "deleted\n"; return; }

// Two existing photos, one per color.
$donor = wc_get_products( [ 'type' => 'variable', 'limit' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] )[0];
$imgs  = array_values( array_filter( array_merge( [ $donor->get_image_id() ], $donor->get_gallery_image_ids() ) ) );
$img_a = $imgs[0];
$img_b = $imgs[1] ?? $imgs[0];

$term = fn( $slug, $tax ) => get_term_by( 'slug', $slug, $tax );
$p = new WC_Product_Variable();
$p->set_name( 'لباس تست خودکار' );
$p->set_status( 'publish' );
$p->set_category_ids( [ $term( 'evening', 'product_cat' )->term_id ] );
$p->set_image_id( $img_a );
$p->set_gallery_image_ids( [ $img_b ] );
$attrs = [];
foreach ( [ 'color' => [ 'black', 'maroon' ], 'size' => [ '38', '40' ] ] as $slug => $opts ) {
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
	$a->set_name( "pa_$slug" );
	$a->set_options( array_map( fn( $o ) => $term( $o, "pa_$slug" )->term_id, $opts ) );
	$a->set_visible( true );
	$a->set_variation( true );
	$attrs[] = $a;
}
$p->set_attributes( $attrs );
$pid = $p->save();
update_post_meta( $pid, '_boutique_material', 'ساتن' );
update_post_meta( $pid, '_boutique_size_guide', [ 'rows' => [ '38' => [ 'bust' => '88', 'hip' => '96' ], '40' => [ 'bust' => '92', 'hip' => '100' ] ], 'note' => 'قالب کمی کوچک است.' ] );

$stock = [ 'black|38' => 3, 'black|40' => 0, 'maroon|38' => 2, 'maroon|40' => 2 ];
$out   = [ 'id' => $pid, 'url' => '', 'price' => 800000, 'vars' => [] ];
foreach ( $stock as $key => $q ) {
	[ $c, $s ] = explode( '|', $key );
	$v = new WC_Product_Variation();
	$v->set_parent_id( $pid );
	$v->set_attributes( [ 'pa_color' => $c, 'pa_size' => $s ] );
	$v->set_regular_price( '1000000' );
	$v->set_sale_price( '800000' );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( $q );
	$v->set_image_id( 'black' === $c ? $img_a : $img_b );
	$v->save();
	$out['vars'][ $key ] = $v->get_id();
}
WC_Product_Variable::sync( $pid );
wc_delete_product_transients( $pid );
$out['url'] = get_permalink( $pid );
echo wp_json_encode( $out ), "\n";
