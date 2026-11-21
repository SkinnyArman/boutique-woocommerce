<?php
/**
 * «موجودی»: edit stock for every color × size of every dress on one screen.
 * Replaces WooCommerce's buried per-variation stock fields for day-to-day use.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'موجودی انبار', 'موجودی', 'edit_products', 'boutique-stock', 'boutique_stock_page', 'dashicons-clipboard', 4 );
} );

function boutique_stock_url( $product_id = 0 ) {
	$args = [ 'page' => 'boutique-stock' ];
	if ( $product_id ) { $args['product'] = (int) $product_id; }
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Build a color × size matrix for a product.
 * Returns [ rows(color labels), cols(size labels), cells[color][size] => WC_Product|null ].
 */
function boutique_stock_matrix( WC_Product $p ) {
	if ( ! $p->is_type( 'variable' ) ) {
		return [ [ '' ], [ '' ], [ '' => [ '' => $p ] ] ];
	}
	$rows = $cols = $cells = [];
	$order = function ( $tax, $slug ) {
		$t = get_term_by( 'slug', $slug, $tax );
		return $t ? [ (int) get_term_meta( $t->term_id, 'order', true ), $t->name ] : [ 999, $slug ];
	};
	foreach ( array_filter( array_map( 'wc_get_product', $p->get_children() ) ) as $v ) {
		$a = $v->get_attributes();
		[ $ro, $r ] = isset( $a['pa_color'] ) ? $order( 'pa_color', $a['pa_color'] ) : [ 0, '' ];
		[ $co, $c ] = isset( $a['pa_size'] ) ? $order( 'pa_size', $a['pa_size'] ) : [ 0, boutique_variation_label( $v ) ];
		$rows[ $r ] = $ro;
		$cols[ $c ] = $co;
		$cells[ $r ][ $c ] = $v;
	}
	asort( $rows );
	asort( $cols );
	return [ array_keys( $rows ), array_keys( $cols ), $cells ];
}

/** Total pieces and number of sold-out cells. */
function boutique_stock_summary( WC_Product $p ) {
	$total = $out = 0;
	[ , , $cells ] = boutique_stock_matrix( $p );
	foreach ( $cells as $row ) {
		foreach ( $row as $v ) {
			if ( ! $v || ! $v->managing_stock() ) { continue; }
			$q      = (int) $v->get_stock_quantity();
			$total += max( 0, $q );
			$out   += $q <= 0 ? 1 : 0;
		}
	}
	return [ $total, $out ];
}

/* --------------------------------------------------------------- Save */
add_action( 'admin_post_boutique_stock_save', function () {
	check_admin_referer( 'boutique_stock_save' );
	if ( ! current_user_can( 'edit_products' ) ) { wp_die( 'دسترسی ندارید.' ); }

	$changed = 0;
	foreach ( (array) ( $_POST['stock'] ?? [] ) as $id => $val ) {
		$val = trim( boutique_latin_digits( wp_unslash( $val ) ) );
		if ( '' === $val || ! is_numeric( $val ) ) { continue; }
		$p = wc_get_product( absint( $id ) );
		if ( ! $p || ! ( $p->is_type( 'variation' ) || $p->is_type( 'simple' ) ) ) { continue; }
		$new = (int) $val;
		if ( $p->managing_stock() && (int) $p->get_stock_quantity() === $new ) { continue; }
		if ( ! $p->managing_stock() ) {
			$p->set_manage_stock( true );
			$p->save();
		}
		wc_update_product_stock( $p, $new, 'set' );
		$changed++;
	}

	$back = wp_get_referer() ?: boutique_stock_url();
	wp_safe_redirect( add_query_arg( 'saved', $changed, remove_query_arg( 'saved', $back ) ) );
	exit;
} );

/* --------------------------------------------------------------- Page */
function boutique_stock_page() {
	$only  = absint( $_GET['product'] ?? 0 );
	$saved = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
	$args  = [ 'status' => [ 'publish', 'draft', 'private' ], 'limit' => -1, 'type' => [ 'variable', 'simple' ], 'orderby' => 'date', 'order' => 'DESC' ];
	if ( $only ) { $args['include'] = [ $only ]; }
	$products = wc_get_products( $args );
	?>
	<div class="wrap b-wrap b-stockpage">
		<header class="b-head">
			<div>
				<h1 class="b-title">موجودی</h1>
				<p class="b-muted">تعداد هر رنگ و سایز را وارد کنید و یک بار «ذخیره» بزنید. خانه‌های قرمز تمام شده‌اند.</p>
			</div>
			<?php if ( $only ) : ?>
				<a class="b-btn b-btn--ghost" href="<?php echo esc_url( boutique_stock_url() ); ?>">همه لباس‌ها</a>
			<?php endif; ?>
		</header>

		<?php if ( null !== $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo $saved ? esc_html( boutique_fa_digits( $saved ) . ' مورد به‌روز شد.' ) : 'تغییری نبود.'; ?></p></div>
		<?php endif; ?>

		<?php if ( ! $only ) :
			$cat  = sanitize_title( wp_unslash( $_GET['cat'] ?? '' ) );
			$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'exclude' => [ (int) get_option( 'default_product_cat' ) ] ] ); ?>
			<div class="b-search b-stock-filter" role="search">
				<label class="screen-reader-text" for="b-stock-search">جستجوی لباس</label>
				<input type="search" id="b-stock-search" placeholder="جستجوی اسم لباس…" autocomplete="off">
				<?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
					<label class="screen-reader-text" for="b-stock-cat">دسته</label>
					<select id="b-stock-cat">
						<option value="">همه دسته‌ها</option>
						<?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c->slug ); ?>" <?php selected( $cat, $c->slug ); ?>><?php echo esc_html( $c->name ); ?></option><?php endforeach; ?>
					</select>
				<?php endif; ?>
			</div>
			<p class="b-empty" id="b-stock-none" hidden>لباسی با این جستجو یا دسته نیست.</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="b-stock-form">
			<?php wp_nonce_field( 'boutique_stock_save' ); ?>
			<input type="hidden" name="action" value="boutique_stock_save">

			<?php if ( ! $products ) : ?>
				<p class="b-empty">هنوز لباسی ثبت نشده.</p>
			<?php endif; ?>

			<div class="b-stock-list">
			<?php foreach ( $products as $p ) :
				[ $rows, $cols, $cells ] = boutique_stock_matrix( $p );
				[ $total, $out ] = boutique_stock_summary( $p ); ?>
				<section class="b-panel b-sp" data-name="<?php echo esc_attr( $p->get_name() ); ?>"
					data-cats="<?php echo esc_attr( ' ' . implode( ' ', wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'slugs' ] ) ) . ' ' ); ?>">
					<header class="b-sp__head">
						<?php echo $p->get_image( [ 56, 56 ], [ 'class' => 'b-sp__img', 'alt' => '' ] ); ?>
						<div class="b-sp__title">
							<h2><?php echo esc_html( $p->get_name() ); ?></h2>
							<p class="b-muted">
								<span><?php echo esc_html( boutique_fa_digits( $total ) ); ?> عدد</span>
								<?php if ( $out && 'publish' === $p->get_status() ) : ?><span class="b-stock is-out"><?php echo esc_html( boutique_fa_digits( $out ) ); ?> سایز تمام</span><?php endif; ?>
								<?php if ( 'publish' !== $p->get_status() ) : ?><span class="b-chip">پیش‌نویس</span><?php endif; ?>
							</p>
						</div>
						<a class="b-sp__edit" href="<?php echo esc_url( get_edit_post_link( $p->get_id() ) ); ?>">ویرایش لباس</a>
					</header>

					<div class="b-sp__scroll">
						<table class="b-matrix">
							<?php if ( $cols !== [ '' ] ) : ?>
								<thead><tr><th scope="col"><span class="screen-reader-text">رنگ</span></th>
									<?php foreach ( $cols as $c ) : ?><th scope="col"><?php echo esc_html( boutique_fa_digits( $c ) ); ?></th><?php endforeach; ?>
								</tr></thead>
							<?php endif; ?>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( $r ?: 'تعداد' ); ?></th>
									<?php foreach ( $cols as $c ) :
										$v = $cells[ $r ][ $c ] ?? null;
										if ( ! $v ) : ?>
											<td class="is-none" title="این ترکیب وجود ندارد">—</td>
										<?php else :
											$q = $v->managing_stock() ? (int) $v->get_stock_quantity() : '';
											$label = trim( $p->get_name() . ' ' . $r . ' ' . $c ); ?>
											<td class="<?php echo '' !== $q && $q <= 0 ? 'is-out' : ''; ?>">
												<div class="b-step-in">
													<button type="button" data-step="-1" aria-label="یکی کمتر: <?php echo esc_attr( $label ); ?>">−</button>
													<input type="text" inputmode="numeric" autocomplete="off" name="stock[<?php echo (int) $v->get_id(); ?>]"
														value="<?php echo esc_attr( '' === $q ? '' : boutique_fa_digits( $q ) ); ?>"
														data-orig="<?php echo esc_attr( (string) $q ); ?>"
														aria-label="موجودی <?php echo esc_attr( $label ); ?>">
													<button type="button" data-step="1" aria-label="یکی بیشتر: <?php echo esc_attr( $label ); ?>">+</button>
												</div>
											</td>
										<?php endif;
									endforeach; ?>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</section>
			<?php endforeach; ?>
			</div>

			<div class="b-savebar" id="b-savebar">
				<span id="b-dirty">تغییری ثبت نشده</span>
				<button type="submit" class="b-btn b-btn--big" id="b-stock-save" disabled>ذخیره موجودی</button>
			</div>
		</form>
	</div>
	<?php
	wp_enqueue_script( 'boutique-stock', BOUTIQUE_ADMIN_URL . 'assets/stock.js', [], filemtime( BOUTIQUE_ADMIN_DIR . '/assets/stock.js' ), true );
}

/* ------------------------------------------------- Product edit screen */

// A clear entry point at the top of the product editor.
add_action( 'edit_form_after_title', function ( $post ) {
	if ( 'product' !== $post->post_type || 'auto-draft' === $post->post_status ) { return; }
	$p = wc_get_product( $post->ID );
	if ( ! $p || ! ( $p->is_type( 'variable' ) || $p->is_type( 'simple' ) ) ) { return; }
	[ $total, $out ] = boutique_stock_summary( $p );
	echo '<div class="b-stockbox"><div><strong>موجودی: ' . esc_html( boutique_fa_digits( $total ) ) . ' عدد</strong>'
		. ( $out && 'publish' === $p->get_status() ? ' <span class="b-stock is-out">' . esc_html( boutique_fa_digits( $out ) ) . ' سایز تمام</span>' : '' )
		. '<p>برای تغییر تعداد هر رنگ و سایز، از جدول موجودی استفاده کنید.</p></div>'
		. '<a class="b-btn" href="' . esc_url( boutique_stock_url( $post->ID ) ) . '">ویرایش موجودی سایزها</a></div>';
} );

/* ---------------------------------------------------- Products list */

add_filter( 'manage_edit-product_columns', function ( $cols ) {
	unset( $cols['is_in_stock'] );
	if ( boutique_is_manager() ) {
		unset( $cols['sku'], $cols['global_unique_id'], $cols['product_tag'], $cols['taxonomy-product_brand'], $cols['featured'] );
	}
	$out = [];
	foreach ( $cols as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'price' === $k ) { $out['b_stock'] = 'موجودی'; }
	}
	return $out;
}, 20 );

add_action( 'manage_product_posts_custom_column', function ( $col, $post_id ) {
	if ( 'b_stock' !== $col ) { return; }
	$p = wc_get_product( $post_id );
	if ( ! $p ) { return; }
	[ $total, $out ] = boutique_stock_summary( $p );
	echo '<a class="b-listock" href="' . esc_url( boutique_stock_url( $post_id ) ) . '">'
		. '<strong>' . esc_html( boutique_fa_digits( $total ) ) . ' عدد</strong>'
		. ( 'publish' !== $p->get_status() ? '' : ( $out ? '<span class="b-stock is-out">' . esc_html( boutique_fa_digits( $out ) ) . ' تمام</span>' : '<span class="b-stock is-ok">همه موجود</span>' ) )
		. '</a>';
}, 10, 2 );
