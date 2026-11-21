const { test, expect } = require('@playwright/test');
const { fixture, php, shop, fa } = require('./helpers');

// Emails are caught before sending (pre_wp_mail) and returned as [to, subject, text].
const capture = (code) => JSON.parse(php(`
  $GLOBALS['caught'] = [];
  add_filter('pre_wp_mail', function ($r, $a) { $GLOBALS['caught'][] = [ $a['to'], $a['subject'], trim(preg_replace('/\\s+/u', ' ', wp_strip_all_tags(preg_replace('#<style.*?</style>#s', '', $a['message'])))) ]; return true; }, 10, 2);
  ${code}
  echo wp_json_encode($GLOBALS['caught'], JSON_UNESCAPED_UNICODE);`).split('\n').pop());
const order = (email, extra = '') => `
  $o = wc_create_order(['created_via' => 'checkout']);
  $o->add_product(wc_get_product(${fixture().vars['maroon|38']}), 1);
  $o->set_billing_first_name('مشتری'); $o->set_billing_phone('09120000004'); $o->set_billing_email('${email}');
  $o->set_payment_method('bacs'); $o->set_payment_method_title('کارت به کارت'); ${extra}
  $o->calculate_totals(false); $o->save();`;
const forCustomer = (mails) => mails.filter((m) => String(m[0]).includes('customer@example.com'));

test.describe('ایمیل به مشتری', () => {
  test('هر مرحله یک ایمیل: ثبت، تأیید پرداخت، ارسال با کد رهگیری، لغو؛ بدون ایمیل تکراری', () => {
    const m = forCustomer(capture(order('customer@example.com') + `
      $o->update_status('on-hold');
      $o->update_status('processing');
      $o->update_meta_data('_boutique_tracking', '123456789'); $o->add_order_note('سفارش شما تحویل پیک شد. کد رهگیری: 123456789', 1); $o->save();
      $o->update_status('completed'); $o->delete(true);`));
    const subjects = m.map((x) => x[1]);
    expect(subjects.some((s) => s.includes('ثبت شد؛ منتظر رسید واریز'))).toBe(true);
    expect(subjects.some((s) => s.includes('تأیید شد'))).toBe(true);
    const shipped = m.find((x) => x[1].includes('ارسال شد'));
    expect(shipped[2]).toContain('123456789');
    expect(shipped[2]).toContain('پیگیری سفارش');
    expect(m.length).toBe(3);                       // the tracking note didn't add a 4th email
    expect(m[0][2]).toContain(fa(shop().card_number));   // card number in the on-hold email
  });

  test('لغو بعد از پرداخت: «مبلغ را برمی‌گردانیم»', () => {
    const m = forCustomer(capture(order('customer@example.com') + `
      $o->update_status('on-hold'); $o->update_status('processing'); $GLOBALS['caught'] = [];
      $o->update_status('cancelled'); $o->delete(true);`));
    expect(m).toHaveLength(1);
    expect(m[0][1]).toContain('لغو شد');
    expect(m[0][2]).toContain('برمی‌گردانیم');
  });

  test('لغو خودکار (رسید نیامد) دلیلش را می‌گوید؛ بدون ایمیل، چیزی فرستاده نمی‌شود', () => {
    const m = forCustomer(capture(order('customer@example.com') + `
      $o->update_status('on-hold'); $o->set_date_created(time() - 25 * HOUR_IN_SECONDS); $o->save();
      $GLOBALS['caught'] = [];
      boutique_cancel_unpaid(); $o = wc_get_order($o->get_id()); $o->delete(true);`));
    expect(m).toHaveLength(1);
    expect(m[0][2]).toContain('۲۴ ساعت');
    const none = capture(order('') + `$o->update_status('on-hold'); $o->update_status('processing'); $o->delete(true);`);
    expect(none.filter((x) => !String(x[0]).includes('info@') && !String(x[0]).includes('admin@'))).toHaveLength(0);
  });

  test('نمونه‌ها: ۶ ایمیل، با عنوان «(نمونه)»، بدون ساختن سفارش', () => {
    const before = php("echo count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => 'any']));");
    const m = capture("boutique_send_email_samples('preview@example.com');");
    expect(m).toHaveLength(6);
    for (const x of m) expect(x[1]).toContain('(نمونه)');
    expect(m.map((x) => x[1]).join(' | ')).toContain('آماده تحویل است');
    expect(php("echo count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => 'any']));")).toBe(before);
  });
});
