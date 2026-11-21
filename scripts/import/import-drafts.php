<?php
/**
 * Create DRAFT products from drafts.json (made by channel_drafts.py). Never changes an existing product.
 *
 *   wp eval-file import-drafts.php offset=0 limit=5            # photos downloaded from the Telegram CDN
 *   wp eval-file import-drafts.php offset=0 limit=5 photos=DIR # photos from DIR/<post>-<n>.jpg (local tests)
 *   wp eval-file import-drafts.php undo=1                      # delete every product this script made
 *
 * - Skips a post if ANY product (any status, trash too) already has its Telegram post number.
 * - Every product made here has _boutique_import = channel-2026-11 (for undo / finding them).
 * - Stops starting new products after ~35 s and prints where to continue (the host kills PHP at 60 s).
 */
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const BOUTIQUE_IMPORT_TAG = 'channel-2026-11';
$t0  = microtime( true );
$opt = [];
foreach ( $args ?? [] as $a ) { [ $k, $v ] = array_pad( explode( '=', $a, 2 ), 2, '' ); $opt[ $k ] = $v; }
$out = [ 'made' => [], 'skipped' => [], 'errors' => [], 'next' => null ];

if ( ! empty( $opt['undo'] ) ) {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_boutique_import', 'meta_value' => BOUTIQUE_IMPORT_TAG ] );
	foreach ( $ids as $pid ) {
		$p = wc_get_product( $pid );
		if ( ! $p || 'draft' !== $p->get_status() ) { $out['skipped'][] = $pid; continue; } // owner already published it: keep
		foreach ( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) as $att ) {
			if ( $att && get_post_meta( $att, '_boutique_import', true ) === BOUTIQUE_IMPORT_TAG ) { wp_delete_attachment( $att, true ); }
		}
		foreach ( $p->get_children() as $c ) { wp_delete_post( $c, true ); }
		$p->delete( true );
		$out['made'][] = -$pid;
	}
	echo wp_json_encode( $out ); return;
}

$data = json_decode( (string) file_get_contents( __DIR__ . '/drafts.json' ), true );
if ( ! is_array( $data ) ) { echo wp_json_encode( [ 'errors' => [ 'no drafts.json' ] ] ); return; }
$offset = (int) ( $opt['offset'] ?? 0 );
$limit  = (int) ( $opt['limit'] ?? 5 );
$dir    = $opt['photos'] ?? '';

$cat_names = [
	'pants' => 'شلوار', 'shomiz' => 'شومیز', 'evening' => 'پیراهن مجلسی', 'blouse' => 'بلوز', 'denim-jacket' => 'کت جین',
	'knit-top' => 'تاپ بافت', 'polo-shirt' => 'پولوشرت', 'turkish-dress' => 'پیراهن ترک', 'swimsuit' => 'مایو',
	'babydoll-dress' => 'پیراهن عروسکی', 'bikini' => 'بیکینی', 'jacket' => 'کت', 'sweatshirt' => 'دورس', 'hoodie' => 'هودی',
	'skirt' => 'دامن', 'casual' => 'پیراهن', 'manteau' => 'مانتو', 'shawl' => 'شال', 'hat' => 'کلاه', 'top' => 'تاپ',
	'jumpsuit' => 'سرهمی و اورال', 'tunic' => 'تونیک', 'cardigan' => 'ژاکت و پلیور', 'other' => 'سایر', 'sets' => 'ست',
];
$cat_id = function ( $slug ) use ( $cat_names ) {
	$t = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $t ) { return (int) $t->term_id; }
	$r = wp_insert_term( $cat_names[ $slug ] ?? $slug, 'product_cat', [ 'slug' => $slug ] );
	return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
};
$term = function ( $name, $tax ) {
	$slug = 'pa_size' === $tax && preg_match( '/^[A-Za-z0-9]+$/', $name ) ? strtolower( $name ) : sanitize_title( $name );
	$t    = get_term_by( 'slug', $slug, $tax ) ?: get_term_by( 'name', $name, $tax );
	if ( $t ) { return $t; }
	$r = wp_insert_term( $name, $tax, [ 'slug' => $slug ] );
	return is_wp_error( $r ) ? null : get_term( $r['term_id'], $tax );
};
$photo = function ( $post, $i, $url ) use ( $dir ) {
	$file = "$post-$i.jpg";
	$have = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_boutique_tg_photo', 'meta_value' => $file ] );
	if ( $have ) { return (int) $have[0]; }
	if ( $dir ) {
		if ( ! is_file( "$dir/$file" ) ) { return 0; }
		$tmp = wp_tempnam( $file ); copy( "$dir/$file", $tmp );
	} else {
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) { return $tmp; }
	}
	$id = media_handle_sideload( [ 'name' => "product-$file", 'tmp_name' => $tmp ], 0 );
	if ( is_wp_error( $id ) ) { @unlink( $tmp ); return $id; }
	update_post_meta( $id, '_boutique_tg_photo', $file );
	update_post_meta( $id, '_boutique_import', BOUTIQUE_IMPORT_TAG );
	return (int) $id;
};

for ( $n = $offset; $n < min( count( $data ), $offset + $limit ); $n++ ) {
	if ( microtime( true ) - $t0 > 35 ) { $out['next'] = $n; break; }
	$row  = $data[ $n ];
	$post = (int) $row['post'];
	$exists = get_posts( [ 'post_type' => 'product', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ], 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_boutique_tg_post', 'meta_value' => (string) $post ] );
	if ( $exists ) { $out['skipped'][] = $post; continue; }

	$imgs = [];
	foreach ( array_values( $row['images'] ?? [] ) as $i => $url ) {
		$id = $photo( $post, $i + 1, $url );
		if ( is_wp_error( $id ) ) { $out['errors'][] = "$post photo " . ( $i + 1 ) . ': ' . $id->get_error_message(); continue; }
		if ( $id ) { $imgs[] = $id; }
	}
	if ( ! $imgs && ! empty( $row['images'] ) ) { $out['errors'][] = "$post: no photo could be loaded, not created"; continue; }

	$clean = (string) $row['description'];
	$desc  = $row['problems'] ? '⚠️ پیش از انتشار بررسی کنید (این خط را بعد پاک کنید): ' . implode( '، ', $row['problems'] ) . ( $clean ? "\n\n" . $clean : '' ) : $clean;

	$p = new WC_Product_Variable();
	$p->set_name( $row['name'] );
	$p->set_status( 'draft' );
	$p->set_description( $desc );
	$p->set_short_description( $clean );
	$p->set_category_ids( array_values( array_filter( array_map( $cat_id, $row['categories'] ?? [ $row['category'] ] ) ) ) );
	$p->set_image_id( $imgs[0] ?? 0 );
	$p->set_gallery_image_ids( array_slice( $imgs, 1 ) );
	$p->set_date_created( strtotime( $row['date'] . ' 12:00:00' ) + $post );

	$colors = $row['colors'] ?: [];
	$sizes  = $row['sizes'] ?: [];
	$attrs  = [];
	$cterms = $sterms = [];
	foreach ( array_keys( $colors ) as $c ) { $t = $term( $c, 'pa_color' ); if ( $t ) { $cterms[ $c ] = $t; } }
	foreach ( $sizes as $s ) { $t = $term( $s, 'pa_size' ); if ( $t ) { $sterms[ $s ] = $t; } }
	foreach ( [ 'color' => $cterms, 'size' => $sterms ] as $slug => $terms ) {
		if ( ! $terms ) { continue; }
		$a = new WC_Product_Attribute();
		$a->set_id( wc_attribute_taxonomy_id_by_name( $slug ) ); $a->set_name( 'pa_' . $slug );
		$a->set_options( array_values( wp_list_pluck( $terms, 'term_id' ) ) );
		$a->set_visible( true ); $a->set_variation( true ); $attrs[] = $a;
	}
	$p->set_attributes( $attrs );
	$p->update_meta_data( '_boutique_tg_post', (string) $post );
	$p->update_meta_data( '_boutique_import', BOUTIQUE_IMPORT_TAG );
	if ( ! empty( $row['ask_price'] ) ) { $p->update_meta_data( '_boutique_ask_price', 'yes' ); }
	if ( ! empty( $row['material'] ) ) { $p->update_meta_data( '_boutique_material', $row['material'] ); }
	$pid = $p->save();
	if ( ! empty( $row['brand'] ) && taxonomy_exists( 'product_brand' ) ) { wp_set_object_terms( $pid, [ $row['brand'] ], 'product_brand' ); }

	$combos = [];
	if ( $cterms ) { foreach ( $colors as $c => $cs ) { foreach ( $cs as $s ) { if ( isset( $cterms[ $c ], $sterms[ $s ] ) ) { $combos[] = [ $cterms[ $c ], $sterms[ $s ] ]; } } } }
	else { foreach ( $sterms as $t ) { $combos[] = [ null, $t ]; } }
	foreach ( $combos as [ $c, $s ] ) {
		$v  = new WC_Product_Variation();
		$va = [ 'pa_size' => $s->slug ];
		if ( $c ) { $va['pa_color'] = $c->slug; }
		$v->set_parent_id( $pid );
		$v->set_attributes( $va );
		if ( ! empty( $row['price'] ) ) { $v->set_regular_price( (string) $row['price'] ); }
		$v->set_manage_stock( true );
		$v->set_stock_quantity( empty( $row['sold'] ) ? 1 : 0 );
		$v->set_status( 'publish' );
		$v->save();
	}
	WC_Product_Variable::sync( $pid );
	wc_delete_product_transients( $pid );
	$out['made'][] = $post;
}
if ( null === $out['next'] && $n < count( $data ) ) { $out['next'] = $n; }
$out['total'] = count( $data );
echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
