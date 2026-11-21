const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const HOST = new URL(process.env.LIVE_URL).hostname;
// Shop details from config/shop.php (or the example), read as text: no PHP needed on this machine.
const cfg = (key) => {
  for (const f of ['shop.php', 'shop.example.php']) {
    const p = path.join(__dirname, '../../config', f);
    const m = fs.existsSync(p) && fs.readFileSync(p, 'utf8').match(new RegExp(`'${key}'\\s*=>\\s*'([^']*)'`));
    if (m) return m[1];
  }
  return '';
};
const fa = (s) => String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
const SHORT_NAME = cfg('short_name');

const PHOTO = path.join(__dirname, '../fixtures/photo.jpg');
const login = async (page, user, pass) => {
  await page.context().clearCookies();
  await page.goto('/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForURL(/wp-admin/, { timeout: 60_000 }), page.click('#wp-submit')]);
};

test.describe.serial('سایت واقعی', () => {
  test('صفحه اصلی: سالم، https، بدون درخواست خارجی، بدون اسکرول افقی در موبایل', async ({ page }) => {
    const errors = []; const external = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('request', (r) => { const u = new URL(r.url()); if (u.protocol.startsWith('http') && u.hostname !== HOST) external.push(r.url()); });
    const res = await page.goto('/', { waitUntil: 'networkidle' });
    expect(res.status()).toBe(200);
    expect(page.url().startsWith(`https://${HOST}/`)).toBe(true);
    await expect(page.locator('h1')).toContainText(SHORT_NAME);
    expect(await page.locator('.b-arcade__item:not([data-clone])').count()).toBeGreaterThan(3);
    await expect(page.locator('.b-sale')).toHaveCount(0);                 // nothing on sale after cleanup
    expect(external).toEqual([]);
    expect(errors).toEqual([]);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/', { waitUntil: 'networkidle' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  });

  test('همه صفحه‌ها باز می‌شوند', async ({ page, request }) => {
    for (const p of ['/shop/', '/categories/', '/faq/', '/contact/', '/terms/', '/privacy-policy/', '/track/', '/about/', '/cart/']) {
      const r = await request.get(p);
      expect(r.status(), p).toBe(200);
    }
    await page.goto('/shop/');
    const first = await page.locator('.products .product a').first().getAttribute('href');
    expect((await request.get(first)).status()).toBe(200);
  });

  test('چیزهای خصوصی از بیرون باز نمی‌شوند', async ({ request }) => {
    for (const p of ['/wp-config.php', '/xmlrpc.php', '/wp-content/uploads/boutique-receipts/', '/wp-content/debug.log']) {
      const r = await request.get(p, { maxRedirects: 0 });
      expect([403, 404], p).toContain(r.status());
    }
    const robots = await (await request.get('/robots.txt')).text();
    expect(robots).toContain(`Sitemap: https://${HOST}/wp-sitemap.xml`);
    expect((await request.get('/wp-sitemap.xml')).status()).toBe(200);
  });

  test('ورود ادمین و مدیر فروشگاه', async ({ page }) => {
    await login(page, 'admin', process.env.LIVE_ADMIN_PASS);
    await expect(page.locator('#adminmenu')).toContainText('امروز');
    await login(page, 'manager', process.env.LIVE_MANAGER_PASS);
    await expect(page).toHaveURL(/page=boutique-today/);
  });

  test('سفارش واقعی: تحویل حضوری + کارت به کارت + آپلود رسید؛ بعد لغو و حذف', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/shop/');
    // First buyable product (not «استعلام قیمت»): add its first available variation.
    const links = [...new Set(await page.locator('.products .product a[href*="/product/"]').evaluateAll((as) => as.map((a) => a.href)))];
    let added = false;
    for (const href of links) {
      await page.goto(href, { waitUntil: 'networkidle' });
      if (await page.locator('body.b-is-ask').count()) continue;
      for (const sel of await page.locator('form.variations_form select').all()) {
        const opts = await sel.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
        if (opts.length) await sel.selectOption(opts[0]);
      }
      const btn = page.locator('.single_add_to_cart_button');
      // WooCommerce enables the button a moment after a size is picked.
      const ready = await page.waitForFunction(() => { const b = document.querySelector('.single_add_to_cart_button'); return b && !b.classList.contains('disabled'); }, null, { timeout: 4000 }).then(() => true, () => false);
      if (!ready) continue;
      await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.ok(), { timeout: 30_000 }), btn.click()]);
      await page.waitForLoadState('networkidle');
      added = true; break;
    }
    expect(added).toBe(true);

    await page.goto('/checkout/', { waitUntil: 'networkidle' });
    await expect(page.locator('#billing_first_name')).toBeVisible({ timeout: 15_000 });
    await page.fill('#billing_first_name', 'تست');
    await page.fill('#billing_last_name', 'استقرار');
    await page.fill('#billing_phone', '09120000000');
    await page.evaluate(() => { const s = document.querySelector('#billing_state'); s.value = 'THR'; jQuery(s).trigger('change'); });
    await page.waitForFunction(() => [...document.querySelectorAll('#billing_city option')].some((o) => o.value === 'تهران'));
    await page.evaluate(() => { const s = document.querySelector('#billing_city'); s.value = 'تهران'; jQuery(s).trigger('change'); });
    await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.locator('input[name^="shipping_method"][value^="local_pickup"]').first().check();
    await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.check('#terms');
    await page.click('#place_order');
    await page.waitForURL(/order-received\/(\d+)/, { timeout: 60_000 });
    const id = page.url().match(/order-received\/(\d+)/)[1];
    await expect(page.locator('.b-receipt')).toContainText(fa(cfg('card_number')));
    await expect(page.locator('.b-receipt')).toContainText(cfg('card_holder'));
    await page.locator('.b-receipt input[type="file"]').setInputFiles(PHOTO);
    await page.locator('.b-receipt button[type="submit"]').click();
    await expect(page.locator('.b-receipt__done')).toContainText('رسید شما رسید');

    // Owner side: sees the receipt, then every «تست استقرار» order (this one and any left by an
    // earlier run) is cancelled (stock comes back) and deleted.
    await login(page, 'manager', process.env.LIVE_MANAGER_PASS);
    const card = page.locator('.b-order', { has: page.locator(`input[name="order_id"][value="${id}"]`) });
    await expect(card.locator('.b-receipt-thumb img')).toBeVisible();
    const ids = await page.locator('.b-order', { hasText: 'تست استقرار' }).locator('input[name="order_id"]').evaluateAll((els) => [...new Set(els.map((e) => e.value))]);
    for (const oid of ids) {
      const c = page.locator('.b-order', { has: page.locator(`input[name="order_id"][value="${oid}"]`) });
      page.once('dialog', (d) => d.accept());
      await c.locator('form.b-step:has(input[name="to"][value="cancelled"]) button').click();
      await expect(page.locator('.b-notice')).toContainText('لغو');
    }
    await login(page, 'admin', process.env.LIVE_ADMIN_PASS);
    for (const oid of ids) {
      await page.goto(`/wp-admin/post.php?post=${oid}&action=edit`);
      page.once('dialog', (d) => d.accept());
      await page.locator('a.submitdelete').first().click();
      await page.waitForLoadState('networkidle');
    }
    await page.goto('/wp-admin/edit.php?post_status=trash&post_type=shop_order');
    const empty = page.locator('#delete_all').first();
    if (await empty.count()) { await empty.click(); await page.waitForLoadState('networkidle'); }
    console.log('test orders cancelled and deleted:', ids.join(', '));
  });
});
