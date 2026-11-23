const { test, expect } = require('@playwright/test');
const { login, fixture, makeOnHoldOrder, php, watchForErrors } = require('./helpers');

test.describe('«سفارش‌ها» و «لباس‌ها»', () => {
  test('سفارش‌ها: تب‌ها با تعداد، جستجو با موبایل یا شماره، لینک جزئیات', async ({ page }) => {
    const problems = watchForErrors(page);
    const id = makeOnHoldOrder(fixture().vars['maroon|38']);   // phone 09120000000
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-orders&tab=onhold');
    await expect(page.locator('.b-tabs a.is-on')).toContainText('منتظر واریز');
    const card = page.locator('.b-order', { hasText: 'لباس تست خودکار' }).first();
    await expect(card.locator('.b-state')).toHaveText('منتظر واریز');
    await expect(card.locator('a', { hasText: 'جزئیات' })).toHaveAttribute('href', new RegExp(`post=${id}|id=${id}`));
    for (const q of ['۰۹۱۲۰۰۰۰۰۰۰', '#' + id]) {
      await page.fill('#b-oq', q); await page.click('.b-search button');
      await expect(page.locator('.b-order').first()).toContainText('لباس تست خودکار');
    }
    await page.fill('#b-oq', 'هیچ‌کس-نیست'); await page.click('.b-search button');
    await expect(page.locator('.b-empty')).toContainText('پیدا نشد');
    php(`wc_get_order(${id})->delete(true);`);
    expect(problems).toEqual([]);
  });

  test('لباس‌ها: کارت با قیمت و موجودی، تب حراج/تمام‌شده، دکمه ویرایش و موجودی', async ({ page }) => {
    const f = fixture();
    await login(page, 'manager');
    await page.goto('/wp-admin/admin.php?page=boutique-products&tab=sale&q=' + encodeURIComponent('لباس تست'));
    const card = page.locator('.b-pcard', { hasText: 'لباس تست خودکار' });
    await expect(card).toHaveCount(1);
    await expect(card).toContainText('حراج');
    await expect(card.locator('.b-state.is-go')).toContainText('عدد');
    await expect(card.locator('a', { hasText: 'ویرایش' })).toHaveAttribute('href', new RegExp(`page=boutique-product&product=${f.id}`));
    await expect(card.locator('a', { hasText: 'موجودی' })).toHaveAttribute('href', /boutique-stock/);
    await page.goto('/wp-admin/admin.php?page=boutique-products&tab=out&q=' + encodeURIComponent('لباس تست'));
    await expect(page.locator('.b-pcard', { hasText: 'لباس تست خودکار' })).toHaveCount(0);   // still has stock
  });
});

test('کلیک روی هر جای کارت لباس، فرم ویرایش را باز می‌کند؛ دکمه «موجودی» همچنان کار خودش را می‌کند', async ({ page }) => {
  const f = fixture();
  await login(page, 'manager');
  await page.goto('/wp-admin/admin.php?page=boutique-products&q=' + encodeURIComponent('لباس تست'));
  const card = page.locator('.b-pcard', { hasText: 'لباس تست خودکار' });
  const box = await card.boundingBox();
  await page.mouse.click(box.x + box.width * 0.45, box.y + 12);           // empty area near the top
  await expect(page).toHaveURL(new RegExp(`page=boutique-product&product=${f.id}`));
  await page.goBack();
  await page.locator('.b-pcard', { hasText: 'لباس تست خودکار' }).locator('a', { hasText: 'موجودی' }).click();
  await expect(page).toHaveURL(/boutique-stock/);
});

test('پیش‌نویس: نشان «تمام شد» ندارد و در تب تمام‌شده نیست؛ دکمه «حذف» (فقط برای پیش‌نویس) آن را حذف می‌کند', async ({ page }) => {
  const problems = watchForErrors(page);
  // A sold-out draft (stock 0) next to the fixture, which is published.
  const id = Number(php(`$p = new WC_Product_Simple(); $p->set_name('پیش‌نویس تست حذف'); $p->set_status('draft');
    $p->set_regular_price(100000); $p->set_manage_stock(true); $p->set_stock_quantity(0); echo $p->save();`).split('\n').pop());
  try {
    await login(page, 'manager');
    const q = '&q=' + encodeURIComponent('تست');
    await page.goto('/wp-admin/admin.php?page=boutique-products' + q);
    const card = page.locator('.b-pcard', { hasText: 'پیش‌نویس تست حذف' });
    await expect(card.locator('.b-state', { hasText: 'پیش‌نویس' })).toBeVisible();
    await expect(card.locator('.b-state.is-out')).toHaveCount(0);
    await expect(page.locator('.b-pcard', { hasText: 'لباس تست خودکار' }).locator('button', { hasText: 'حذف' })).toHaveCount(0); // published: no delete
    await page.goto('/wp-admin/admin.php?page=boutique-products&tab=out' + q);
    await expect(page.locator('.b-pcard', { hasText: 'پیش‌نویس تست حذف' })).toHaveCount(0);
    await page.goto('/wp-admin/admin.php?page=boutique-stock');
    await expect(page.locator('.b-sp', { hasText: 'پیش‌نویس تست حذف' }).locator('.b-stock.is-out')).toHaveCount(0);

    // Delete from the editor's header too; here from the list, accepting the confirm.
    await page.goto(`/wp-admin/admin.php?page=boutique-product&product=${id}`);
    await expect(page.locator('.b-head__actions button', { hasText: 'حذف' })).toBeVisible();
    await page.goto('/wp-admin/admin.php?page=boutique-products' + q);
    page.once('dialog', (d) => d.accept());
    await page.locator('.b-pcard', { hasText: 'پیش‌نویس تست حذف' }).locator('button', { hasText: 'حذف' }).click();
    await expect(page.locator('.b-done')).toContainText('«پیش‌نویس تست حذف» حذف شد');
    await expect(page.locator('.b-pcard', { hasText: 'پیش‌نویس تست حذف' })).toHaveCount(0);
    expect(php(`echo get_post_status(${id});`)).toBe('trash');
    expect(problems).toEqual([]);
  } finally {
    php(`wp_delete_post(${id}, true);`);
  }
});

test('ویرایش از صفحه ۲ پیش‌نویس‌ها: بعد از ذخیره به همان صفحه برمی‌گردد؛ اگر آن صفحه خالی شد، به آخرین صفحه', async ({ page }) => {
  const problems = watchForErrors(page);
  const ids = php(`$ids = []; for ($i = 1; $i <= 25; $i++) { $p = new WC_Product_Simple(); $p->set_name('صفحه‌بندی تست ' . $i);
    $p->set_status('draft'); $p->set_regular_price(100000); $p->set_manage_stock(true); $p->set_stock_quantity(1); $ids[] = $p->save(); }
    echo implode(',', $ids);`).split('\n').pop();
  try {
    await login(page, 'manager');
    const q = encodeURIComponent('صفحه‌بندی تست');
    await page.goto(`/wp-admin/admin.php?page=boutique-products&tab=draft&q=${q}&paged=2`);
    await expect(page.locator('.b-pcard')).toHaveCount(1);                       // 24 per page → 1 on page 2
    const name = (await page.locator('.b-pcard__name').first().textContent()).trim();

    // Edit → save: back on page 2 of the same tab + search, with the message.
    await page.locator('.b-pcard').first().locator('a', { hasText: 'ویرایش' }).click();
    await expect(page.locator('#b-pe-name')).toHaveValue(name);
    await page.click('[data-status="draft"]');
    await expect(page.locator('.b-done')).toContainText(`«${name}»`, { timeout: 30_000 });
    await expect(page).toHaveURL(/page=boutique-products/);
    await expect(page).toHaveURL(/tab=draft/);
    await expect(page).toHaveURL(/paged=2/);
    // Pager above and below the list, both on page 2.
    await expect(page.locator('.b-pager--top [aria-current="page"]')).toHaveText('۲');
    await expect(page.locator('.b-pager--bottom [aria-current="page"]')).toHaveText('۲');
    await expect(page.locator('.b-pager--top .b-pager__step', { hasText: 'بعدی' })).not.toHaveAttribute('href', /./);   // last page
    await expect(page.locator('.b-pager--top a', { hasText: 'قبلی' })).toHaveAttribute('href', /tab=draft/);
    await expect(page.locator('.b-pcard', { hasText: name })).toBeVisible();

    // Delete the only item on page 2: page 2 no longer exists, so the (new) last page is shown, not an empty one.
    page.once('dialog', (d) => d.accept());
    await page.locator('.b-pcard', { hasText: name }).locator('button', { hasText: 'حذف' }).click();
    await expect(page.locator('.b-done')).toContainText('حذف شد');
    await expect(page).toHaveURL(/tab=draft/);
    await expect(page.locator('.b-pcard')).toHaveCount(24);
    await expect(page.locator('.b-pager')).toHaveCount(0);                          // one page left
    expect(problems).toEqual([]);
  } finally {
    php(`foreach ([${ids}] as $id) wp_delete_post($id, true);`);
  }
});

test('صفحه‌بندی روی موبایل: بالا و پایین لیست، کوتاه و در یک ردیف، حتی با صفحه‌های زیاد', async ({ page }) => {
  const ids = php(`$ids = []; for ($i = 1; $i <= 24 * 9 + 1; $i++) { $p = new WC_Product_Simple(); $p->set_name('صفحه‌زیاد تست ' . $i);
    $p->set_status('draft'); $p->set_regular_price(100000); $ids[] = $p->save(); } echo implode(',', $ids);`).split('\n').pop();
  try {
    await page.setViewportSize({ width: 375, height: 800 });
    await login(page, 'manager');
    const q = encodeURIComponent('صفحه‌زیاد تست');
    await page.goto(`/wp-admin/admin.php?page=boutique-products&tab=draft&q=${q}&paged=5`);   // 10 pages
    for (const where of ['top', 'bottom']) {
      const pager = page.locator(`.b-pager--${where}`);
      await expect(pager.locator('[aria-current="page"]')).toHaveText('۵');
      await expect(pager.locator('.b-pager__num')).toHaveText(['۱', '۴', '۵', '۶', '۱۰']);
      await expect(pager.locator('.b-pager__gap')).toHaveCount(2);
      const heights = await pager.locator('a, span').evaluateAll((els) => els.map((e) => e.getBoundingClientRect()));
      expect(new Set(heights.map((r) => Math.round(r.top))).size).toBe(1);          // one row
      expect(Math.min(...heights.map((r) => r.height))).toBeGreaterThanOrEqual(44); // finger-sized
    }
    // The top pager is above the first card; the bottom one below the last.
    const top = await page.locator('.b-pager--top').boundingBox();
    const first = await page.locator('.b-pcard').first().boundingBox();
    expect(top.y).toBeLessThan(first.y);
    await page.locator('.b-pager--top a', { hasText: 'بعدی' }).click();
    await expect(page.locator('.b-pager--top [aria-current="page"]')).toHaveText('۶');
    await page.locator('.b-pager--bottom a', { hasText: '۱۰' }).click();
    await expect(page.locator('.b-pcard')).toHaveCount(1);
  } finally {
    php(`foreach ([${ids}] as $id) wp_delete_post($id, true);`);
  }
});
