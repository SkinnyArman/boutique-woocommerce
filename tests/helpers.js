const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const FIXTURE_FILE = path.join(__dirname, '.fixture.json');
const SHOP_FILE = path.join(__dirname, '.shop.json');
const USERS = {
  manager: [process.env.MANAGER_USER || 'manager', process.env.MANAGER_PASS || 'manager_local_pw'],
  admin: [process.env.ADMIN_USER || 'admin', process.env.ADMIN_PASS || 'admin_local_pw'],
};

function wpcli(args, input) {
  return execFileSync('docker', ['compose', '--profile', 'cli', 'run', '--rm', '-T', 'wpcli', ...args],
    { cwd: ROOT, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'ignore'] }).trim();
}
/** Run PHP inside WordPress and return what it echoes. */
const php = (code) => wpcli(['eval-file', '-'], '<?php ' + code);
const phpJson = (code) => JSON.parse(php(code).split('\n').pop());

const fixture = () => JSON.parse(fs.readFileSync(FIXTURE_FILE, 'utf8'));
/** The shop's details from config/shop.php, as WordPress sees them (written by global-setup). */
let shopCache;
const shop = () => (shopCache ??= fs.existsSync(SHOP_FILE)
  ? JSON.parse(fs.readFileSync(SHOP_FILE, 'utf8'))
  : phpJson('echo wp_json_encode(boutique_shop());'));
/** Escape a string for use inside a RegExp. */
const re = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const stockOf = (id) => Number(php(`echo (int) wc_get_product(${id})->get_stock_quantity();`));
const orderInfo = (id) => phpJson(`$o = wc_get_order(${id}); echo wp_json_encode(['status' => $o->get_status(), 'via' => $o->get_created_via(), 'total' => (float) $o->get_total(), 'pay' => $o->get_payment_method_title(), 'tracking' => $o->get_meta('_boutique_tracking'), 'phone' => $o->get_billing_phone()]);`);
/** An on-hold card-to-card order for one fixture variation, with stock reduced like a real checkout. */
const makeOnHoldOrder = (variationId) => Number(php(`
  add_filter('woocommerce_email_enabled_new_order', '__return_false');
  add_filter('woocommerce_email_enabled_customer_on_hold_order', '__return_false');
  $o = wc_create_order(['created_via' => 'checkout']);
  $o->add_product(wc_get_product(${variationId}), 1);
  $o->set_billing_first_name('مشتری'); $o->set_billing_last_name('تست'); $o->set_billing_phone('09120000000'); $o->set_billing_address_1('خیابان آزمایش ۱');
  $o->set_payment_method('bacs'); $o->set_payment_method_title('کارت به کارت');
  $o->calculate_totals(false); $o->save();
  $o->update_status('on-hold');
  echo $o->get_id();`).split('\n').pop());

const latin = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
/** "۴,۸۵۰,۰۰۰ تومان" -> 4850000 */
const toNumber = (s) => Number(latin(s).replace(/[^\d]/g, '') || 0);

/**
 * Log in by posting the login form directly (the login page's own script moves keyboard focus
 * right after load, which makes typing into it racy), then open the admin like a user would.
 */
async function login(page, role) {
  const [user, pass] = USERS[role];
  await page.context().clearCookies();
  await page.request.get('/wp-login.php'); // sets WordPress's test cookie
  const res = await page.request.post('/wp-login.php', { form: { log: user, pwd: pass, 'wp-submit': '1', testcookie: '1' } });
  if (!/wp-admin/.test(res.url())) throw new Error(`Login as ${role} failed (ended at ${res.url()})`);
  await page.goto(res.url());
}

/** Track requests that have started but not finished, to explain a stuck page. */
function trackRequests(page) {
  const open = new Set();
  page.on('request', (r) => open.add(r.url()));
  page.on('requestfinished', (r) => open.delete(r.url()));
  page.on('requestfailed', (r) => open.delete(r.url()));
  return {
    async waitForURL(re) {
      try { await page.waitForURL(re, { timeout: 30_000 }); }
      catch (e) { throw new Error(`Never reached ${re}. URL: ${page.url()}\nStill loading:\n${[...open].join('\n') || '(nothing)'}`); }
    },
  };
}

const adminMenu = async (page) =>
  (await page.locator('#adminmenu > li .wp-menu-name').allInnerTexts()).map((s) => s.replace(/[\d۰-۹]+/g, '').replace(/\s+/g, ' ').trim()).filter(Boolean);

/** Fails the test on JS errors / failed same-site requests. Call at the start of a test. */
function watchForErrors(page) {
  const problems = [];
  page.on('pageerror', (e) => problems.push('JS error: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.url()}`); });
  return problems;
}

module.exports = { ROOT, FIXTURE_FILE, SHOP_FILE, shop, re, wpcli, php, phpJson, fixture, stockOf, orderInfo, makeOnHoldOrder, latin, fa, toNumber, login, trackRequests, adminMenu, watchForErrors };
