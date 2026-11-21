const { test, expect } = require('@playwright/test');
const { login, fixture, php, makeOnHoldOrder, shop } = require('./helpers');
const OWNER = () => '98' + String(shop().notify_phones[0]).replace(/^(\+?98|0)/, '');   // e.g. 989121234567

// Messages are captured instead of sent (boutique_bale_capture), so no real Bale call happens.
const outbox = () => JSON.parse(php("echo wp_json_encode(get_option('boutique_bale_outbox', []));"));

test.describe.serial('اعلان بله', () => {
  test.beforeAll(() => php("update_option('boutique_bale_capture', 1); delete_option('boutique_bale_outbox');"));
  test.afterAll(() => php("delete_option('boutique_bale_capture'); delete_option('boutique_bale_outbox');"));

  test('سفارش آنلاین و رسید آپلودشده در بله خبر داده می‌شوند', async ({ page }) => {
    const f = fixture();
    const setSel = (s, v) => page.evaluate(([s, v]) => { const el = document.querySelector(s); el.value = v; window.jQuery(el).trigger('change'); }, [s, v]);
    await page.goto(`/?add-to-cart=${f.id}&variation_id=${f.vars['black|38']}&attribute_pa_color=black&attribute_pa_size=38`);
    await page.goto('/checkout/', { waitUntil: 'networkidle' });
    await page.fill('#billing_first_name', 'مشتری'); await page.fill('#billing_last_name', 'بله');
    await page.fill('#billing_phone', '09120000003');
    await setSel('#billing_state', 'THR');
    await page.waitForFunction(() => [...document.querySelectorAll('#billing_city option')].some((o) => o.value === 'تهران'));
    await setSel('#billing_city', 'تهران');
    await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.locator('input[name^="shipping_method"][value^="local_pickup"]').first().check();
    await page.waitForLoadState('networkidle'); await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.check('#terms');
    await page.click('#place_order');
    await page.waitForURL(/order-received\/(\d+)/);
    const id = page.url().match(/order-received\/(\d+)/)[1];

    await expect.poll(() => outbox().length).toBe(1);
    const msg = outbox()[0];
    expect(msg).toContain('سفارش جدید #' + id.replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]));
    expect(msg).toContain('۰۹۱۲۰۰۰۰۰۰۳');
    expect(msg).toContain('لباس تست خودکار');
    expect(msg).toContain('تحویل حضوری');
    expect(msg).toContain('page=boutique-today');

    const photo = require('path').join(__dirname, 'fixtures/photo.jpg');
    await page.locator('.b-receipt input[type="file"]').setInputFiles(photo);
    await page.locator('.b-receipt button[type="submit"]').click();
    await expect(page.locator('.b-receipt__done')).toBeVisible();
    await expect.poll(() => outbox().length).toBe(2);
    expect(outbox()[1]).toContain('رسید کارت به کارت');
  });

  test('فروش حضوری و سفارش‌های ساخته‌شده دستی اعلان نمی‌دهند', async () => {
    php("delete_option('boutique_bale_outbox');");
    makeOnHoldOrder(fixture().vars['maroon|38']);
    expect(outbox().length).toBe(0);
  });

  test('صفحه «اعلان سفارش» برای مدیر: بله و تلگرام', async ({ page }) => {
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-bale');
    await expect(page.locator('h1')).toHaveText('اعلان سفارش');
    await expect(page.locator('.b-notify[data-ch="bale"] h2')).toContainText('بله');
    await expect(page.locator('.b-notify[data-ch="tg"] h2')).toContainText('تلگرام');
    await expect(page.locator('#adminmenu')).toContainText('اعلان سفارش');
  });

  test('اتصال فقط با شماره مجاز (notify_phones در config): بقیه رد می‌شوند', async ({ page }) => {
    const fake = (updates) => php(`update_option('boutique_bale_fake_updates', json_decode('${JSON.stringify(updates)}', true)); delete_option('boutique_bale_outbox');`);
    php("delete_option('boutique_tg_chats'); delete_option('boutique_tg_asked'); delete_option('boutique_notify_phones');");
    const start = (id) => ({ message: { chat: { id, type: 'private', first_name: 'U' + id }, from: { id }, text: '/start' } });
    const contact = (id, phone, uid = id) => ({ message: { chat: { id, type: 'private', first_name: 'U' + id }, from: { id }, contact: { phone_number: phone, user_id: uid } } });
    const connect = async () => {
      await page.goto('/wp-admin/admin.php?page=boutique-bale');
      await Promise.all([page.waitForURL(/msg=/), page.locator('.b-notify[data-ch="tg"] form:has(input[value="connect"]) button').click()]);
    };
    const chats = () => JSON.parse(php("echo wp_json_encode(array_keys((array) get_option('boutique_tg_chats', [])));")).map(String);
    await login(page, 'manager');

    // 1. /start from the owner (111) and a stranger (222): both are asked for their number, nobody connected yet.
    fake([start(111), start(222)]);
    await connect();
    expect(chats()).toEqual([]);
    expect(outbox().join(' ')).toContain('شماره موبایلتان تأیید شود');

    // 2. Owner shares +98 912 198 4212, stranger shares another number, a third forwards the owner's contact.
    fake([start(111), start(222), contact(111, '+' + OWNER()), contact(222, '989350000000'), start(333), contact(333, OWNER(), 111)]);
    await connect();
    expect(chats()).toEqual(['111']);
    expect(outbox().join(' ')).toContain('اجازه دریافت');
    php("delete_option('boutique_tg_chats'); delete_option('boutique_tg_asked'); delete_option('boutique_bale_fake_updates');");
  });

  test('ارسال ناموفق: ۲، ۴، ۸، ۱۶ ثانیه دوباره؛ بعد صف پس‌زمینه تا ۳۰ دقیقه، بعد رها', () => {
    php("delete_option('boutique_bale_outbox'); delete_option('boutique_notify_queue'); delete_option('boutique_notify_dropped');");
    // Fails 3 times, 4th try (after 2+4+8 s) gets through: delivered, nothing queued.
    php("update_option('boutique_bale_fake_fail', 3); boutique_bale_send('alert A', null, true);");
    expect(outbox().join(' ')).toContain('alert A');
    expect(php("echo count((array) get_option('boutique_notify_queue', []));")).toBe('0');

    // Fails all 5 inline tries: goes to the queue, next try in 32 s.
    php("update_option('boutique_bale_fake_fail', 5); boutique_bale_send('alert B', null, true);");
    const q = JSON.parse(php("echo wp_json_encode(get_option('boutique_notify_queue'));"));
    expect(q).toHaveLength(1);
    expect(q[0].wait).toBe(32);
    // Queue run while still failing: gap doubles.
    php("update_option('boutique_bale_fake_fail', 1); $q = get_option('boutique_notify_queue'); $q[0]['next'] = 0; update_option('boutique_notify_queue', $q); boutique_notify_queue_run();");
    expect(JSON.parse(php("echo wp_json_encode(get_option('boutique_notify_queue'));"))[0].wait).toBe(64);
    // Next run succeeds: delivered and removed.
    php("$q = get_option('boutique_notify_queue'); $q[0]['next'] = 0; update_option('boutique_notify_queue', $q); boutique_notify_queue_run();");
    expect(outbox().join(' ')).toContain('alert B');
    expect(php("echo count((array) get_option('boutique_notify_queue', []));")).toBe('0');

    // Still failing after 30 minutes: dropped and logged.
    php("update_option('boutique_bale_fake_fail', 5); boutique_bale_send('alert C', null, true); update_option('boutique_bale_fake_fail', 99); $q = get_option('boutique_notify_queue'); $q[0]['next'] = 0; $q[0]['first'] = time() - 31 * 60; update_option('boutique_notify_queue', $q); boutique_notify_queue_run();");
    expect(php("echo count((array) get_option('boutique_notify_queue', []));")).toBe('0');
    expect(php("echo wp_json_encode(get_option('boutique_notify_dropped'));")).toContain('alert C');
    php("delete_option('boutique_bale_fake_fail'); delete_option('boutique_notify_queue'); delete_option('boutique_notify_dropped');");
  });
});
