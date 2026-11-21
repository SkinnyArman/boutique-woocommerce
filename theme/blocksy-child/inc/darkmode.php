<?php
/**
 * Dark mode: light by default; a moon/sun button beside the cart switches it and the choice is
 * remembered on that device (localStorage). Applied before first paint, so no white flash.
 * Colors live in style.css under html[data-theme="dark"].
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', function () {
	echo "<script>try{if(localStorage.getItem('b-theme')==='dark')document.documentElement.setAttribute('data-theme','dark')}catch(e){}</script>\n";
}, 0 );

add_action( 'wp_footer', function () {
	$moon = '<svg class="b-theme__moon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	$sun  = '<svg class="b-theme__sun" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
	?>
	<template id="b-theme-tpl"><button type="button" class="b-theme" aria-pressed="false" aria-label="حالت تیره" title="حالت تیره / روشن"><?php echo $moon . $sun; // phpcs:ignore ?></button></template>
	<script>
	(function () {
		var root = document.documentElement, tpl = document.getElementById('b-theme-tpl');
		if (!tpl) return;
		function sync() {
			var dark = root.getAttribute('data-theme') === 'dark';
			document.querySelectorAll('.b-theme').forEach(function (b) { b.setAttribute('aria-pressed', dark ? 'true' : 'false'); });
		}
		// One button per header (desktop + mobile), just before the cart icon.
		document.querySelectorAll('#header [data-device] [data-id="cart"]').forEach(function (cart) {
			cart.parentNode.insertBefore(tpl.content.firstElementChild.cloneNode(true), cart);
		});
		document.addEventListener('click', function (e) {
			if (!e.target.closest('.b-theme')) return;
			var dark = root.getAttribute('data-theme') !== 'dark';
			// Switch instantly: no element should animate its colors during the swap.
			root.classList.add('b-no-transition');
			dark ? root.setAttribute('data-theme', 'dark') : root.removeAttribute('data-theme');
			try { localStorage.setItem('b-theme', dark ? 'dark' : 'light'); } catch (err) {}
			sync();
			requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.remove('b-no-transition'); }); });
		});
		sync();
	})();
	</script>
	<?php
}, 5 );
