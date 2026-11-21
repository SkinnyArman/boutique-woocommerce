<?php
/**
 * «گزارش»: the few numbers a small dress shop actually decides with.
 * Visible to the shop manager and the admin. Replaces WooCommerce Analytics for the manager.
 *
 * Sales = orders confirmed or done (processing + completed), minus refunds, by order date.
 * Unconfirmed card-to-card orders (on-hold) are shown separately, never counted as sales.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'گزارش فروش', 'گزارش', 'view_woocommerce_reports', 'boutique-reports', 'boutique_reports_page', 'dashicons-chart-bar', 5 );
	if ( boutique_is_manager() ) {
		remove_menu_page( 'wc-admin&path=/analytics/overview' );
	}
}, 1000 );

const BOUTIQUE_RANGES = [ 'today' => 'امروز', '7' => '۷ روز اخیر', '30' => '۳۰ روز اخیر', '90' => '۹۰ روز اخیر' ];

/** Collect everything the page shows for [start, end). */
function boutique_report_data( $start, $end ) {
	$orders = wc_get_orders( [
		'status'       => [ 'processing', 'completed' ],
		'date_created' => $start . '...' . ( $end - 1 ),
		'limit'        => -1,
		'type'         => 'shop_order',
	] );
	$d = [ 'sum' => 0, 'count' => 0, 'online' => [ 0, 0 ], 'instore' => [ 0, 0 ], 'days' => [], 'products' => [], 'colors' => [], 'sizes' => [], 'pay' => [], 'pieces' => 0 ];
	foreach ( $orders as $o ) {
		$total = (float) $o->get_total() - (float) $o->get_total_refunded();
		$d['sum'] += $total;
		$d['count']++;
		$ch = 'instore' === $o->get_created_via() ? 'instore' : 'online';
		$d[ $ch ][0]++;
		$d[ $ch ][1] += $total;
		// Gregorian key on purpose: Persian WooCommerce makes wp_date() return Jalali dates,
		// which would never match the bucket keys built with DateTime::format() below.
		$day = ( new DateTimeImmutable( '@' . $o->get_date_created()->getTimestamp() ) )->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$d['days'][ $day ] = ( $d['days'][ $day ] ?? 0 ) + $total;
		$pm = $o->get_payment_method_title() ?: 'نامشخص';
		$d['pay'][ $pm ] = ( $d['pay'][ $pm ] ?? 0 ) + $total;

		foreach ( $o->get_items() as $item ) {
			$qty = (int) $item->get_quantity() - absint( $o->get_qty_refunded_for_item( $item->get_id() ) );
			if ( $qty <= 0 ) { continue; }
			$pid  = $item->get_product_id();
			$name = $pid ? get_the_title( $pid ) : $item->get_name();
			$d['products'][ $pid ] ??= [ 'name' => $name, 'qty' => 0, 'sum' => 0 ];
			$d['products'][ $pid ]['qty'] += $qty;
			$d['products'][ $pid ]['sum'] += (float) $item->get_total();
			$d['pieces'] += $qty;
			foreach ( [ 'pa_color' => 'colors', 'pa_size' => 'sizes' ] as $tax => $bucket ) {
				$slug = $item->get_meta( $tax );
				if ( ! $slug ) { continue; }
				$t     = get_term_by( 'slug', $slug, $tax );
				$label = $t ? $t->name : $slug;
				$d[ $bucket ][ $label ] = ( $d[ $bucket ][ $label ] ?? 0 ) + $qty;
			}
		}
	}
	uasort( $d['products'], fn( $a, $b ) => $b['qty'] <=> $a['qty'] ?: $b['sum'] <=> $a['sum'] );
	arsort( $d['colors'] );
	arsort( $d['sizes'] );
	arsort( $d['pay'] );
	return $d;
}

/** Ranked list with thin magnitude bars (one hue). */
function boutique_rank_list( array $rows, $unit, $empty, $swatches = [] ) {
	if ( ! $rows ) { echo '<p class="b-empty">' . esc_html( $empty ) . '</p>'; return; }
	$max = max( array_column( $rows, 'v' ) ) ?: 1;
	echo '<ol class="b-rank">';
	foreach ( $rows as $r ) {
		$sw = isset( $swatches[ $r['label'] ] ) ? '<span class="b-rank__sw" style="background:' . esc_attr( $swatches[ $r['label'] ] ) . '" aria-hidden="true"></span>' : '';
		printf(
			'<li><div class="b-rank__top"><span class="b-rank__label">%s%s</span><span class="b-rank__val">%s</span></div><div class="b-rank__track" aria-hidden="true"><span style="inline-size:%s%%"></span></div></li>',
			$sw,
			esc_html( boutique_fa_digits( $r['label'] ) ),
			esc_html( $r['text'] ?? boutique_fa_digits( $r['v'] ) . ' ' . $unit ),
			esc_attr( max( 2, round( $r['v'] / $max * 100, 1 ) ) )
		);
	}
	echo '</ol>';
}

function boutique_reports_page() {
	$range = sanitize_key( $_GET['range'] ?? '30' );
	$range = isset( BOUTIQUE_RANGES[ $range ] ) ? $range : '30';
	$tz    = wp_timezone();
	$today = ( new DateTimeImmutable( 'today', $tz ) );
	$days  = 'today' === $range ? 1 : (int) $range;
	$start = $today->modify( '-' . ( $days - 1 ) . ' days' );
	$end   = $today->modify( '+1 day' );
	$prevS = $start->modify( "-{$days} days" );

	$d    = boutique_report_data( $start->getTimestamp(), $end->getTimestamp() );
	$prev = boutique_report_data( $prevS->getTimestamp(), $start->getTimestamp() );
	$avg  = $d['count'] ? $d['sum'] / $d['count'] : 0;

	$waiting = wc_get_orders( [ 'status' => 'on-hold', 'limit' => -1 ] );
	$wsum    = array_sum( array_map( fn( $o ) => (float) $o->get_total(), $waiting ) );

	// Change vs previous period, in words.
	$change = function ( $now, $before ) use ( $range ) {
		$label = 'today' === $range ? 'دیروز' : 'دوره قبل';
		if ( $before <= 0 ) { return $now > 0 ? "دوره قبل فروشی نبود" : ''; }
		$pct = round( ( $now - $before ) / $before * 100 );
		if ( 0 === (int) $pct ) { return "مثل {$label}"; }
		$dir = $pct > 0 ? 'up' : 'down';
		$svg = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="' . ( 'up' === $dir ? 'M6 2l4 5H2z' : 'M6 10L2 5h8z' ) . '" fill="currentColor"/></svg>';
		return $svg . ' ' . boutique_fa_digits( abs( $pct ) ) . '٪ ' . ( $pct > 0 ? 'بیشتر' : 'کمتر' ) . " از {$label}";
	};

	// Buckets for the chart: daily up to 30 days, weekly for 90.
	$buckets = [];
	if ( $days > 1 ) {
		$step = $days > 30 ? 7 : 1;
		for ( $t = $start; $t < $end; $t = $t->modify( "+{$step} days" ) ) {
			$sum = 0;
			for ( $i = 0; $i < $step; $i++ ) { $sum += $d['days'][ $t->modify( "+{$i} days" )->format( 'Y-m-d' ) ] ?? 0; }
			$buckets[] = [
				'label' => 1 === $step ? wp_date( 'j F', $t->getTimestamp() ) : 'هفته ' . wp_date( 'j F', $t->getTimestamp() ),
				'short' => wp_date( 'j', $t->getTimestamp() ),
				'sum'   => $sum,
			];
		}
	}
	$bmax = $buckets ? max( array_column( $buckets, 'sum' ) ) : 0;

	$swatch = [ 'مشکی' => '#2b2527', 'کرم' => '#e3d3b8', 'صورتی' => '#e8a5b0', 'سرمه‌ای' => '#2f3a5c', 'زرشکی' => '#7d1f33', 'سبز' => '#6f8f72' ];
	?>
	<div class="wrap b-wrap b-rep">
		<header class="b-head">
			<div>
				<h1 class="b-title">گزارش فروش</h1>
				<p class="b-muted"><?php echo esc_html( 'today' === $range ? wp_date( 'l j F Y' ) : wp_date( 'j F', $start->getTimestamp() ) . ' تا ' . wp_date( 'j F Y', $today->getTimestamp() ) ); ?></p>
			</div>
			<nav class="b-seg" aria-label="بازه زمانی">
				<?php foreach ( BOUTIQUE_RANGES as $k => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'boutique-reports', 'range' => $k ], admin_url( 'admin.php' ) ) ); ?>" <?php echo $k === $range ? 'aria-current="page" class="is-on"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</header>

		<section class="b-stats b-stats--rep" aria-label="خلاصه">
			<div class="b-stat b-stat--hero"><span>فروش</span><strong><?php echo esc_html( boutique_money( $d['sum'] ) ); ?></strong><em><?php echo wp_kses( $change( $d['sum'], $prev['sum'] ), [ 'svg' => [ 'width' => 1, 'height' => 1, 'viewbox' => 1, 'aria-hidden' => 1 ], 'path' => [ 'd' => 1, 'fill' => 1 ] ] ); ?></em></div>
			<div class="b-stat"><span>تعداد سفارش</span><strong><?php echo esc_html( boutique_fa_digits( $d['count'] ) ); ?></strong><em><?php echo esc_html( boutique_fa_digits( $d['pieces'] ) ); ?> لباس فروخته شد</em></div>
			<div class="b-stat"><span>میانگین هر سفارش</span><strong><?php echo esc_html( boutique_money( $avg ) ); ?></strong></div>
			<div class="b-stat"><span>آنلاین / حضوری</span>
				<strong><?php echo esc_html( boutique_fa_digits( $d['online'][0] ) . ' / ' . boutique_fa_digits( $d['instore'][0] ) ); ?></strong>
				<em><?php echo $d['sum'] > 0 ? esc_html( 'آنلاین ' . boutique_fa_digits( round( $d['online'][1] / $d['sum'] * 100 ) ) . '٪ از فروش' ) : '—'; ?></em></div>
		</section>

		<?php if ( $waiting ) : ?>
			<p class="b-callout"><?php echo esc_html( boutique_fa_digits( count( $waiting ) ) . ' سفارش کارت‌به‌کارت (' . boutique_money( $wsum ) . ') هنوز تأیید نشده و در این اعداد نیست.' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=boutique-today' ) ); ?>">بررسی در «امروز»</a></p>
		<?php endif; ?>

		<?php if ( $buckets ) : ?>
			<section class="b-panel" aria-labelledby="b-chart-title">
				<h2 id="b-chart-title">فروش <?php echo $days > 30 ? 'هر هفته' : 'هر روز'; ?></h2>
				<?php if ( $bmax <= 0 ) : ?>
					<p class="b-empty">در این بازه فروشی ثبت نشده.</p>
				<?php else : ?>
					<div class="b-chart" role="img" aria-label="نمودار ستونی فروش؛ جدول همین داده‌ها پایین‌تر است.">
						<div class="b-chart__grid" aria-hidden="true">
							<span data-v="<?php echo esc_attr( boutique_money( $bmax ) ); ?>"></span>
							<span data-v="<?php echo esc_attr( boutique_money( $bmax / 2 ) ); ?>"></span>
							<span data-v="۰"></span>
						</div>
						<div class="b-chart__bars">
							<?php foreach ( $buckets as $i => $b ) : ?>
								<div class="b-bar" tabindex="0" style="--h:<?php echo esc_attr( $bmax ? round( $b['sum'] / $bmax * 100, 2 ) : 0 ); ?>%">
									<span class="b-bar__fill"></span>
									<span class="b-bar__tip" role="tooltip"><b><?php echo esc_html( $b['label'] ); ?></b><?php echo esc_html( boutique_money( $b['sum'] ) ); ?></span>
									<?php if ( $days <= 7 || 0 === $i % ( $days > 30 ? 2 : 5 ) ) : ?><span class="b-bar__x"><?php echo esc_html( $days <= 7 ? $b['label'] : $b['short'] ); ?></span><?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
					<details class="b-more">
						<summary>نمایش به‌صورت جدول</summary>
						<table class="b-table"><thead><tr><th scope="col">تاریخ</th><th scope="col">فروش</th></tr></thead><tbody>
							<?php foreach ( $buckets as $b ) : ?><tr><td><?php echo esc_html( $b['label'] ); ?></td><td><?php echo esc_html( boutique_money( $b['sum'] ) ); ?></td></tr><?php endforeach; ?>
						</tbody></table>
					</details>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<div class="b-cols b-cols--3">
			<section class="b-panel" aria-labelledby="b-top-title">
				<h2 id="b-top-title">پرفروش‌ترین لباس‌ها</h2>
				<?php boutique_rank_list( array_map( fn( $p ) => [ 'label' => $p['name'], 'v' => $p['qty'], 'text' => boutique_fa_digits( $p['qty'] ) . ' عدد · ' . boutique_money( $p['sum'] ) ], array_slice( $d['products'], 0, 8 ) ), 'عدد', 'هنوز فروشی نیست.' ); ?>
			</section>
			<section class="b-panel" aria-labelledby="b-color-title">
				<h2 id="b-color-title">رنگ‌های پرفروش</h2>
				<?php boutique_rank_list( array_map( fn( $k, $v ) => [ 'label' => $k, 'v' => $v ], array_keys( $d['colors'] ), $d['colors'] ), 'عدد', 'هنوز فروشی نیست.', $swatch ); ?>
			</section>
			<section class="b-panel" aria-labelledby="b-size-title">
				<h2 id="b-size-title">سایزهای پرفروش</h2>
				<?php boutique_rank_list( array_map( fn( $k, $v ) => [ 'label' => 'سایز ' . $k, 'v' => $v ], array_keys( $d['sizes'] ), $d['sizes'] ), 'عدد', 'هنوز فروشی نیست.' ); ?>
			</section>
		</div>

		<section class="b-panel" aria-labelledby="b-pay-title">
			<h2 id="b-pay-title">روش پرداخت</h2>
			<?php boutique_rank_list( array_map( fn( $k, $v ) => [ 'label' => $k, 'v' => $v, 'text' => boutique_money( $v ) ], array_keys( $d['pay'] ), $d['pay'] ), '', 'هنوز فروشی نیست.' ); ?>
		</section>
	</div>
	<?php
}
