const { test, expect } = require('@playwright/test');
const { login, fixture, makeOnHoldOrder, php, watchForErrors } = require('./helpers');

const track = async (page, order, phone) => {
  await page.goto('/track/');
  await page.fill('input[name="order"]', String(order));
  await page.fill('input[name="phone"]', phone);
  await page.click('.b-track__form button');
};
const step = (page, orderId, to) =>
  page.locator(`form.b-step:has(input[name="order_id"][value="${orderId}"]):has(input[name="to"][value="${to}"])`);

test.describe.serial('پیگیری سفارش', () => {
  let orderId;
  test.beforeAll(() => {
    php('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'_transient%b_track_%\'");');
    orderId = makeOnHoldOrder(fixture().vars['maroon|40']); // phone 09120000000
  });

  test('هر مرحله همان چیزی را نشان می‌دهد که مدیر زده؛ آدرس هرگز نشان داده نمی‌شود', async ({ page }) => {
    const problems = watchForErrors(page);
    await track(page, orderId, '۰۹۱۲۰۰۰۰۰۰۰'); // Persian digits
    await expect(page.locator('.b-track__status')).toContainText('در انتظار تأیید پرداخت');
    await expect(page.locator('.b-track__items')).toContainText('لباس تست خودکار');
    await expect(page.locator('.b-track__result')).not.toContainText('خیابان');

    await login(page, 'manager');
    await step(page, orderId, 'processing').locator('button').click();
    await track(page, orderId, '+989120000000');  // +98 form
    await expect(page.locator('.b-track__status')).toContainText('ثبت شده');
    await expect(page.locator('.b-track__steps li.is-now')).toHaveText('ثبت شده');

    await login(page, 'manager');
    const ship = step(page, orderId, 'completed');
    await expect(ship.locator('button')).toHaveText('تحویل پیک شد');
    await ship.locator('input[name="tracking"]').fill('98765');
    await ship.locator('button').click();
    await track(page, orderId, '09120000000');
    await expect(page.locator('.b-track__status')).toContainText('تحویل پیک شد');
    await expect(page.locator('.b-track__code')).toContainText('98765');
    expect(problems).toEqual([]);
  });

  test('شماره درست با موبایل اشتباه چیزی نشان نمی‌دهد', async ({ page }) => {
    await track(page, orderId, '09351111111');
    await expect(page.locator('.b-track__error')).toContainText('پیدا نشد');
    await expect(page.locator('.b-track__result')).toHaveCount(0);
  });

  test('حدس زدن پشت‌سرهم مسدود می‌شود', async ({ page }) => {
    for (let i = 0; i < 9; i++) await track(page, orderId + 1000 + i, '09120000000');
    await track(page, orderId, '09120000000'); // even the right answer waits now
    await expect(page.locator('.b-track__error')).toContainText('تعداد تلاش‌ها زیاد');
    php('global $wpdb; $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE \'_transient%b_track_%\'");');
  });

  test('لغو شده به‌صورت لغو شده نشان داده می‌شود', async ({ page }) => {
    const id = makeOnHoldOrder(fixture().vars['maroon|40']);
    php(`wc_get_order(${id})->update_status('cancelled');`);
    await track(page, id, '09120000000');
    await expect(page.locator('.b-track__status')).toContainText('لغو شده');
    await expect(page.locator('.b-track__steps')).toHaveCount(0);
  });

  test('صفحه پیگیری در فوتر است', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('footer a', { hasText: 'پیگیری سفارش' })).toHaveAttribute('href', /\/track\//);
  });
});
