<?php
/**
 * Blocksy header: add the cart icon (desktop + mobile).
 * Run: docker compose run --rm wpcli eval-file /scripts/theme.php
 */

$v = get_theme_mod( 'header_placements', '__none__' );
if ( '__none__' === $v ) {
	$v = blocksy_manager()->header_builder->get_default_value();
}

$add = function ( array &$rows, $placement, array $want_before_last = [] ) {
	foreach ( $rows as &$row ) {
		if ( 'middle-row' !== $row['id'] ) { continue; }
		foreach ( $row['placements'] as &$pl ) {
			if ( $pl['id'] !== $placement ) { continue; }
			if ( ! in_array( 'cart', $pl['items'], true ) ) {
				// Desktop: [menu, search, cart]; Mobile: [cart, trigger]
				if ( $want_before_last && in_array( $want_before_last[0], $pl['items'], true ) ) {
					$i = array_search( $want_before_last[0], $pl['items'], true );
					array_splice( $pl['items'], $i, 0, 'cart' );
				} else {
					$pl['items'][] = 'cart';
				}
			}
		}
	}
};

foreach ( $v['sections'] as &$section ) {
	if ( 'type-1' !== $section['id'] ) { continue; }
	$add( $section['desktop'], 'end' );
	$add( $section['mobile'], 'end', [ 'trigger' ] );
}
set_theme_mod( 'header_placements', $v );
delete_transient( 'blocksy_dynamic_styles_descriptor' );
WP_CLI::success( 'Cart added to header.' );
