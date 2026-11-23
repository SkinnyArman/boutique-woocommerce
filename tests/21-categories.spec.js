const { test, expect } = require('@playwright/test');
const { login, php, watchForErrors } = require('./helpers');

const NAME = 'دسته تست خودکار';
const cleanup = () => php(`$t = get_term_by('name', '${NAME}', 'product_cat'); if ($t) wp_delete_term($t->term_id, 'product_cat');`);

test.describe('«دسته‌بندی‌ها»', () => {
  test.beforeAll(cleanup);
  test.afterAll(cleanup);

  test('مدیر همه دسته‌ها را می‌بیند و دسته جدید اضافه می‌کند؛ فوراً در فرم لباس و منوی سایت می‌آید', async ({ page }) => {
    const problems = watchForErrors(page);
    await login(page, 'manager');
    await page.locator('#adminmenu a', { hasText: 'دسته‌بندی‌ها' }).first().click();
    await expect(page).toHaveURL(/page=boutique-categories/);
    await expect(page.locator('.b-catlist li', { hasText: 'پیراهن مجلسی' })).toBeVisible();
    await expect(page.locator('.b-catlist')).not.toContainText('حذف');      // no delete anywhere

    await page.fill('#b-cat-name', '  ' + NAME + ' ');
    await page.click('.b-cats__add button[type="submit"]');
    await expect(page.locator('.b-done')).toContainText(`دسته «${NAME}» اضافه شد`);
    await expect(page.locator('.b-catlist li.is-new')).toContainText(NAME);
    await expect(page.locator('.b-catlist li').last()).toContainText(NAME);   // goes to the end

    // Same name again: refused.
    await page.fill('#b-cat-name', NAME);
    await page.click('.b-cats__add button[type="submit"]');
    await expect(page.locator('.notice-error')).toContainText('از قبل هست');
    expect(Number(php(`echo count(get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => '${NAME}']));`))).toBe(1);

    // In the product form…
    await page.goto('/wp-admin/admin.php?page=boutique-product');
    await expect(page.locator('label.b-chipcheck', { hasText: NAME })).toBeVisible();
    // …and in the site menu under «دسته‌بندی‌ها».
    await page.goto('/');
    await expect(page.locator('.b-menu-cats a', { hasText: NAME }).first()).toBeAttached();
    expect(problems).toEqual([]);
  });

  test('مدیر نمی‌تواند دسته را حذف کند؛ صفحه دسته‌های وردپرس او را به همین صفحه می‌برد', async ({ page }) => {
    const id = Number(php(`echo get_term_by('slug', 'evening', 'product_cat')->term_id;`));
    expect(php(`$u = get_user_by('login', 'manager'); echo user_can($u, 'delete_term', ${id}) ? 'yes' : 'no';`)).toBe('no');
    expect(php(`$u = get_user_by('login', 'admin'); echo user_can($u, 'delete_term', ${id}) ? 'yes' : 'no';`)).toBe('yes');
    await login(page, 'manager');
    await page.goto('/wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product');
    await expect(page).toHaveURL(/page=boutique-categories/);
  });
});
