const { test, expect } = require('@playwright/test');
const { login, fixture, stockOf, orderInfo, makeOnHoldOrder, php, fa } = require('./helpers');

const step = (page, orderId, to) =>
  page.locator(`form.b-step:has(input[name="order_id"][value="${orderId}"]):has(input[name="to"][value="${to}"])`);

test.describe('صفحه «امروز»', () => {
  test('تأیید واریز ← آماده ارسال ← ارسال با کد رهگیری', async ({ page }) => {
    const orderId = makeOnHoldOrder(fixture().vars['maroon|40']);
    await login(page, 'manager');

    const waiting = page.locator('.b-panel', { hasText: 'منتظر تأیید واریز' });
    await expect(waiting).toContainText(`#${fa(orderId)}`);
    await expect(waiting).toContainText('لباس تست خودکار');
    await step(page, orderId, 'processing').locator('button').click();
    await expect(page.locator('.b-notice')).toContainText('واریز تأیید شد');
    expect(orderInfo(orderId).status).toBe('processing');

    const toShip = page.locator('.b-panel', { hasText: 'آماده ارسال' });
    await expect(toShip).toContainText(`#${fa(orderId)}`);
    const ship = step(page, orderId, 'completed');
    await ship.locator('input[name="tracking"]').fill('۱۲۳۴۵۶۷۸'); // typed in Persian digits
    await ship.locator('button').click();
    await expect(page.locator('.b-notice')).toContainText('پیگیری سفارش');

    const o = orderInfo(orderId);
    expect(o.status).toBe('completed');
    expect(o.tracking).toBe('12345678');
    const note = php(`foreach (wc_get_order_notes(['order_id' => ${orderId}, 'type' => 'customer']) as $n) echo $n->content;`);
    expect(note).toContain('12345678'); // the customer is told the tracking code
  });

  test('لغو سفارش، موجودی را برمی‌گرداند', async ({ page }) => {
    const vid = fixture().vars['maroon|40'];
    const before = stockOf(vid);
    const orderId = makeOnHoldOrder(vid);
    expect(stockOf(vid)).toBe(before - 1);

    await login(page, 'manager');
    page.once('dialog', (d) => d.accept());
    await step(page, orderId, 'cancelled').locator('button').click();
    await expect(page.locator('.b-notice')).toContainText('لغو شد');
    expect(orderInfo(orderId).status).toBe('cancelled');
    expect(stockOf(vid)).toBe(before);
  });

  test('سایز تمام‌شده در فهرست «تمام‌شده» دیده می‌شود', async ({ page }) => {
    await login(page, 'manager');
    const row = page.locator('.b-table tr', { hasText: 'لباس تست خودکار' }).filter({ hasText: 'مشکی' }).first();
    await expect(row.locator('.b-stock.is-out')).toHaveText('تمام شد');
  });
});
