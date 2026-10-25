/**
 * License products: the registry other plugins register into (gatekeeper_license_products), the
 * transition default that keeps xp-pro served until the XP plugin registers it, the isolation
 * between two products sharing one store, the helper functions and the one-release facade.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const SITE = 'site-abc123';
const XP_KEY = /^XP-PRO-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/;
const TEST_KEY = /^TEST-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/;
const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

/** A second product: a site licence with its own id shape, two devices, no grace, a "forever" payment plan. */
const register = `
  gk_register_license_product('test', array(
    'label' => 'Test Pro', 'app_name' => 'Test App',
    'key_prefix' => 'TEST-', 'option_prefix' => 'gk_lic_test_',
    'account_param' => 'site_id', 'account_validator' => fn( $s ) => 1 === preg_match( '/\\Asite-[a-z0-9]{4,12}\\z/', $s ),
    'device_param' => 'install_id',
    'max_devices' => 2, 'grace_seconds' => 0,
    'plans' => fn() => array(
      'basic'   => array( 'price' => 1.00, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 0, 'store_product_id' => 'test_basic', 'on_sale' => true ),
      'forever' => array( 'price' => 9.00, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'test_forever', 'on_sale' => true ),
    ),
  ));
`;
const captureLog = `$logfile = tempnam( sys_get_temp_dir(), 'gklog' ); ini_set( 'error_log', $logfile );`;
const readLog = `$log = file_get_contents( $logfile ); unlink( $logfile );`;

const grantXp = (extra = {}) => ({
  action: 'grant', source: 'stripe', family_id: FAMILY, plan: 'monthly', expires_at: 1800000000, env: 'production',
  email: 'parent@doit.forthexp.com', refs: { sub: 'sub_100', pi: 'pi_100', cus: 'cus_100' }, ...extra,
});
const grantTest = (extra = {}) => ({
  action: 'grant', source: 'stripe', site_id: SITE, plan: 'basic', expires_at: 1800000000, env: 'production',
  email: 'owner@test.example', refs: { sub: 'sub_t1', pi: 'pi_t1', cus: 'cus_t1' }, ...extra,
});

describe('License products: registry', () => {
  it('the bootstrap registers xp-pro as the XP plugin will, with the defaults filled', () => {
    const res = xp(`
      $p = Gatekeeper_License_Products::get('xp-pro');
      echo json_encode([
        'label' => $p['label'], 'app' => $p['app_name'], 'prefix' => $p['option_prefix'], 'key' => $p['key_prefix'], 'groups' => $p['key_groups'],
        'alphabet' => $p['key_alphabet'], 'acct' => $p['account_param'], 'dev' => $p['device_param'], 'max' => $p['max_devices'],
        'grace' => $p['grace_seconds'], 'trial' => $p['trial_seconds'], 'checkout' => $p['checkout_product'], 'webhooks' => $p['webhooks_option'],
        'setup' => $p['stripe_setup_option'], 'route' => $p['route_prefix'], 'builtin' => $p['builtin'], 'settings' => array_keys($p['settings']),
        'subject' => $p['strings']['key_email_subject'], 'lines' => is_callable($p['strings']['key_email_lines']),
        'validators' => [is_callable($p['account_validator']), is_callable($p['device_validator'])],
        'plans' => array_keys(Gatekeeper_License_Products::plans('xp-pro')), 'ids' => array_keys(Gatekeeper_License_Products::all()),
      ]);
    `);
    assert.deepStrictEqual(res, {
      label: 'XP Pro', app: 'Do It For The XP', prefix: 'gk_xp_pro_', key: 'XP-PRO-', groups: 3,
      alphabet: 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', acct: 'family_id', dev: 'device_id', max: 10,
      grace: 259200, trial: 604800, checkout: 'doitforthexp', webhooks: 'gatekeeper_xp_pro_webhooks',
      setup: 'gatekeeper_xp_pro_stripe_setup', route: 'xp-pro', builtin: false, settings: ['ai_daily_limit', 'lifetime_on_sale'],
      subject: 'Your XP Pro key', lines: true, validators: [true, true], plans: ['monthly', 'yearly', 'lifetime'], ids: ['xp-pro'],
    });
  });

  it('a minimal product gets every default from its id and label', () => {
    const res = xp(`
      gk_register_license_product('acme-seo', array('label' => 'Acme SEO', 'app_name' => 'Acme'));
      $p = Gatekeeper_License_Products::get('acme-seo');
      echo json_encode([$p['key_prefix'], $p['option_prefix'], $p['route_prefix'], $p['account_param'], $p['device_param'], $p['webhooks_option'],
        $p['stripe_setup_option'], $p['checkout_product'], $p['max_devices'], $p['grace_seconds'], $p['trial_seconds'], $p['key_alphabet'],
        $p['strings']['key_email_subject'], $p['strings']['support_line'],
        call_user_func($p['strings']['key_email_lines'], ['license_key' => 'ACME-SEO-AAAA-BBBB-CCCC'])[2],
        Gatekeeper_License_Products::plans('acme-seo'), $p['settings']]);
    `);
    assert.deepStrictEqual(res, ['ACME-SEO-', 'gk_lic_acme_seo_', 'acme-seo', 'account_id', 'device_id', 'gatekeeper_acme_seo_webhooks',
      'gatekeeper_acme_seo_stripe_setup', 'acme-seo', 10, 259200, 604800, 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
      'Your Acme SEO key', 'Questions? Write to {support_email}.', 'Your key: ACME-SEO-AAAA-BBBB-CCCC', [], []]);
  });

  it('a malformed product is logged and skipped, a prefix collision too, and the sound ones stay', () => {
    const res = xp(`
      ${captureLog}
      gk_register_license_product('bad-devices', array('label' => 'Bad', 'max_devices' => 'ten'));
      $GLOBALS['gk_license_products']['Bad Id!'] = array('label' => 'X');
      $GLOBALS['gk_license_products']['notarray'] = 'nope';
      gk_register_license_product('twin', array('label' => 'Twin', 'option_prefix' => 'gk_xp_pro_'));
      gk_register_license_product('ok', array('label' => 'Ok'));
      $ids = array_keys(Gatekeeper_License_Products::all());
      ${readLog}
      echo json_encode(['ids' => $ids, 'bad' => Gatekeeper_License_Products::get('bad-devices'), 'twin' => Gatekeeper_License_Products::get('twin'),
        'log' => [str_contains($log, '"bad-devices" skipped: max_devices'), str_contains($log, '"Bad Id!" skipped'),
          str_contains($log, '"notarray" skipped: the config is not an array'),
          str_contains($log, '"twin" skipped: option_prefix "gk_xp_pro_" is already used by product "xp-pro"')]]);
    `);
    assert.deepStrictEqual(res, { ids: ['xp-pro', 'ok'], bad: null, twin: null, log: [true, true, true, true] });
  });

  it('by_route_prefix and by_webhook_id find the product and the source', () => {
    const res = xp(`
      ${register}
      update_option('gatekeeper_xp_pro_webhooks', ['stripe' => 11, 'stripe_test' => 12, 'revenuecat' => 13]);
      update_option('gatekeeper_test_webhooks', ['stripe' => 21]);
      $pick = function ($id) { $r = Gatekeeper_License_Products::by_webhook_id($id); return null === $r ? null : [$r[0]['id'], $r[1]]; };
      echo json_encode([
        'route' => Gatekeeper_License_Products::by_route_prefix('xp-pro')['id'], 'route_test' => Gatekeeper_License_Products::by_route_prefix('test')['id'],
        'route_none' => Gatekeeper_License_Products::by_route_prefix('nope'),
        'w11' => $pick(11), 'w12' => $pick(12), 'w13' => $pick('13'), 'w21' => $pick(21), 'w99' => $pick(99), 'w0' => $pick(0),
      ]);
    `);
    assert.deepStrictEqual(res, {
      route: 'xp-pro', route_test: 'test', route_none: null,
      w11: ['xp-pro', 'stripe'], w12: ['xp-pro', 'stripe'], w13: ['xp-pro', 'revenuecat'], w21: ['test', 'stripe'], w99: null, w0: null,
    });
  });

  it('settings are read and written through the schema with their types and defaults', () => {
    const res = xp(`
      $a = Gatekeeper_License_Products::read_setting('xp-pro', 'ai_daily_limit');
      $b = Gatekeeper_License_Products::read_setting('xp-pro', 'lifetime_on_sale');
      update_option('xp_pro_ai_daily_limit', '250'); update_option('xp_pro_lifetime_on_sale', 'off');
      $c = Gatekeeper_License_Products::read_setting('xp-pro', 'ai_daily_limit');
      $d = Gatekeeper_License_Products::read_setting('xp-pro', 'lifetime_on_sale');
      update_option('xp_pro_ai_daily_limit', 'lots');
      $e = Gatekeeper_License_Products::read_setting('xp-pro', 'ai_daily_limit');
      $w = Gatekeeper_License_Products::write_setting('xp-pro', 'ai_daily_limit', '42');
      $x = Gatekeeper_License_Products::write_setting('xp-pro', 'nope', 1);
      echo json_encode([$a, $b, $c, $d, $e, $w, get_option('xp_pro_ai_daily_limit'), $x, Gatekeeper_License_Products::read_setting('xp-pro', 'nope'),
        Gatekeeper_License_Products::settings('xp-pro'), Gatekeeper_License_Products::setting_schema('xp-pro', 'lifetime_on_sale')['option']]);
    `);
    assert.deepStrictEqual(res, [100, true, 250, false, 100, true, 42, false, null, { ai_daily_limit: 42, lifetime_on_sale: false }, 'xp_pro_lifetime_on_sale']);
  });
});

describe('License products: transition default', () => {
  it('without a registered xp-pro the built-in definition serves it and logs once per request', () => {
    const res = xp(`
      ${captureLog}
      $GLOBALS['gk_license_products'] = array(); Gatekeeper_License_Products::reset();
      $p = Gatekeeper_License_Products::get('xp-pro');
      Gatekeeper_License_Products::all(); Gatekeeper_License_Products::get('xp-pro');
      $plans = array_keys(Gatekeeper_License_Products::plans('xp-pro'));
      $lifetime = Gatekeeper_License_Store::lifetime_plan('xp-pro');
      $GLOBALS['gk_filters']['gatekeeper_xp_pro_plans'] = function ($v) { return ['monthly' => ['price' => 4.99, 'mode' => 'subscription', 'on_sale' => true]]; };
      $legacy = array_keys(Gatekeeper_License_Products::plans('xp-pro'));
      $r = Gatekeeper_License_Store::apply('xp-pro', ${lit(grantXp())}, 'evt_1');
      ${readLog}
      echo json_encode(['builtin' => $p['builtin'], 'prefix' => $p['option_prefix'], 'plans' => $plans, 'lifetime' => $lifetime, 'legacy' => $legacy,
        'key' => $r['record']['license_key'], 'stored' => isset($GLOBALS['gk_options']['gk_xp_pro_${FAMILY}']),
        'logs' => substr_count($log, 'no plugin registered the xp-pro product')]);
    `);
    assert.strictEqual(res.builtin, true);
    assert.strictEqual(res.prefix, 'gk_xp_pro_');
    assert.deepStrictEqual(res.plans, ['monthly', 'yearly', 'lifetime']);
    assert.strictEqual(res.lifetime, 'lifetime');
    assert.deepStrictEqual(res.legacy, ['monthly']);
    assert.match(res.key, XP_KEY);
    assert.strictEqual(res.stored, true);
    assert.strictEqual(res.logs, 1);
  });

  it('with xp-pro registered the built-in stays out and nothing is logged', () => {
    const res = xp(`
      ${captureLog}
      $p = Gatekeeper_License_Products::get('xp-pro'); Gatekeeper_License_Products::all();
      ${readLog}
      echo json_encode([$p['builtin'], $log]);
    `);
    assert.deepStrictEqual(res, [false, '']);
  });
});

describe('License products: two products share one store and never see each other', () => {
  it('keys, records and lookups are per product', () => {
    const res = xp(`
      ${register}
      $xp = Gatekeeper_License_Store::apply('xp-pro', ${lit(grantXp())}, 'evt_1');
      $t  = Gatekeeper_License_Store::apply('test', ${lit(grantTest())}, 'evt_1');
      $xk = $xp['record']['license_key']; $tk = $t['record']['license_key'];
      $names = array_keys($GLOBALS['gk_options']); sort($names);
      echo json_encode([
        'xk' => $xk, 'tk' => $tk,
        'record_fields' => [array_key_exists('family_id', $xp['record']), array_key_exists('site_id', $xp['record']), array_key_exists('site_id', $t['record']), array_key_exists('family_id', $t['record'])],
        'site' => $t['record']['site_id'], 'plan' => $t['record']['plan'],
        'cross_key' => [Gatekeeper_License_Store::find_by_key('xp-pro', $tk), Gatekeeper_License_Store::find_by_key('test', $xk)],
        'cross_shape' => [Gatekeeper_License_Store::is_license_key('xp-pro', $tk), Gatekeeper_License_Store::is_license_key('test', $xk), Gatekeeper_License_Store::is_license_key('test', $tk)],
        'cross_get' => [Gatekeeper_License_Store::get('xp-pro', '${SITE}'), Gatekeeper_License_Store::get('test', '${FAMILY}')],
        'cross_ref' => [Gatekeeper_License_Store::find_by_ref('xp-pro', 'sub', 'sub_t1'), Gatekeeper_License_Store::find_by_ref('test', 'sub', 'sub_t1')['site_id'] ?? null],
        'cross_email' => [count(Gatekeeper_License_Store::find_by_email('xp-pro', 'owner@test.example')), count(Gatekeeper_License_Store::find_by_email('test', 'owner@test.example'))],
        'validators' => [Gatekeeper_License_Store::is_account('test', '${SITE}'), Gatekeeper_License_Store::is_account('test', '${FAMILY}'), Gatekeeper_License_Store::is_account('xp-pro', '${SITE}')],
        'options' => $names,
      ]);
    `);
    assert.match(res.xk, XP_KEY);
    assert.match(res.tk, TEST_KEY);
    assert.deepStrictEqual(res.record_fields, [true, false, true, false]);
    assert.strictEqual(res.site, SITE);
    assert.strictEqual(res.plan, 'basic');
    assert.deepStrictEqual(res.cross_key, [null, null]);
    assert.deepStrictEqual(res.cross_shape, [false, false, true]);
    assert.deepStrictEqual(res.cross_get, [null, null]);
    assert.deepStrictEqual(res.cross_ref, [null, SITE]);
    assert.deepStrictEqual(res.cross_email, [0, 1]);
    assert.deepStrictEqual(res.validators, [true, false, false]);
    assert.ok(res.options.every((k) => k.startsWith('gk_xp_pro_') || k.startsWith('gk_lic_test_')), res.options.join());
    assert.ok(res.options.includes(`gk_lic_test_${SITE}`));
    assert.ok(res.options.includes(`gk_lic_test_key_${res.tk}`));
    assert.ok(res.options.includes(`gk_xp_pro_${FAMILY}`));
  });

  it('devices, grace and the public view follow each product', () => {
    const res = xp(`
      ${register}
      Gatekeeper_License_Store::apply('xp-pro', ${lit(grantXp())}, 'evt_1');
      Gatekeeper_License_Store::apply('test', ${lit(grantTest())}, 'evt_1');
      $one = Gatekeeper_License_Store::attach_device('test', '${SITE}', 'install-0001');
      $two = Gatekeeper_License_Store::attach_device('test', '${SITE}', 'install-0002');
      $three = Gatekeeper_License_Store::attach_device('test', '${SITE}', 'install-0003');
      $bad = Gatekeeper_License_Store::attach_device('test', '${SITE}', 'short');
      $wrong = Gatekeeper_License_Store::attach_device('test', '${FAMILY}', 'install-0001');
      $none = Gatekeeper_License_Store::attach_device('nope', '${SITE}', 'install-0001');
      $rec = ['status' => 'active', 'expires_at' => '2027-01-01T00:00:00Z'];
      $t = strtotime('2027-01-01T00:00:00Z');
      echo json_encode([
        'ok' => [$one === true, $two === true],
        'third' => [$three->get_error_code(), $three->get_error_data()['status'], $three->get_error_message()],
        'bad' => [$bad->get_error_code(), $bad->get_error_message()],
        'wrong' => $wrong->get_error_code(), 'none' => [$none->get_error_code(), $none->get_error_data()['status']],
        'grace' => [Gatekeeper_License_Store::is_entitled('test', $rec, $t - 1), Gatekeeper_License_Store::is_entitled('test', $rec, $t),
          Gatekeeper_License_Store::is_entitled('xp-pro', $rec, $t + 86400), Gatekeeper_License_Store::is_entitled('nope', $rec, $t - 1)],
        'view' => Gatekeeper_License_Store::public_view('test', Gatekeeper_License_Store::get('test', '${SITE}'), 1790000000),
        'xp_view_keys' => array_keys(Gatekeeper_License_Store::public_view('xp-pro', Gatekeeper_License_Store::get('xp-pro', '${FAMILY}'), 1790000000)),
      ]);
    `);
    assert.deepStrictEqual(res.ok, [true, true]);
    assert.deepStrictEqual(res.third, ['device_limit', 409, 'This license is on 2 devices already.']);
    assert.deepStrictEqual(res.bad, ['invalid_input', 'Invalid site_id or install_id.']);
    assert.strictEqual(res.wrong, 'invalid_input');
    assert.deepStrictEqual(res.none, ['invalid_input', 400]);
    assert.deepStrictEqual(res.grace, [true, false, true, false]);
    assert.deepStrictEqual(Object.keys(res.view).sort(), ['env', 'expires_at', 'license_key', 'plan', 'pro', 'site_id']);
    assert.strictEqual(res.view.site_id, SITE);
    assert.strictEqual(res.view.pro, true);
    assert.strictEqual(res.view.plan, 'basic');
    assert.deepStrictEqual(res.xp_view_keys.sort(), ['env', 'expires_at', 'family_id', 'license_key', 'plan', 'pro']);
  });

  it('the lifetime plan is the one sold as a payment, and an unknown plan falls back to the first subscription', () => {
    const res = xp(`
      ${register}
      $a = Gatekeeper_License_Store::apply('test', ${lit(grantTest({ plan: 'forever', expires_at: null, refs: { pi: 'pi_life', cus: 'cus_t1' } }))}, 'evt_1');
      $b = Gatekeeper_License_Store::apply('test', ['action' => 'extend', 'source' => 'stripe', 'site_id' => '${SITE}', 'expires_at' => 1810000000, 'refs' => ['sub' => 'sub_t9']], 'evt_2');
      $c = Gatekeeper_License_Store::apply('test', ${lit(grantTest({ site_id: 'site-other1', plan: 'platinum', refs: { sub: 'sub_t2', cus: 'cus_t2' } }))}, 'evt_3');
      $adm = Gatekeeper_License_Store::update_admin('test', 'site-other1', ['plan' => 'forever'], 3);
      $other = Gatekeeper_License_Store::get('test', 'site-other1');
      echo json_encode([
        'lifetime' => [$a['record']['plan'], $a['record']['expires_at'], $a['record']['lifetime_source']],
        'kept' => [$b['changed'], Gatekeeper_License_Store::get('test', '${SITE}')['expires_at']],
        'fallback' => $c['record']['plan'],
        'admin' => [$adm, $other['plan'], $other['expires_at']],
        'ids' => [Gatekeeper_License_Store::lifetime_plan('test'), Gatekeeper_License_Store::default_plan('test'), Gatekeeper_License_Store::lifetime_plan('xp-pro'), Gatekeeper_License_Store::plan_ids('xp-pro')],
      ]);
    `);
    assert.deepStrictEqual(res, {
      lifetime: ['forever', null, 'stripe:pi_life'],
      kept: [true, null],
      fallback: 'basic',
      admin: [true, 'forever', null],
      ids: ['forever', 'basic', 'lifetime', ['monthly', 'yearly', 'lifetime']],
    });
  });

  it('apply records the last event and fires gatekeeper_license_applied once; public_view passes its filter', () => {
    const res = xp(`
      ${register}
      $GLOBALS['gk_actions'] = [];
      $r = Gatekeeper_License_Store::apply('test', ${lit(grantTest())}, 'evt_42');
      $again = Gatekeeper_License_Store::apply('test', ${lit(grantTest())}, 'evt_42');
      $GLOBALS['gk_filters']['gatekeeper_license_public_view'] = function ($view, $record, $product) { $view['product'] = $product; return $view; };
      $view = Gatekeeper_License_Store::public_view('test', $r['record'], 1790000000);
      $hooks = array_values(array_filter($GLOBALS['gk_actions'], fn($a) => $a['hook'] === 'gatekeeper_license_applied'));
      echo json_encode([
        'last' => $r['record']['last_event'],
        'fired' => count($hooks), 'args' => [$hooks[0]['args'][0], $hooks[0]['args'][1]['action'], $hooks[0]['args'][2]['created_key']],
        'replay' => $again['changed'], 'view_product' => $view['product'] ?? null,
      ]);
    `);
    assert.strictEqual(res.last.id, 'evt_42');
    assert.strictEqual(res.last.action, 'grant');
    assert.strictEqual(res.last.source, 'stripe');
    assert.match(res.last.at, ISO);
    assert.strictEqual(res.fired, 1);
    assert.deepStrictEqual(res.args, ['test', 'grant', true]);
    assert.strictEqual(res.replay, false);
    assert.strictEqual(res.view_product, 'test');
  });
});

describe('License helpers', () => {
  it('gatekeeper_license_record and gatekeeper_license_entitled read one product', () => {
    const res = xp(`
      ${register}
      Gatekeeper_License_Store::apply('xp-pro', ${lit(grantXp())}, 'evt_1');
      Gatekeeper_License_Store::apply('test', ${lit(grantTest({ expires_at: 1790000000 }))}, 'evt_1');
      echo json_encode([
        'rec' => gatekeeper_license_record('xp-pro', '${FAMILY}')['family_id'] ?? null,
        'none' => gatekeeper_license_record('xp-pro', '${SITE}'),
        'ent' => [gatekeeper_license_entitled('xp-pro', '${FAMILY}', 1799999999), gatekeeper_license_entitled('xp-pro', '${FAMILY}', 1800000000 + 3 * 86400),
          gatekeeper_license_entitled('test', '${SITE}', 1789999999), gatekeeper_license_entitled('test', '${SITE}', 1790000000), gatekeeper_license_entitled('nope', '${FAMILY}', 1)],
        'fns' => array_map('function_exists', ['gatekeeper_license_record', 'gatekeeper_license_entitled', 'gatekeeper_license_for_key', 'gatekeeper_counter_bump', 'gatekeeper_rate_limited']),
      ]);
    `);
    assert.deepStrictEqual(res, { rec: FAMILY, none: null, ent: [true, false, true, false, false], fns: [true, true, true, true, true] });
  });

  it('gatekeeper_license_for_key returns the record on a known device, attaches a new one, or a coded error', () => {
    const res = xp(`
      ${register}
      $r = Gatekeeper_License_Store::apply('test', ${lit(grantTest())}, 'evt_1');
      $key = $r['record']['license_key'];
      $code = fn($x) => is_wp_error($x) ? [$x->get_error_code(), $x->get_error_data()['status']] : 'record';
      $first = gatekeeper_license_for_key('test', strtolower($key), 'install-0001');
      $second = gatekeeper_license_for_key('test', $key, 'install-0002');
      $third = gatekeeper_license_for_key('test', $key, 'install-0003');
      $again = gatekeeper_license_for_key('test', $key, 'install-0001');
      Gatekeeper_License_Store::apply('test', ['action' => 'expire', 'source' => 'stripe', 'site_id' => '${SITE}', 'refs' => ['sub' => 'sub_t1']], 'evt_2');
      $dead = gatekeeper_license_for_key('test', $key, 'install-0001');
      echo json_encode([
        'first' => [$code($first), $first['site_id'] ?? null, $first['devices'] ?? null],
        'second' => $code($second), 'third' => $code($third), 'again' => $code($again),
        'bad' => $code(gatekeeper_license_for_key('test', 'TEST-AAAA', 'install-0001')),
        'baddev' => $code(gatekeeper_license_for_key('test', $key, 'x')),
        'unknown' => $code(gatekeeper_license_for_key('test', 'TEST-AAAA-BBBB-CCCC', 'install-0001')),
        'other' => $code(gatekeeper_license_for_key('xp-pro', $key, 'install-0001')),
        'product' => $code(gatekeeper_license_for_key('nope', $key, 'install-0001')),
        'dead' => $code($dead),
      ]);
    `);
    assert.deepStrictEqual(res, {
      first: ['record', SITE, ['install-0001']], second: 'record', third: ['device_limit', 409], again: 'record',
      bad: ['invalid_input', 400], baddev: ['invalid_input', 400], unknown: ['not_found', 404], other: ['invalid_input', 400],
      product: ['invalid_input', 400], dead: ['pro_required', 402],
    });
  });

  it('gatekeeper_counter_bump counts inside a window and gatekeeper_rate_limited trips past max', () => {
    const res = xp(`
      Gatekeeper_License_Db::$now = 1790000045;
      $a = gatekeeper_counter_bump('Test AI fam', 60);
      $b = gatekeeper_counter_bump('Test AI fam', 60);
      $c = gatekeeper_counter_bump('Test AI fam', 60, -1);
      $name = Gatekeeper_License_Db::counter('Test AI fam', 60);
      Gatekeeper_License_Db::$now = 1790000101;
      $d = gatekeeper_counter_bump('Test AI fam', 60);
      $day = Gatekeeper_License_Db::counter('daily', 86400);
      $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
      $rl = [gatekeeper_rate_limited('test_claim', 2, 60, null, '${SITE}'), gatekeeper_rate_limited('test_claim', 2, 60, null, '${SITE}'),
        gatekeeper_rate_limited('test_claim', 2, 60, null, '${SITE}'), gatekeeper_rate_limited('test_claim', 2, 60, null, 'site-zzzz99'),
        gatekeeper_rate_limited('xp_pro_claim', 2, 60, null, '${SITE}')];
      echo json_encode([$a, $b, $c, $name, $d, $day, $rl, Gatekeeper_License_Db::client_ip(), array_keys($GLOBALS['wpdb']->counters)]);
    `);
    assert.deepStrictEqual(res.slice(0, 8), [1, 2, 1, 'gk_lic_ctr_testaifam_1790000100', 1, 'gk_lic_ctr_daily_1790035200', [false, false, true, false, false], '203.0.113.9']);
    assert.ok(res[8].some((k) => k.startsWith('gk_xppro_rl_test_claim_')), res[8].join());
  });
});

describe('License facade and alias', () => {
  it('Gatekeeper_Xp_Pro_Licenses delegates to the store for xp-pro and Gatekeeper_Xp_Pro_Db is Gatekeeper_License_Db', () => {
    const res = xp(`
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit(grantXp())}, 'evt_1');
      $key = $r['record']['license_key'];
      echo json_encode([
        'get' => Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}') === Gatekeeper_License_Store::get('xp-pro', '${FAMILY}'),
        'key' => Gatekeeper_Xp_Pro_Licenses::find_by_key($key)['family_id'],
        'ent' => Gatekeeper_Xp_Pro_Licenses::is_entitled($r['record'], 1790000000),
        'view' => Gatekeeper_Xp_Pro_Licenses::public_view($r['record'], 1790000000) === Gatekeeper_License_Store::public_view('xp-pro', $r['record'], 1790000000),
        'consts' => [Gatekeeper_Xp_Pro_Licenses::MAX_DEVICES, Gatekeeper_Xp_Pro_Licenses::OPTION_PREFIX, Gatekeeper_Xp_Pro_Licenses::GRACE_SECONDS],
        'shape' => [Gatekeeper_Xp_Pro_Licenses::is_license_key($key), Gatekeeper_Xp_Pro_Licenses::is_uuid4('${FAMILY}'), Gatekeeper_Xp_Pro_Licenses::is_device_id('abcd1234')],
        'alias' => [(new ReflectionClass('Gatekeeper_Xp_Pro_Db'))->getName(), Gatekeeper_Xp_Pro_Db::LOCK_WAIT, Gatekeeper_Xp_Pro_Db::ai_counter('${FAMILY}') === Gatekeeper_License_Db::daily_counter('${FAMILY}')],
      ]);
    `);
    assert.deepStrictEqual(res, {
      get: true, key: FAMILY, ent: true, view: true, consts: [10, 'gk_xp_pro_', 259200], shape: [true, true, true],
      alias: ['Gatekeeper_License_Db', 10, true],
    });
  });

  it('files contain no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-license-products.php', '../includes/class-gatekeeper-license-store.php', '../includes/class-gatekeeper-license-db.php',
      '../includes/gatekeeper-license-functions.php', '../includes/class-gatekeeper-xp-pro-licenses.php', '../includes/class-gatekeeper-xp-pro-db.php', 'license-products.test.mjs']) {
      const file = path.join(HERE, rel);
      if (fs.existsSync(file)) assertNoEmDashes(fs.readFileSync(file, 'utf8'), rel);
    }
  });
});
