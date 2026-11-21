/* Stock grid: +/- steppers, Persian digits, dirty tracking, search, unsaved-changes guard. */
(function () {
	const form = document.getElementById('b-stock-form');
	if (!form) return;
	const save = document.getElementById('b-stock-save');
	const dirtyLabel = document.getElementById('b-dirty');
	const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
	const latin = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
	const val = (input) => { const n = parseInt(latin(input.value).replace(/[^\d-]/g, ''), 10); return isNaN(n) ? null : n; };
	let submitting = false;

	function refresh() {
		let dirty = 0;
		form.querySelectorAll('input[data-orig]').forEach((i) => {
			const v = val(i);
			const changed = v !== null && String(v) !== i.dataset.orig;
			i.closest('td').classList.toggle('is-dirty', changed);
			i.closest('td').classList.toggle('is-out', v !== null && v <= 0);
			if (changed) dirty++;
		});
		save.disabled = !dirty;
		dirtyLabel.textContent = dirty ? `${fa(dirty)} تغییر ذخیره نشده` : 'تغییری ثبت نشده';
		document.getElementById('b-savebar').classList.toggle('is-dirty', !!dirty);
	}

	form.addEventListener('click', (e) => {
		const b = e.target.closest('[data-step]');
		if (!b) return;
		const input = b.parentElement.querySelector('input');
		input.value = fa(Math.max(0, (val(input) ?? 0) + +b.dataset.step));
		refresh();
	});
	form.addEventListener('input', (e) => {
		if (!e.target.matches('input[data-orig]')) return;
		const pos = e.target.selectionStart;
		e.target.value = fa(latin(e.target.value).replace(/[^\d]/g, ''));
		e.target.setSelectionRange(pos, pos);
		refresh();
	});
	// Enter moves to the next cell instead of submitting half-done edits.
	form.addEventListener('keydown', (e) => {
		if (e.key !== 'Enter' || !e.target.matches('input[data-orig]')) return;
		e.preventDefault();
		const all = [...form.querySelectorAll('input[data-orig]')];
		all[all.indexOf(e.target) + 1]?.focus();
	});
	// Select-on-focus is handy with a mouse but stops the iPhone keyboard; skip it on touch screens.
	const touch = matchMedia('(pointer: coarse)').matches;
	form.addEventListener('focusin', (e) => { if (!touch && e.target.matches('input[data-orig]')) e.target.select(); });
	// Tapping anywhere in a cell types into it (focus inside the tap, so iOS opens the keyboard).
	form.addEventListener('click', (e) => {
		if (e.target.closest('input, button')) return;
		e.target.closest('.b-matrix td')?.querySelector('input[data-orig]')?.focus();
	});
	form.addEventListener('submit', () => { submitting = true; save.disabled = true; save.textContent = 'در حال ذخیره…'; });
	window.addEventListener('beforeunload', (e) => {
		if (!submitting && !save.disabled) { e.preventDefault(); e.returnValue = ''; }
	});

	// Search + category filter run in the page, so unsaved stock edits survive switching.
	const search = document.getElementById('b-stock-search');
	const cat = document.getElementById('b-stock-cat');
	const none = document.getElementById('b-stock-none');
	if (search) {
		const norm = (s) => s.toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[«»\s‌]+/g, ' ').trim();
		const filter = () => {
			const q = norm(search.value);
			const c = cat ? cat.value : '';
			let shown = 0;
			document.querySelectorAll('.b-sp').forEach((s) => {
				s.hidden = !!((q && !norm(s.dataset.name).includes(q)) || (c && !s.dataset.cats.includes(` ${c} `)));
				if (!s.hidden) shown++;
			});
			if (none) none.hidden = shown > 0 || !document.querySelector('.b-sp');
			// Keep the chosen category in the address so a reload or save comes back to it.
			if (cat) {
				const u = new URL(location.href);
				c ? u.searchParams.set('cat', c) : u.searchParams.delete('cat');
				u.searchParams.delete('saved');
				history.replaceState(null, '', u);
			}
		};
		search.addEventListener('input', filter);
		cat?.addEventListener('change', filter);
		if (cat?.value) filter();
	}
	refresh();
})();
