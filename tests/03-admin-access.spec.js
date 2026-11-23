const { test, expect } = require('@playwright/test');
const { login, adminMenu, watchForErrors, fixture, php, fa } = require('./helpers');

test.describe('دسترسی‌ها و منوی مدیریت', () => {
  test('بدون ورود، پیشخوان در دسترس نیست', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=boutique-today');
    await expect(page).toHaveURL(/wp-login\.php/);
  });

  test('مدیر فروشگاه: مستقیم به «امروز» می‌رود و فقط منوی لازم را می‌بیند', async ({ page }) => {
    const problems = watchForErrors(page);
    await login(page, 'manager');
    await expect(page).toHaveURL(/page=boutique-today/);
    const menu = await adminMenu(page);
    for (const item of ['امروز', 'فروش حضوری', 'موجودی', 'گزارش', 'سفارش‌ها', 'لباس‌ها', 'دسته‌بندی‌ها']) expect(menu).toContain(item);
    for (const item of ['نوشته‌ها', 'برگه‌ها', 'دیدگاه‌ها', 'نمایش', 'افزونه‌ها', 'ابزارها', 'تنظیمات', 'بازاریابی', 'تجزیه و تحلیل', 'ووکامرس فارسی', 'ووکامرس', 'کاربران']) expect(menu).not.toContain(item);

    // The old dashboard sends the manager back to «امروز».
    await page.goto('/wp-admin/index.php');
    await expect(page).toHaveURL(/page=boutique-today/);
    // "Add product" opens the simple form.
    await page.goto('/wp-admin/post-new.php?post_type=product');
    await expect(page).toHaveURL(/page=boutique-product/);
    expect(problems).toEqual([]);
  });

  test('مدیر فروشگاه به افزونه‌ها و تنظیمات سایت دسترسی ندارد', async ({ page }) => {
    await login(page, 'manager');
    for (const path of ['/wp-admin/plugins.php', '/wp-admin/options-general.php', '/wp-admin/themes.php', '/wp-admin/customize.php', '/wp-admin/update-core.php', '/wp-admin/user-new.php']) {
      const res = await page.goto(path);
      expect(res.status(), path).toBe(403);
    }
  });

  test('ادمین: پیشخوان سالم است، همه چیز را می‌بیند، تبلیغ افزونه‌ها پنهان است', async ({ page }) => {
    const problems = watchForErrors(page);
    await login(page, 'admin');
    const res = await page.goto('/wp-admin/index.php');
    expect(res.status()).toBe(200); // regression: missing pdo_mysql used to crash every admin page
    const menu = await adminMenu(page);
    for (const item of ['امروز', 'فروش حضوری', 'موجودی', 'گزارش', 'افزونه‌ها', 'تنظیمات']) expect(menu).toContain(item);
    await expect(page.locator('.pw_notice:visible')).toHaveCount(0);
    expect(problems).toEqual([]);
  });

  test('صفحه‌های مدیریت هیچ درخواستی به سرور خارجی نمی‌فرستند', async ({ page }) => {
    // Regression: Gravatar on every admin page made them hang when the foreign server was slow.
    const external = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.protocol.startsWith('http') && u.hostname !== 'localhost') external.push(r.url());
    });
    await login(page, 'manager');
    for (const path of ['admin.php?page=boutique-today', 'admin.php?page=boutique-instore', 'admin.php?page=boutique-stock', 'admin.php?page=boutique-product', 'admin.php?page=boutique-reports', 'edit.php?post_type=product', 'admin.php?page=wc-orders']) {
      await page.goto('/wp-admin/' + path, { waitUntil: 'networkidle' });
    }
    expect(external).toEqual([]);
  });

  test('فهرست محصولات: ستون موجودی درست و قابل کلیک است', async ({ page }) => {
    const f = fixture();
    const total = Number(php(`$t = 0; foreach (wc_get_product(${f.id})->get_children() as $c) { $t += max(0, (int) wc_get_product($c)->get_stock_quantity()); } echo $t;`));
    await login(page, 'manager');
    await page.goto('/wp-admin/edit.php?post_type=product');
    const cell = page.locator(`#post-${f.id} .column-b_stock`);
    await expect(cell).toContainText(`${fa(total)} عدد`);
    await expect(cell).toContainText('تمام'); // black/40 starts at zero
    await cell.locator('a').click();
    await expect(page).toHaveURL(new RegExp(`page=boutique-stock&product=${f.id}`));
    // Clicking the dress name opens the simple form, not WooCommerce's editor.
    await page.goto('/wp-admin/edit.php?post_type=product');
    await page.locator(`#post-${f.id} a.row-title`).click();
    await expect(page).toHaveURL(new RegExp(`page=boutique-product&product=${f.id}`));
  });
});
