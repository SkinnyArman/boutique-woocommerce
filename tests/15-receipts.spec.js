const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { login, fixture, php, orderInfo, ROOT, watchForErrors, makeOnHoldOrder, stockOf } = require('./helpers');

const PHOTO = path.join(ROOT, 'tests/fixtures/photo.jpg');
const settle = async (page) => { await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0); };
async function setSelect(page, selector, value) {
  await page.waitForFunction(([s, v]) => document.querySelector(s) && [...document.querySelector(s).options].some((o) => o.value === v), [selector, value]);
  await page.evaluate(([s, v]) => { const el = document.querySelector(s); el.value = v; window.jQuery(el).trigger('change'); }, [selector, value]);
}

test.describe.serial('رسید کارت به کارت', () => {
  let id, thanks;

  test('بعد از ثبت سفارش: مبلغ و شماره کارت، دو راه فرستادن رسید، و آپلود عکس', async ({ page }) => {
    const problems = watchForErrors(page);
    const f = fixture();
    await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38`);
    await page.goto('/checkout/', { waitUntil: 'networkidle' });
    await page.fill('#billing_first_name', 'مشتری');
    await page.fill('#billing_last_name', 'رسید');
    await page.fill('#billing_phone', '09120000002');
    await setSelect(page, '#billing_state', 'THR');
    await setSelect(page, '#billing_city', 'تهران');
    await page.fill('#billing_address_1', 'خیابان آزمایش');
    await page.fill('#billing_postcode', '1234567890');
    await settle(page);
    await page.locator('#shipping_method li', { hasText: 'پست پیشتاز' }).locator('input').check();   // Post
    await settle(page);
    await page.check('#terms');
    await page.click('#place_order');
    await page.waitForURL(/order-received\/(\d+)/, { timeout: 30_000 });
    id = Number(page.url().match(/order-received\/(\d+)/)[1]);
    thanks = page.url();

    const box = page.locator('.b-receipt');
    await expect(box).toContainText('شماره کارت');
    await expect(box.locator('.b-receipt__pay')).toContainText('900,000')   // 800,000 dress + 100,000 Post (Tehran);
    // Option 2: chat buttons carry the order number.
    await page.click('label[for="b-way-chat"]');
    const tg = box.locator('.b-receipt__chats a', { hasText: 'تلگرام' });
    await expect(tg).toBeVisible();
    const msg = decodeURIComponent(await tg.getAttribute('href'));
    expect(msg).toContain('سفارش شماره ' + String(id).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]));
    expect(msg).toContain('۹۰۰,۰۰۰ تومان');
    expect(msg).not.toMatch(/&nbsp;|&#/);                  // plain text, no HTML leaking into the chat
    await expect(box.locator('.b-receipt__deadline')).toContainText('خودکار لغو');
    await expect(box.locator('.b-receipt__chats a', { hasText: 'واتساپ' })).toHaveAttribute('href', /wa\.me/);
    // Option 1: upload.
    await page.click('label[for="b-way-up"]');
    await box.locator('input[type="file"]').setInputFiles(PHOTO);
    await box.locator('button[type="submit"]').click();
    await expect(page.locator('.b-receipt__done')).toContainText('رسید شما رسید');
    expect(orderInfo(id).status).toBe('on-hold');
    expect(problems).toEqual([]);
  });

  test('فایل غیرعکس پذیرفته نمی‌شود؛ رسید از بیرون قابل دیدن نیست', async ({ page, request }) => {
    await page.goto(thanks);
    await page.locator('.b-receipt input[type="file"]').setInputFiles({ name: 'receipt.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('not an image') });
    await page.locator('.b-receipt button[type="submit"]').click();
    await expect(page.locator('.b-receipt__error')).toContainText('فقط عکس');

    const name = php(`echo wc_get_order(${id})->get_meta('_boutique_receipt');`);
    expect(name).toMatch(new RegExp(`^${id}-\\w{24}\\.jpg$`));
    expect((await request.get(`/wp-content/uploads/boutique-receipts/${name}`)).status()).toBe(403);
    const anon = await request.get(`/wp-admin/admin-post.php?action=boutique_receipt_view&order=${id}`, { maxRedirects: 0 });
    expect(anon.headers()['content-type'] || '').not.toContain('image');
    // Re-saved small: a phone photo shouldn't eat the host's disk.
    expect(Number(php(`echo filesize(boutique_receipt_file(wc_get_order(${id})));`))).toBeLessThan(600_000);
  });

  test('مدیر رسید را کنار «واریز رسید، تأیید» می‌بیند', async ({ page }) => {
    await login(page, 'manager');
    const card = page.locator('.b-order', { has: page.locator(`input[name="order_id"][value="${id}"]`) });
    const img = card.locator('.b-receipt-thumb img');
    await expect(img).toBeVisible();
    expect(await img.evaluate((i) => i.naturalWidth)).toBeGreaterThan(50);
  });

  test('در پیگیری سفارش هم می‌شود رسید فرستاد', async ({ page }) => {
    php('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'_transient%b_track_%\'");');
    await page.goto('/track/');
    await page.fill('input[name="order"]', String(id));
    await page.fill('input[name="phone"]', '09120000002');
    await page.click('.b-track__form button');
    await expect(page.locator('.b-receipt')).toContainText('شماره کارت');
    await page.locator('.b-receipt input[type="file"]').setInputFiles(PHOTO);
    await page.locator('.b-receipt button[type="submit"]').click();
    await expect(page.locator('.b-track__ok')).toContainText('رسید شما رسید');
  });

  test('رسید نیامد: بعد از ۲۴ ساعت لغو خودکار و موجودی آزاد؛ سفارشِ با رسید دست نمی‌خورد', async ({ page }) => {
    const vid = fixture().vars['maroon|40'];
    const before = stockOf(vid);
    const unpaid = makeOnHoldOrder(vid);                         // reserves one
    expect(stockOf(vid)).toBe(before - 1);
    const age = (oid) => php(`$o = wc_get_order(${oid}); $o->set_date_created(time() - 25 * HOUR_IN_SECONDS); $o->save();`);
    age(unpaid); age(id);                                        // id = the order with an uploaded receipt

    await login(page, 'manager');
    await expect(page.locator('.b-order', { has: page.locator(`input[name="order_id"][value="${unpaid}"]`) })).toContainText('لغو');

    php('boutique_cancel_unpaid();');
    expect(orderInfo(unpaid).status).toBe('cancelled');
    expect(stockOf(vid)).toBe(before);                           // size is back on sale
    expect(orderInfo(id).status).toBe('on-hold');                // receipt uploaded: the owner decides

    php('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'_transient%b_track_%\'");');
    await page.goto('/track/');
    await page.fill('input[name="order"]', String(unpaid));
    await page.fill('input[name="phone"]', '09120000000');
    await page.click('.b-track__form button');
    await expect(page.locator('.b-track__status')).toContainText('رسید واریز');
  });
});
