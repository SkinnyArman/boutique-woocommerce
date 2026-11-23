<?php
/**
 * «لباس جدید / ویرایش لباس»: one-page product form for a non-technical shop owner.
 * Photos -> name, brand, fabric & category -> price -> colors & sizes -> stock grid -> size guide -> description -> publish.
 * Builds a WooCommerce variable product (pa_color × pa_size) behind the scenes.
 */
defined( 'ABSPATH' ) || exit;

/** Size-guide columns (cm). A column left empty on every row isn't shown to customers. */
const BOUTIQUE_GUIDE_COLS = [ 'bust' => 'دور سینه', 'waist' => 'دور کمر', 'hip' => 'دور باسن', 'length' => 'قد لباس' ];

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=product', 'لباس جدید', boutique_is_manager() ? 'افزودن لباس' : 'لباس جدید (ساده)', 'edit_products', 'boutique-product', 'boutique_product_page', 1 );
	if ( boutique_is_manager() ) {
		remove_submenu_page( 'edit.php?post_type=product', 'post-new.php?post_type=product' );
	}
} );

function boutique_product_url( $id = 0 ) {
	return add_query_arg( array_filter( [ 'page' => 'boutique-product', 'product' => (int) $id ] ), admin_url( 'admin.php' ) );
}

/** WooCommerce's own editor, bypassing our link filter. */
function boutique_classic_edit_url( $id ) {
	return admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
}

/* ---------------------------------------------- Route the manager here */
add_action( 'load-post-new.php', function () {
	if ( boutique_is_manager() && 'product' === ( $_GET['post_type'] ?? '' ) ) {
		wp_safe_redirect( boutique_product_url() );
		exit;
	}
} );

add_filter( 'get_edit_post_link', function ( $link, $post_id ) {
	// Not inside the classic editor itself: it uses this link to redirect after saving.
	if ( boutique_is_manager() && 'product' === get_post_type( $post_id ) && 'post.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
		$p = wc_get_product( $post_id );
		if ( $p && ( $p->is_type( 'variable' ) || $p->is_type( 'simple' ) ) ) {
			return boutique_product_url( $post_id );
		}
	}
	return $link;
}, 10, 2 );

// Row action for admins too.
add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( 'product' === $post->post_type && current_user_can( 'edit_product', $post->ID ) ) {
		$actions = [ 'b_simple' => '<a href="' . esc_url( boutique_product_url( $post->ID ) ) . '">ویرایش ساده</a>' ] + $actions;
	}
	return $actions;
}, 10, 2 );

add_action( 'admin_enqueue_scripts', function () {
	if ( 'boutique-product' === ( $_GET['page'] ?? '' ) ) {
		wp_enqueue_media();
		wp_enqueue_script( 'boutique-product', BOUTIQUE_ADMIN_URL . 'assets/product-editor.js', [ 'jquery' ], filemtime( BOUTIQUE_ADMIN_DIR . '/assets/product-editor.js' ), true );
	}
} );

/** Terms of an attribute taxonomy, in the shop's order. */
function boutique_attr_terms( $tax ) {
	if ( ! taxonomy_exists( $tax ) ) { return []; }
	$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
	if ( is_wp_error( $terms ) ) { return []; }
	usort( $terms, fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'order', true ) <=> (int) get_term_meta( $b->term_id, 'order', true ) );
	return array_map( fn( $t ) => [ 'id' => $t->term_id, 'name' => $t->name ], $terms );
}

/** Find or create a term by name; returns term_id. */
function boutique_ensure_term( $name, $tax ) {
	$name = trim( wp_strip_all_tags( $name ) );
	if ( '' === $name ) { return 0; }
	$t = get_term_by( 'name', $name, $tax );
	if ( $t ) { return $t->term_id; }
	$r = wp_insert_term( $name, $tax );
	if ( is_wp_error( $r ) ) {
		return (int) ( $r->get_error_data( 'term_exists' ) ?: 0 );
	}
	update_term_meta( $r['term_id'], 'order', 100 + (int) $r['term_id'] );
	return $r['term_id'];
}

/* -------------------------------------------------------------- Save */
add_action( 'admin_post_boutique_product_save', function () {
	check_admin_referer( 'boutique_product_save' );
	if ( ! current_user_can( 'edit_products' ) ) { wp_die( 'دسترسی ندارید.' ); }

	$id   = absint( $_POST['product_id'] ?? 0 );
	$num  = fn( $k ) => (float) preg_replace( '/[^\d.]/', '', boutique_latin_digits( wp_unslash( $_POST[ $k ] ?? '' ) ) );
	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$reg  = $num( 'price' );
	$sale = $num( 'sale_price' );
	$return = boutique_products_back( $_POST['back'] ?? null );   // the «لباس‌ها» page she came from
	$back   = fn( $args ) => wp_safe_redirect( add_query_arg( $args + ( $return ? [ 'back' => $return ] : [] ), boutique_product_url( $id ) ) );
	// «استعلام قیمت»: no price shown, not buyable online. The price boxes are disabled (so not sent)
	// and any price already saved is kept for when the box is unticked.
	$ask = ! empty( $_POST['ask_price'] );

	if ( '' === $name || ( $reg <= 0 && ! $ask ) ) { $back( [ 'err' => 'required' ] ); exit; }
	if ( $sale >= $reg ) { $sale = 0; }

	// Colors / sizes: values are term ids or "new:<name>".
	$new_ids = []; // "new:<name>" -> term id, so stock keys typed before saving still match.
	$resolve = function ( $list, $tax ) use ( &$new_ids ) {
		$ids = [];
		foreach ( (array) $list as $v ) {
			$v = wp_unslash( $v );
			if ( str_starts_with( $v, 'new:' ) ) {
				$new_ids[ $v ] = boutique_ensure_term( substr( $v, 4 ), $tax );
				$ids[]         = $new_ids[ $v ];
			} else {
				$ids[] = absint( $v );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	};
	$colors = $resolve( $_POST['colors'] ?? [], 'pa_color' );
	$sizes  = $resolve( $_POST['sizes'] ?? [], 'pa_size' );

	$existing = $id ? wc_get_product( $id ) : null;
	$variable = $colors || $sizes;
	if ( $existing && $existing->is_type( 'variable' ) !== $variable ) {
		$back( [ 'err' => 'type' ] ); exit;
	}
	$p = $existing ?: ( $variable ? new WC_Product_Variable() : new WC_Product_Simple() );

	$p->set_name( $name );
	$desc = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
	$p->set_description( $desc );
	$p->set_short_description( wp_trim_words( $desc, 30, '…' ) );
	$p->set_category_ids( array_map( 'absint', (array) ( $_POST['cats'] ?? [] ) ) );
	$imgs = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $_POST['images'] ?? '' ) ) ) ) );
	$p->set_image_id( $imgs[0] ?? 0 );
	$p->set_gallery_image_ids( array_slice( $imgs, 1 ) );
	$p->set_status( 'publish' === ( $_POST['status'] ?? '' ) ? 'publish' : 'draft' );

	// Stock keyed "colorId|sizeId" (0 when that dimension isn't used).
	$stock = [];
	foreach ( (array) ( $_POST['stock'] ?? [] ) as $key => $q ) {
		$parts = array_map( fn( $part ) => (string) ( $new_ids[ $part ] ?? $part ), explode( '|', wp_unslash( $key ), 2 ) );
		$stock[ implode( '|', array_map( 'absint', $parts ) ) ] = max( 0, (int) boutique_latin_digits( wp_unslash( $q ) ) );
	}

	// Photo -> color tags. First photo tagged with a color becomes that color's variation image.
	$photo_colors = [];
	foreach ( (array) json_decode( wp_unslash( $_POST['photo_colors'] ?? '{}' ), true ) as $att => $c ) {
		$att = absint( $att );
		$c   = (int) ( $new_ids[ $c ] ?? absint( $c ) );
		if ( $att && $c && in_array( $att, $imgs, true ) && in_array( $c, $colors, true ) ) { $photo_colors[ $att ] = $c; }
	}
	$color_img = [];
	foreach ( $imgs as $att ) {
		$c = $photo_colors[ $att ] ?? 0;
		if ( $c && ! isset( $color_img[ $c ] ) ) { $color_img[ $c ] = $att; }
	}

	if ( ! $variable ) {
		if ( $reg > 0 ) {
			$p->set_regular_price( (string) $reg );
			$p->set_sale_price( $sale ? (string) $sale : '' );
		}
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock['0|0'] ?? 0 );
		$id = $p->save();
	} else {
		$attrs = [];
		foreach ( [ 'color' => $colors, 'size' => $sizes ] as $slug => $ids ) {
			if ( ! $ids ) { continue; }
			$a = new WC_Product_Attribute();
			$a->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
			$a->set_name( 'pa_' . $slug );
			$a->set_options( $ids );
			$a->set_visible( true );
			$a->set_variation( true );
			$attrs[] = $a;
		}
		$p->set_attributes( $attrs );
		$first_color = $colors ? get_term( $colors[0] )->slug : '';
		$p->set_default_attributes( $first_color ? [ 'pa_color' => $first_color ] : [] );
		$id = $p->save();

		// Existing variations keyed the same way.
		$have = [];
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) { continue; }
			$a  = $v->get_attributes();
			$c  = isset( $a['pa_color'] ) ? ( get_term_by( 'slug', $a['pa_color'], 'pa_color' )->term_id ?? 0 ) : 0;
			$s  = isset( $a['pa_size'] ) ? ( get_term_by( 'slug', $a['pa_size'], 'pa_size' )->term_id ?? 0 ) : 0;
			$have[ "$c|$s" ] = $v;
		}

		$keep = [];
		foreach ( ( $colors ?: [ 0 ] ) as $c ) {
			foreach ( ( $sizes ?: [ 0 ] ) as $s ) {
				$key = "$c|$s";
				$v   = $have[ $key ] ?? new WC_Product_Variation();
				$v->set_parent_id( $id );
				$va = [];
				if ( $c ) { $va['pa_color'] = get_term( $c )->slug; }
				if ( $s ) { $va['pa_size'] = get_term( $s )->slug; }
				$v->set_attributes( $va );
				if ( $reg > 0 ) {
					$v->set_regular_price( (string) $reg );
					$v->set_sale_price( $sale ? (string) $sale : '' );
				}
				$v->set_manage_stock( true );
				$v->set_stock_quantity( $stock[ $key ] ?? 0 );
				$v->set_image_id( $color_img[ $c ] ?? 0 ); // 0 = fall back to the main photo
				$v->set_status( 'publish' );
				$v->save();
				$keep[ $key ] = true;
			}
		}
		// Combinations that were unticked go away (past orders keep their own copy of the name).
		foreach ( $have as $key => $v ) {
			if ( ! isset( $keep[ $key ] ) ) { $v->delete( true ); }
		}
		WC_Product_Variable::sync( $id );
	}
	update_post_meta( $id, '_boutique_photo_colors', $photo_colors );
	$ask ? update_post_meta( $id, '_boutique_ask_price', 'yes' ) : delete_post_meta( $id, '_boutique_ask_price' );
	// Fabric (optional).
	$material = trim( sanitize_text_field( wp_unslash( $_POST['material'] ?? '' ) ) );
	$material ? update_post_meta( $id, '_boutique_material', $material ) : delete_post_meta( $id, '_boutique_material' );

	// Size guide (optional): one row per size, measurements in cm (ranges like ۹۰-۹۴ allowed).
	$guide = [];
	foreach ( (array) ( $_POST['guide'] ?? [] ) as $size => $vals ) {
		$row = [];
		foreach ( BOUTIQUE_GUIDE_COLS as $k => $label ) {
			$v = trim( sanitize_text_field( boutique_latin_digits( wp_unslash( $vals[ $k ] ?? '' ) ) ) );
			if ( '' !== $v ) { $row[ $k ] = mb_substr( $v, 0, 12 ); }
		}
		if ( $row ) { $guide[ sanitize_text_field( wp_unslash( $size ) ) ] = $row; }
	}
	$note = trim( sanitize_textarea_field( wp_unslash( $_POST['guide_note'] ?? '' ) ) );
	( $guide || $note ) ? update_post_meta( $id, '_boutique_size_guide', [ 'rows' => $guide, 'note' => $note ] ) : delete_post_meta( $id, '_boutique_size_guide' );

	// Brand (optional): pick an existing one or type a new name; empty removes it.
	if ( taxonomy_exists( 'product_brand' ) ) {
		$brand = trim( sanitize_text_field( wp_unslash( $_POST['brand'] ?? '' ) ) );
		wp_set_object_terms( $id, $brand ? [ $brand ] : [], 'product_brand' );
	}
	wc_delete_product_transients( $id );

	// Back to the list «لباس‌ها», which says it was saved (with links to edit again / add another).
	wp_safe_redirect( boutique_products_page_url( $return + [ 'saved' => $p->get_status(), 'item' => $id ] ) );
	exit;
} );

/* -------------------------------------------------------------- Page */
function boutique_product_page() {
	$id = absint( $_GET['product'] ?? 0 );
	$p  = $id ? wc_get_product( $id ) : null;
	if ( $id && ( ! $p || ! current_user_can( 'edit_product', $id ) ) ) { wp_die( 'لباس پیدا نشد.' ); }

	// Current state for the form.
	$state = [
		'images' => [], 'colors' => [], 'sizes' => [], 'stock' => (object) [],
		'price' => '', 'sale' => '',
		'guide' => (object) [], 'guide_note' => '',
	];
	if ( $p ) {
		$kids = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : [ $p ];
		// Color tags: saved by this form, else inferred from variation images (older products).
		$tags = get_post_meta( $id, '_boutique_photo_colors', true );
		if ( ! is_array( $tags ) || ! $tags ) {
			$tags = [];
			foreach ( $kids as $k ) {
				$a = $k->is_type( 'variation' ) ? $k->get_attributes() : [];
				if ( $k->get_image_id() && isset( $a['pa_color'] ) && ! isset( $tags[ $k->get_image_id() ] ) ) {
					$t = get_term_by( 'slug', $a['pa_color'], 'pa_color' );
					if ( $t ) { $tags[ $k->get_image_id() ] = $t->term_id; }
				}
			}
		}
		foreach ( array_filter( array_merge( [ $p->get_image_id() ], $p->get_gallery_image_ids() ) ) as $att ) {
			$state['images'][] = [ 'id' => (int) $att, 'url' => wp_get_attachment_image_url( $att, 'thumbnail' ), 'color' => isset( $tags[ $att ] ) ? (string) $tags[ $att ] : '' ];
		}
		$stock = [];
		foreach ( $kids as $k ) {
			$a = $k->is_type( 'variation' ) ? $k->get_attributes() : [];
			$c = isset( $a['pa_color'] ) ? ( get_term_by( 'slug', $a['pa_color'], 'pa_color' )->term_id ?? 0 ) : 0;
			$s = isset( $a['pa_size'] ) ? ( get_term_by( 'slug', $a['pa_size'], 'pa_size' )->term_id ?? 0 ) : 0;
			if ( $c ) { $state['colors'][] = (string) $c; }
			if ( $s ) { $state['sizes'][] = (string) $s; }
			$stock[ "$c|$s" ] = (int) $k->get_stock_quantity();
			if ( '' === $state['price'] ) {
				$state['price'] = (float) $k->get_regular_price();
				$state['sale']  = (float) $k->get_sale_price();
			}
		}
		$state['colors'] = array_values( array_unique( $state['colors'] ) );
		$state['sizes']  = array_values( array_unique( $state['sizes'] ) );
		$state['stock']  = (object) $stock;
		$g = get_post_meta( $id, '_boutique_size_guide', true );
		if ( is_array( $g ) ) {
			$state['guide']      = (object) ( $g['rows'] ?? [] );
			$state['guide_note'] = (string) ( $g['note'] ?? '' );
		}
	}
	$cats    = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] );
	$checked = $p ? $p->get_category_ids() : [];
	$err     = sanitize_key( $_GET['err'] ?? '' );
	$return  = boutique_products_back( $_GET['back'] ?? null );
	?>
	<div class="wrap b-wrap b-pe">
		<header class="b-head">
			<div>
				<h1 class="b-title"><?php echo $p ? 'ویرایش لباس' : 'لباس جدید'; ?></h1>
				<p class="b-muted">از بالا به پایین پر کنید. هر وقت خواستید «پیش‌نویس» ذخیره کنید؛ تا «انتشار» نزنید، مشتری آن را نمی‌بیند.</p>
			</div>
			<?php if ( $p ) : ?>
				<div class="b-head__actions">
					<?php if ( 'publish' === $p->get_status() ) : ?><a class="b-btn b-btn--ghost" href="<?php echo esc_url( $p->get_permalink() ); ?>" target="_blank">دیدن در سایت</a><?php endif; ?>
					<a class="b-btn b-btn--quiet" href="<?php echo esc_url( boutique_classic_edit_url( $id ) ); ?>">حالت پیشرفته</a>
					<?php boutique_draft_delete_form( $p, 'editor', $return ); ?>
				</div>
			<?php endif; ?>
		</header>

		<?php if ( 'required' === $err ) : ?>
			<div class="notice notice-error"><p>اسم لباس و قیمت را وارد کنید.</p></div>
		<?php elseif ( 'type' === $err ) : ?>
			<div class="notice notice-error"><p>برای حذف همه رنگ‌ها و سایزهای یک لباس، از «حالت پیشرفته» استفاده کنید.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="b-pe-form" novalidate>
			<?php wp_nonce_field( 'boutique_product_save' ); ?>
			<input type="hidden" name="action" value="boutique_product_save">
			<input type="hidden" name="product_id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="images" id="b-pe-images" value="">
			<input type="hidden" name="photo_colors" id="b-pe-photocolors" value="{}">
			<input type="hidden" name="status" id="b-pe-status" value="publish">
			<?php boutique_products_back_fields( $return ); ?>

			<section class="b-panel">
				<h2>۱. عکس‌ها</h2>
				<p class="b-hint">اولین عکس، عکس اصلی است. عکس عمودی (ایستاده) بهترین نتیجه را می‌دهد.<br>
					اگر عکسی مربوط به یک رنگ خاص است، رنگش را زیر عکس انتخاب کنید؛ وقتی مشتری آن رنگ را بزند، همین عکس نشان داده می‌شود.</p>
				<div class="b-pe__photos" id="b-pe-photos"></div>
			</section>

			<section class="b-panel">
				<h2>۲. اسم و دسته</h2>
				<label class="b-field"><span>اسم لباس</span>
					<input type="text" name="name" id="b-pe-name" required value="<?php echo esc_attr( $p ? $p->get_name() : '' ); ?>" placeholder="مثلاً: پیراهن مجلسی ساتن «نیلوفر»">
				</label>
				<?php if ( taxonomy_exists( 'product_brand' ) ) :
					$brand_now = $p ? wp_get_post_terms( $p->get_id(), 'product_brand', [ 'fields' => 'names' ] ) : [];
					$brands    = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false, 'fields' => 'names' ] ); ?>
					<label class="b-field"><span>برند <em class="b-muted">(اختیاری)</em></span>
						<input type="text" name="brand" id="b-pe-brand" data-suggest="b-pe-brands" dir="auto" autocomplete="off" value="<?php echo esc_attr( $brand_now[0] ?? '' ); ?>" placeholder="مثلاً Vero Moda: از فهرست انتخاب کنید یا بنویسید">
						<datalist id="b-pe-brands"><?php foreach ( is_wp_error( $brands ) ? [] : $brands as $b ) : ?><option value="<?php echo esc_attr( $b ); ?>"><?php endforeach; ?></datalist>
					</label>
				<?php endif; ?>
				<?php
				global $wpdb;
				$materials = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_boutique_material' AND meta_value <> '' ORDER BY meta_value LIMIT 100" ); ?>
				<label class="b-field"><span>جنس <em class="b-muted">(اختیاری)</em></span>
					<input type="text" name="material" id="b-pe-material" data-suggest="b-pe-materials" autocomplete="off" value="<?php echo esc_attr( $p ? get_post_meta( $p->get_id(), '_boutique_material', true ) : '' ); ?>" placeholder="مثلاً: ساتن، لینن، نخ پنبه، کرپ">
					<datalist id="b-pe-materials"><?php foreach ( $materials as $m ) : ?><option value="<?php echo esc_attr( $m ); ?>"><?php endforeach; ?></datalist>
				</label>
				<p class="b-label">دسته</p>
				<div class="b-chips">
					<?php foreach ( $cats as $c ) : ?>
						<label class="b-chipbtn b-chipcheck"><input type="checkbox" name="cats[]" value="<?php echo (int) $c->term_id; ?>" <?php checked( in_array( $c->term_id, $checked, true ) ); ?>> <?php echo esc_html( $c->name ); ?></label>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="b-panel">
				<h2>۳. قیمت</h2>
				<div class="b-pe__prices">
					<label class="b-field"><span>قیمت (تومان)</span>
						<input type="text" inputmode="numeric" name="price" id="b-pe-price" required value="<?php echo esc_attr( $state['price'] ? boutique_fa_digits( number_format( $state['price'] ) ) : '' ); ?>" placeholder="۲٬۵۰۰٬۰۰۰">
					</label>
					<label class="b-field"><span>قیمت حراج (اختیاری)</span>
						<input type="text" inputmode="numeric" name="sale_price" id="b-pe-sale" value="<?php echo esc_attr( $state['sale'] ? boutique_fa_digits( number_format( $state['sale'] ) ) : '' ); ?>" placeholder="خالی = بدون تخفیف">
					</label>
				</div>
				<label class="b-pe__ask"><input type="checkbox" name="ask_price" id="b-pe-ask" value="yes" <?php checked( $p && 'yes' === get_post_meta( $p->get_id(), '_boutique_ask_price', true ) ); ?>>
					<span><strong>استعلام قیمت</strong> — قیمت در سایت نشان داده نمی‌شود و مشتری برای قیمت پیام می‌دهد</span></label>
				<p class="b-hint" id="b-pe-off" aria-live="polite"></p>
			</section>

			<section class="b-panel">
				<h2>۴. رنگ‌ها و سایزها</h2>
				<p class="b-hint">هر رنگ و سایزی که از این لباس دارید را انتخاب کنید. رنگ یا سایز جدید را پایین هر بخش بنویسید.</p>
				<p class="b-label">رنگ‌ها</p>
				<div class="b-chips" id="b-pe-colors"></div>
				<div class="b-pe__add"><input type="text" id="b-pe-newcolor" placeholder="رنگ جدید، مثلاً: یاسی"><button type="button" class="b-btn b-btn--ghost" data-add="colors">افزودن</button></div>
				<p class="b-label">سایزها</p>
				<div class="b-chips" id="b-pe-sizes"></div>
				<div class="b-pe__add"><input type="text" id="b-pe-newsize" placeholder="سایز جدید، مثلاً: فری‌سایز"><button type="button" class="b-btn b-btn--ghost" data-add="sizes">افزودن</button></div>
			</section>

			<section class="b-panel">
				<h2>۵. موجودی</h2>
				<p class="b-hint">از هر رنگ و سایز چند تا دارید؟</p>
				<div id="b-pe-stock" class="b-sp__scroll"></div>
			</section>

			<section class="b-panel">
				<h2>۶. راهنمای سایز <em class="b-muted">(اختیاری)</em></h2>
				<p class="b-hint">اندازه‌های همین لباس به سانتی‌متر. هر خانه‌ای را که نمی‌دانید خالی بگذارید؛ ستون خالی به مشتری نشان داده نمی‌شود. اگر هیچ چیز ننویسید، بخش «راهنمای سایز» برای این لباس نمایش داده نمی‌شود.</p>
				<div id="b-pe-guide" class="b-sp__scroll"></div>
				<label class="b-field"><span>نکته سایز</span>
					<input type="text" name="guide_note" id="b-pe-guidenote" value="" placeholder="مثلاً: قالب کوچک است؛ یک سایز بزرگ‌تر بردارید">
				</label>
			</section>

			<section class="b-panel">
				<h2>۷. توضیحات</h2>
				<label class="b-field"><span class="screen-reader-text">توضیحات</span>
					<textarea name="description" rows="5" placeholder="جنس پارچه، قد لباس، آستر دارد یا نه، نحوه شستشو…"><?php echo esc_textarea( $p ? wp_strip_all_tags( $p->get_description() ) : '' ); ?></textarea>
				</label>
			</section>

			<div class="b-savebar is-dirty">
				<span id="b-pe-msg" role="alert"></span>
				<button type="submit" class="b-btn b-btn--ghost" data-status="draft">ذخیره پیش‌نویس</button>
				<button type="submit" class="b-btn b-btn--big" data-status="publish"><?php echo $p && 'publish' === $p->get_status() ? 'ذخیره تغییرات' : 'انتشار در سایت'; ?></button>
			</div>
		</form>
	</div>
	<script>
	window.BOUTIQUE_PE = <?php echo wp_json_encode( [
		'state'  => $state,
		'colors' => boutique_attr_terms( 'pa_color' ),
		'sizes'  => boutique_attr_terms( 'pa_size' ),
		'guideCols' => BOUTIQUE_GUIDE_COLS,
	], JSON_UNESCAPED_UNICODE ); ?>;
	</script>
	<?php
}
