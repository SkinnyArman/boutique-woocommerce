const { test, expect } = require('@playwright/test');
const { login, fixture, stockOf, php, watchForErrors } = require('./helpers');

test('جدول موجودی: تغییر با عدد فارسی و دکمه +، یک بار ذخیره', async ({ page }) => {
  const f = fixture();
  const a = f.vars['black|40'];   // sold out
  const b = f.vars['black|38'];
  const bBefore = stockOf(b);
  const problems = watchForErrors(page);

  await login(page, 'manager');
  await page.goto(`/wp-admin/admin.php?page=boutique-stock&product=${f.id}`);
  await expect(page.locator('.b-sp')).toHaveCount(1);
  await expect(page.locator('#b-stock-save')).toBeDisabled();
  await expect(page.locator(`td:has(input[name="stock[${a}]"])`)).toHaveClass(/is-out/);

  await page.fill(`input[name="stock[${a}]"]`, '۶');
  await page.locator(`td:has(input[name="stock[${b}]"]) [data-step="1"]`).click();
  await expect(page.locator('#b-dirty')).toHaveText('۲ تغییر ذخیره نشده');
  await expect(page.locator(`td:has(input[name="stock[${a}]"])`)).not.toHaveClass(/is-out/);

  await page.click('#b-stock-save');
  await expect(page.locator('.notice-success:not(.pw_notice)')).toContainText('۲ مورد به‌روز شد');
  expect(stockOf(a)).toBe(6);
  expect(stockOf(b)).toBe(bBefore + 1);
  expect(php(`echo wc_get_product(${a})->get_stock_status();`)).toBe('instock'); // sellable again
  expect(problems).toEqual([]);
});

test('جدول موجودی: فیلتر دسته بدون از دست رفتن تغییرات', async ({ page }) => {
  const problems = watchForErrors(page);
  await login(page, 'manager');
  await page.goto('/wp-admin/admin.php?page=boutique-stock');
  const sel = page.locator('#b-stock-cat');
  await expect(sel).toBeVisible();

  // Pick a category that has at least one dress.
  const slugs = await page.locator('.b-sp').evaluateAll((els) => [...new Set(els.flatMap((e) => e.dataset.cats.trim().split(/\s+/).filter(Boolean)))]);
  const options = await sel.locator('option').evaluateAll((o) => o.map((x) => x.value).filter(Boolean));
  const slug = options.find((o) => slugs.includes(o));
  expect(slug, 'a category with dresses').toBeTruthy();

  // An unsaved edit in a dress outside the filter must survive switching.
  const first = page.locator('.b-sp input[data-orig]').first();
  await first.locator('xpath=..').locator('[data-step="1"]').click();
  await expect(page.locator('#b-dirty')).toHaveText('۱ تغییر ذخیره نشده');

  await sel.selectOption(slug);
  const all = await page.locator('.b-sp').count();
  const inCat = await page.locator(`.b-sp[data-cats*=" ${slug} "]`).count();
  await expect(page.locator('.b-sp:visible')).toHaveCount(inCat);
  expect(inCat).toBeLessThanOrEqual(all);
  await expect(page).toHaveURL(new RegExp(`cat=${encodeURIComponent(slug)}`));
  await expect(page.locator('#b-dirty')).toHaveText('۱ تغییر ذخیره نشده');

  await sel.selectOption('');
  await expect(page.locator('.b-sp:visible')).toHaveCount(all);

  // Opening with ?cat= starts filtered.
  page.on('dialog', (d) => d.accept());
  await page.goto(`/wp-admin/admin.php?page=boutique-stock&cat=${encodeURIComponent(slug)}`);
  await expect(page.locator('.b-sp:visible')).toHaveCount(inCat);
  expect(problems).toEqual([]);
});
