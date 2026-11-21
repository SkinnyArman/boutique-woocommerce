/* In-store sale screen: search -> pick color -> pick size -> invoice -> submit. */
(function () {
	const catalog = window.BOUTIQUE_CATALOG || [];
	const $ = (s) => document.querySelector(s);
	const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
	const latin = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
	const money = (n) => fa(Math.round(n).toLocaleString('en-US')) + ' تومان';
	const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const norm = (s) => String(s).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[«»\s‌]+/g, ' ').trim();

	const els = {
		search: $('#b-pos-search'), list: $('#b-pos-products'), variant: $('#b-pos-variant'),
		cart: $('#b-pos-cart'), total: $('#b-pos-total'), lines: $('#b-pos-lines'),
		submit: $('#b-pos-submit'), form: $('#b-pos-form'),
	};
	let cart = []; // {id, name, label, price, qty, stock}
	let current = null, color = null;

	function renderProducts() {
		const q = norm(els.search.value);
		const hits = catalog.filter((p) => !q || norm(p.name).includes(q)).slice(0, 24);
		els.list.innerHTML = hits.length ? hits.map((p) => `
			<button type="button" class="b-prod${current && current.id === p.id ? ' is-active' : ''}" data-id="${p.id}" role="listitem">
				<img src="${esc(p.img)}" alt="" loading="lazy" width="64" height="64">
				<span>${esc(p.name)}</span>
			</button>`).join('') : '<p class="b-empty">لباسی با این اسم پیدا نشد.</p>';
	}

	function renderVariant() {
		if (!current) { els.variant.hidden = true; return; }
		els.variant.hidden = false;
		const colors = [...new Set(current.vars.map((v) => v.color))];
		if (colors.length === 1) color = colors[0];
		const sizes = current.vars.filter((v) => v.color === color);
		els.variant.innerHTML = `
			<h3>${esc(current.name)}</h3>
			${colors[0] !== '' ? `<p class="b-label">رنگ</p><div class="b-chips">${colors.map((c) =>
				`<button type="button" class="b-chipbtn${c === color ? ' is-on' : ''}" data-color="${esc(c)}">${esc(c)}</button>`).join('')}</div>` : ''}
			${color !== null ? `<p class="b-label">سایز (موجودی سایت)</p><div class="b-chips">${sizes.map((v) => {
				const out = v.stock !== null && v.stock <= 0;
				return `<button type="button" class="b-chipbtn b-size${out ? ' is-out' : ''}" data-var="${v.id}">
					<b>${esc(v.size || v.label || 'تک‌سایز')}</b><small>${v.stock === null ? '—' : out ? 'صفر' : fa(v.stock)}</small></button>`;
			}).join('')}</div>` : '<p class="b-hint">اول رنگ را انتخاب کنید.</p>'}`;
	}

	function add(v) {
		if (v.stock !== null && v.stock <= 0 &&
			!confirm('طبق سایت این سایز موجود نیست. اگر واقعاً در فروشگاه بود و فروختید، ادامه دهید (موجودی سایت منفی می‌شود و بعداً باید اصلاحش کنید).')) return;
		const hit = cart.find((l) => l.id === v.id);
		if (hit) hit.qty++;
		else cart.push({ id: v.id, name: current.name, label: v.label, price: v.price, qty: 1, stock: v.stock });
		renderCart();
		els.variant.querySelector(`[data-var="${v.id}"]`)?.classList.add('is-flash');
	}

	function renderCart() {
		if (!cart.length) {
			els.cart.innerHTML = '<p class="b-empty">هنوز چیزی اضافه نشده.</p>';
		} else {
			els.cart.innerHTML = cart.map((l, i) => `
				<div class="b-line">
					<div class="b-line__name"><strong>${esc(l.name)}</strong><span>${esc(l.label)}</span></div>
					<label class="b-line__price"><span class="screen-reader-text">قیمت واحد</span>
						<input type="text" inputmode="numeric" value="${fa(Math.round(l.price).toLocaleString('en-US'))}" data-i="${i}" data-f="price"></label>
					<div class="b-line__qty">
						<button type="button" data-i="${i}" data-d="-1" aria-label="یکی کمتر">−</button>
						<span>${fa(l.qty)}</span>
						<button type="button" data-i="${i}" data-d="1" aria-label="یکی بیشتر">+</button>
					</div>
					<button type="button" class="b-line__rm" data-rm="${i}" aria-label="حذف">×</button>
				</div>`).join('');
		}
		const total = cart.reduce((s, l) => s + l.price * l.qty, 0);
		els.total.textContent = money(total);
		els.lines.value = JSON.stringify(cart.map((l) => ({ id: l.id, qty: l.qty, price: l.price })));
		els.submit.disabled = !cart.length;
		els.submit.textContent = cart.length ? `ثبت فروش · ${money(total)}` : 'ثبت فروش';
	}

	els.search.addEventListener('input', renderProducts);
	els.list.addEventListener('click', (e) => {
		const b = e.target.closest('[data-id]'); if (!b) return;
		current = catalog.find((p) => p.id === +b.dataset.id); color = null;
		renderProducts(); renderVariant();
		els.variant.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	});
	els.variant.addEventListener('click', (e) => {
		const c = e.target.closest('[data-color]');
		if (c) { color = c.dataset.color; renderVariant(); return; }
		const s = e.target.closest('[data-var]');
		if (s) add(current.vars.find((v) => v.id === +s.dataset.var));
	});
	els.cart.addEventListener('click', (e) => {
		const d = e.target.closest('[data-d]');
		if (d) { const l = cart[+d.dataset.i]; l.qty = Math.max(1, l.qty + +d.dataset.d); renderCart(); return; }
		const r = e.target.closest('[data-rm]');
		if (r) { cart.splice(+r.dataset.rm, 1); renderCart(); }
	});
	els.cart.addEventListener('change', (e) => {
		if (e.target.dataset.f !== 'price') return;
		const n = parseInt(latin(e.target.value).replace(/[^\d]/g, ''), 10);
		cart[+e.target.dataset.i].price = isNaN(n) ? 0 : n;
		renderCart();
	});
	els.form.addEventListener('submit', (e) => {
		if (!cart.length) { e.preventDefault(); return; }
		els.submit.disabled = true; els.submit.textContent = 'در حال ثبت…';
	});

	renderProducts(); renderCart();
	els.search.focus();
})();
