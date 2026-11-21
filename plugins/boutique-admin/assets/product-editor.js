/* Simple product form: photos, money fields, color/size chips, auto stock grid, validation. */
(function ($) {
	const D = window.BOUTIQUE_PE;
	if (!D) return;
	const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
	const latin = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
	const num = (s) => { const n = parseInt(latin(s).replace(/[^\d]/g, ''), 10); return isNaN(n) ? 0 : n; };
	const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const form = document.getElementById('b-pe-form');

	/* ---------------- Photos ---------------- */
	let images = D.state.images.slice();
	const photos = document.getElementById('b-pe-photos');
	function renderPhotos() {
		const colorList = typeof ordered === 'function' ? ordered('colors') : [];
		photos.innerHTML = images.map((im, i) => {
			if (im.color && !colorList.includes(im.color)) im.color = '';
			return `<div class="b-phwrap">
			<figure class="b-ph${i === 0 ? ' is-main' : ''}">
				<img src="${esc(im.url)}" alt="">
				${i === 0 ? '<span class="b-ph__tag">عکس اصلی</span>' : `<button type="button" class="b-ph__main" data-main="${i}">اصلی شود</button>`}
				<button type="button" class="b-ph__rm" data-rm="${i}" aria-label="حذف عکس">×</button>
			</figure>
			${colorList.length ? `<label class="b-ph__color"><span class="screen-reader-text">رنگ این عکس</span>
				<select data-photo="${i}"><option value="">همه رنگ‌ها</option>${colorList.map((c) =>
					`<option value="${esc(c)}" ${im.color === c ? 'selected' : ''}>${esc(nameOf('colors', c))}</option>`).join('')}</select></label>` : ''}
			</div>`;
		}).join('') +
			`<button type="button" class="b-ph b-ph--add" id="b-pe-addphoto"><span class="dashicons dashicons-camera" aria-hidden="true"></span>${images.length ? 'عکس بیشتر' : 'انتخاب عکس‌ها'}</button>`;
		document.getElementById('b-pe-images').value = images.map((i) => i.id).join(',');
		syncPhotoColors();
	}
	function syncPhotoColors() {
		const map = {};
		images.forEach((im) => { if (im.color) map[im.id] = im.color; });
		document.getElementById('b-pe-photocolors').value = JSON.stringify(map);
	}
	photos.addEventListener('change', (e) => {
		const s = e.target.closest('[data-photo]');
		if (s) { images[+s.dataset.photo].color = s.value; syncPhotoColors(); }
	});
	let frame;
	photos.addEventListener('click', (e) => {
		if (e.target.closest('#b-pe-addphoto')) {
			frame = frame || wp.media({ title: 'عکس‌های لباس', button: { text: 'افزودن این عکس‌ها' }, library: { type: 'image' }, multiple: 'add' });
			frame.off('select').on('select', () => {
				frame.state().get('selection').toJSON().forEach((a) => {
					if (!images.some((i) => i.id === a.id)) images.push({ id: a.id, url: (a.sizes && (a.sizes.thumbnail || a.sizes.medium) || a).url, color: '' });
				});
				renderPhotos();
			});
			frame.open();
			return;
		}
		const m = e.target.closest('[data-main]');
		if (m) { images.unshift(images.splice(+m.dataset.main, 1)[0]); renderPhotos(); return; }
		const r = e.target.closest('[data-rm]');
		if (r) { images.splice(+r.dataset.rm, 1); renderPhotos(); }
	});

	/* ---------------- Money fields ---------------- */
	const price = document.getElementById('b-pe-price'), sale = document.getElementById('b-pe-sale'), off = document.getElementById('b-pe-off');
	function money(input) {
		const n = num(input.value);
		input.value = n ? fa(n.toLocaleString('en-US')) : '';
	}
	const ask = document.getElementById('b-pe-ask');
	function discount() {
		if (ask.checked) { off.textContent = 'قیمت در سایت نمایش داده نمی‌شود؛ کنار لباس «استعلام قیمت» نوشته می‌شود.'; return; }
		const p = num(price.value), s = num(sale.value);
		if (s && p && s < p) off.textContent = `${fa(Math.round((p - s) / p * 100))}٪ تخفیف — مشتری قیمت قبلی را خط‌خورده می‌بیند.`;
		else if (s && s >= p) off.textContent = 'قیمت حراج باید از قیمت اصلی کمتر باشد.';
		else off.textContent = '';
	}
	[price, sale].forEach((i) => i.addEventListener('input', () => { money(i); discount(); }));
	function askToggle() { price.disabled = sale.disabled = ask.checked; discount(); }
	ask.addEventListener('change', askToggle);
	askToggle();

	/* ---------------- Colors & sizes ---------------- */
	const sel = { colors: new Set(D.state.colors.map(String)), sizes: new Set(D.state.sizes.map(String)) };
	const opts = { colors: D.colors.map((t) => ({ v: String(t.id), name: t.name })), sizes: D.sizes.map((t) => ({ v: String(t.id), name: t.name })) };
	const stock = Object.assign({}, D.state.stock); // "c|s" -> qty

	function renderChips(kind) {
		document.getElementById('b-pe-' + kind).innerHTML = opts[kind].map((o) => `
			<label class="b-chipbtn b-chipcheck"><input type="checkbox" name="${kind}[]" value="${esc(o.v)}" ${sel[kind].has(o.v) ? 'checked' : ''}> ${esc(fa(o.name))}</label>`).join('');
	}
	['colors', 'sizes'].forEach((kind) => {
		document.getElementById('b-pe-' + kind).addEventListener('change', (e) => {
			e.target.checked ? sel[kind].add(e.target.value) : sel[kind].delete(e.target.value);
			renderStock(); renderPhotos(); renderGuide();
		});
	});
	document.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
		const kind = b.dataset.add, input = document.getElementById(kind === 'colors' ? 'b-pe-newcolor' : 'b-pe-newsize');
		const name = input.value.trim();
		if (!name) { input.focus(); return; }
		let o = opts[kind].find((x) => x.name === name || x.name === latin(name));
		if (!o) { o = { v: 'new:' + name, name }; opts[kind].push(o); }
		sel[kind].add(o.v);
		input.value = '';
		renderChips(kind); renderStock(); renderPhotos(); renderGuide();
	}));
	['b-pe-newcolor', 'b-pe-newsize'].forEach((id) => document.getElementById(id).addEventListener('keydown', (e) => {
		if (e.key === 'Enter') { e.preventDefault(); e.target.nextElementSibling.click(); }
	}));

	/* ---------------- Stock grid ---------------- */
	const nameOf = (kind, v) => (opts[kind].find((o) => o.v === v) || { name: v }).name;
	// Keep chip order (shop's order), not click order.
	const ordered = (kind) => opts[kind].map((o) => o.v).filter((v) => sel[kind].has(v));
	function renderStock() {
		const rows = ordered('colors'), cols = ordered('sizes');
		const R = rows.length ? rows : ['0'], C = cols.length ? cols : ['0'];
		const box = document.getElementById('b-pe-stock');
		if (!rows.length && !cols.length) {
			box.innerHTML = `<p class="b-hint">بدون رنگ و سایز، فقط یک عدد موجودی دارد:</p>${cell('0|0', 'موجودی')}`;
			return;
		}
		box.innerHTML = `<table class="b-matrix">
			${cols.length ? `<thead><tr><th></th>${C.map((c) => `<th scope="col">${esc(fa(nameOf('sizes', c)))}</th>`).join('')}</tr></thead>` : ''}
			<tbody>${R.map((r) => `<tr><th scope="row">${rows.length ? esc(nameOf('colors', r)) : 'تعداد'}</th>
				${C.map((c) => `<td>${cell(r + '|' + c, `${rows.length ? nameOf('colors', r) : ''} ${cols.length ? nameOf('sizes', c) : ''}`)}</td>`).join('')}</tr>`).join('')}
			</tbody></table>`;
	}
	function cell(key, label) {
		const q = stock[key] ?? '';
		return `<div class="b-step-in"><button type="button" data-step="-1" data-k="${esc(key)}" aria-label="یکی کمتر ${esc(label)}">−</button>
			<input type="text" inputmode="numeric" autocomplete="off" name="stock[${esc(key)}]" data-k="${esc(key)}" value="${q === '' ? '' : fa(q)}" placeholder="۰" aria-label="موجودی ${esc(label)}">
			<button type="button" data-step="1" data-k="${esc(key)}" aria-label="یکی بیشتر ${esc(label)}">+</button></div>`;
	}
	const stockBox = document.getElementById('b-pe-stock');
	stockBox.addEventListener('click', (e) => {
		const b = e.target.closest('[data-step]'); if (!b) return;
		stock[b.dataset.k] = Math.max(0, (+stock[b.dataset.k] || 0) + +b.dataset.step);
		b.parentElement.querySelector('input').value = fa(stock[b.dataset.k]);
	});
	stockBox.addEventListener('input', (e) => {
		if (!e.target.dataset.k) return;
		const n = latin(e.target.value).replace(/[^\d]/g, '');
		e.target.value = fa(n);
		stock[e.target.dataset.k] = n === '' ? '' : +n;
	});
	// Select-on-focus is handy with a mouse but stops the iPhone keyboard; skip it on touch screens.
	const touch = matchMedia('(pointer: coarse)').matches;
	stockBox.addEventListener('focusin', (e) => { if (!touch && e.target.matches('input')) e.target.select(); });
	// Tapping anywhere in a cell types into it (focus inside the tap, so iOS opens the keyboard).
	stockBox.addEventListener('click', (e) => {
		if (e.target.closest('input, button')) return;
		e.target.closest('td, .b-step-in')?.querySelector('input')?.focus();
	});

	/* ---------------- Size guide: one row per chosen size ---------------- */
	const guide = {};                        // size name -> { bust, waist, hip, length }
	Object.entries(D.state.guide || {}).forEach(([k, v]) => { guide[k] = Object.assign({}, v); });
	document.getElementById('b-pe-guidenote').value = D.state.guide_note || '';
	function renderGuide() {
		const box = document.getElementById('b-pe-guide');
		const sizes = ordered('sizes').map((v) => nameOf('sizes', v));
		const rows = sizes.length ? sizes : ['فری‌سایز'];
		const cols = Object.entries(D.guideCols);
		box.innerHTML = `<table class="b-matrix b-guide">
			<thead><tr><th scope="col">سایز</th>${cols.map(([, l]) => `<th scope="col">${esc(l)}</th>`).join('')}</tr></thead>
			<tbody>${rows.map((s) => `<tr><th scope="row">${esc(fa(s))}</th>${cols.map(([k, l]) => {
				const v = (guide[s] || {})[k] || '';
				return `<td><input type="text" inputmode="decimal" name="guide[${esc(s)}][${k}]" data-s="${esc(s)}" data-c="${k}" value="${esc(fa(v))}" placeholder="—" aria-label="${esc(l)} سایز ${esc(fa(s))}"></td>`;
			}).join('')}</tr>`).join('')}</tbody></table>`;
	}
	document.getElementById('b-pe-guide').addEventListener('input', (e) => {
		const i = e.target; if (!i.dataset.s) return;
		i.value = fa(latin(i.value).replace(/[^\d.\-–/]/g, ''));
		(guide[i.dataset.s] = guide[i.dataset.s] || {})[i.dataset.c] = latin(i.value);
	});

	/* ---------------- Submit ---------------- */
	let status = 'publish', dirty = false, sending = false;
	const clearMsg = () => { if (!sending) document.getElementById('b-pe-msg').textContent = ''; };
	form.addEventListener('input', () => { dirty = true; clearMsg(); });
	form.addEventListener('change', () => { dirty = true; clearMsg(); });
	photos.addEventListener('click', clearMsg);
	form.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', () => { status = b.dataset.status; }));
	form.addEventListener('submit', (e) => {
		const msg = document.getElementById('b-pe-msg');
		const problems = [];
		if (!document.getElementById('b-pe-name').value.trim()) problems.push('اسم لباس');
		if (!ask.checked && !num(price.value)) problems.push('قیمت');
		if (status === 'publish' && !images.length) problems.push('حداقل یک عکس');
		if (status === 'publish' && !form.querySelector('input[name="cats[]"]:checked')) problems.push('دسته');
		if (problems.length) {
			e.preventDefault();
			msg.textContent = 'لطفاً وارد کنید: ' + problems.join('، ');
			return;
		}
		document.getElementById('b-pe-status').value = status;
		// Send plain digits to the server.
		form.querySelectorAll('input[inputmode="numeric"]').forEach((i) => { i.value = latin(i.value).replace(/[^\d]/g, ''); });
		sending = true;
		form.querySelectorAll('[data-status]').forEach((b) => { b.disabled = true; });
		msg.textContent = 'در حال ذخیره…';
		// disabled buttons are not submitted; status travels in the hidden field.
	});
	window.addEventListener('beforeunload', (e) => { if (dirty && !sending) { e.preventDefault(); e.returnValue = ''; } });

	/* ---------------- Brand / fabric suggestions ----------------
	 * Plain text box + tappable chips instead of <datalist>, which iPhone Safari handles badly
	 * (its suggestion sheet can stop the keyboard from opening). */
	const normS = (s) => String(s).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').trim();
	document.querySelectorAll('input[data-suggest]').forEach((input) => {
		const src = document.getElementById(input.dataset.suggest);
		const all = src ? [...src.options].map((o) => o.value).filter(Boolean) : [];
		if (!all.length) return;
		const box = document.createElement('div');
		box.className = 'b-chips b-suggest';
		box.hidden = true;
		input.closest('label').after(box);
		const show = () => {
			const q = normS(input.value);
			const m = all.filter((v) => normS(v) !== q && (!q || normS(v).includes(q))).slice(0, 8);
			box.innerHTML = m.map((v) => `<button type="button" class="b-chipbtn">${esc(v)}</button>`).join('');
			box.hidden = !m.length;
		};
		input.addEventListener('focus', show);
		input.addEventListener('input', show);
		// Keep the text box focused while a chip is tapped.
		box.addEventListener('mousedown', (e) => e.preventDefault());
		box.addEventListener('click', (e) => {
			const b = e.target.closest('button'); if (!b) return;
			input.value = b.textContent;
			input.dispatchEvent(new Event('input', { bubbles: true }));
			box.hidden = true;
		});
		input.addEventListener('blur', () => setTimeout(() => { box.hidden = true; }, 200));
	});

	renderChips('colors'); renderChips('sizes'); renderStock(); renderPhotos(); renderGuide();
})(jQuery);
