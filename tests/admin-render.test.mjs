/**
 * The Gatekeeper Licenses admin page: one section per registered license product (settings form
 * from the product's schema, webhook id slots, license table with a Last event column), and the
 * admin_post handlers that take the product id and check a nonce per product. The xp-pro forms keep
 * the action names and notice keys they had before the page went generic.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const FAMILY_B = '7a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const SITE = 'site-abc123';
const ISO = /\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/;

/** The WordPress functions the page touches, a recording wpdb that lists options by prefix, and the second product. */
const PRELUDE = `
  require_once '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/admin/class-xophz-compass-gatekeeper-admin.php';
  class Gk_Redirect extends RuntimeException {}
  class Gk_Admin_Wpdb extends Gk_Fake_Wpdb {
    public function esc_like( $text ) { return (string) $text; }
    public function get_col( $sql ) {
      if ( ! preg_match( "/LIKE '(.*)%'/", $sql, $m ) ) { return array(); }
      return array_values( array_filter( array_keys( $GLOBALS['gk_options'] ), fn( $n ) => 0 === strpos( $n, $m[1] ) ) );
    }
  }
  $GLOBALS['wpdb']   = new Gk_Admin_Wpdb();
  $GLOBALS['gk_nonces'] = array();
  function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
  function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
  function esc_html__( $t, $d = '' ) { return $t; }
  function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
  function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
  function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '" />'; }
  function submit_button( $text ) { echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $text ) . '</button></p>'; }
  function current_user_can( $cap ) { return $GLOBALS['gk_can'] ?? true; }
  function get_current_user_id() { return 7; }
  function check_admin_referer( $action ) { $GLOBALS['gk_nonces'][] = $action; return true; }
  function wp_die( $message = '', $title = '', $args = array() ) { throw new RuntimeException( 'wp_die' ); }
  function wp_safe_redirect( $url ) { throw new Gk_Redirect( $url ); }
  function add_options_page( ...$args ) { $GLOBALS['gk_menu'] = $args; }
  function gk_render( $method = 'render_licenses_page' ) {
    $admin = new Xophz_Compass_Gatekeeper_Admin( 'gatekeeper', '1' );
    ob_start();
    $admin->$method();
    return ob_get_clean();
  }
  /** Run a handler as a posted form: [redirect url or false, wp_die thrown]. */
  function gk_post( $method, $post ) {
    $_POST = $post;
    $admin = new Xophz_Compass_Gatekeeper_Admin( 'gatekeeper', '1' );
    try {
      $admin->$method();
      return array( 'ran', null );
    } catch ( Gk_Redirect $r ) {
      return array( 'redirect', $r->getMessage() );
    } catch ( RuntimeException $e ) {
      return array( $e->getMessage(), null );
    }
  }
  gk_register_license_product( 'test', array(
    'label' => 'Test Pro', 'app_name' => 'Test App',
    'key_prefix' => 'TEST-', 'option_prefix' => 'gk_lic_test_',
    'account_param' => 'site_id', 'account_validator' => fn( $s ) => 1 === preg_match( '/\\Asite-[a-z0-9]{4,12}\\z/', $s ),
    'max_devices' => 2, 'grace_seconds' => 0,
    'plans' => fn() => array(
      'basic'   => array( 'price' => 1.00, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 0, 'store_product_id' => 'test_basic', 'on_sale' => true ),
      'forever' => array( 'price' => 9.00, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'test_forever', 'on_sale' => true ),
    ),
    'settings' => array(
      'seats'   => array( 'label' => 'Seats per site', 'type' => 'int', 'option' => 'test_seats', 'default' => 5, 'min' => 2 ),
      'beta'    => array( 'label' => 'Beta features', 'type' => 'bool', 'option' => 'test_beta', 'default' => false ),
      'tagline' => array( 'label' => 'Tagline', 'type' => 'string', 'option' => 'test_tagline', 'default' => 'Hello' ),
    ),
  ));
`;

/** One license per product, made through the store so each carries a real last_event. */
const SEED = `
  Gatekeeper_License_Store::apply( 'xp-pro', array(
    'action' => 'grant', 'source' => 'stripe', 'family_id' => '${FAMILY}', 'plan' => 'monthly', 'expires_at' => 1800000000,
    'env' => 'production', 'email' => 'parent@doit.forthexp.com', 'refs' => array( 'sub' => 'sub_100', 'pi' => 'pi_100', 'cus' => 'cus_100' ),
  ), 'evt_xp_1' );
  Gatekeeper_License_Store::apply( 'test', array(
    'action' => 'grant', 'source' => 'revenuecat', 'site_id' => '${SITE}', 'plan' => 'basic', 'expires_at' => 1800000000,
    'env' => 'sandbox', 'email' => 'owner@test.example', 'refs' => array( 'sub' => 'sub_t1', 'pi' => 'pi_t1', 'cus' => 'cus_t1' ),
  ), 'evt_t_1' );
`;

/** The rendered page after `setup` PHP ran (options, extra records), as a string. */
function page(setup = '', method = 'render_licenses_page') {
  return xp(`${PRELUDE}${SEED}${setup}\necho json_encode( gk_render( '${method}' ) );`);
}

/** Run a PHP snippet with the prelude and the seeded licenses loaded. */
function run(code) {
  return xp(`${PRELUDE}${SEED}${code}`);
}

/** The part of the page for one product. */
function section(html, id) {
  const start = html.indexOf(`<div class="gk-license-product" id="gk-product-${id}">`);
  assert.ok(start >= 0, `no section for ${id}`);
  const next = html.indexOf('<div class="gk-license-product"', start + 10);
  return html.slice(start, next < 0 ? undefined : next);
}

/** Every <input> of a chunk of HTML as {name: [attrs...]} parsed from its attribute list. */
function inputs(chunk) {
  const out = [];
  for (const m of chunk.matchAll(/<input ([^>]*)\/>/g)) {
    const attrs = {};
    for (const a of m[1].matchAll(/([a-z-]+)="([^"]*)"/g)) attrs[a[1]] = a[2];
    out.push(attrs);
  }
  return out;
}

describe('Gatekeeper Licenses page: layout', () => {
  it('registers the Settings page as Gatekeeper Licenses under the old slug', () => {
    const res = run(`
      $admin = new Xophz_Compass_Gatekeeper_Admin( 'gatekeeper', '1' );
      $admin->register_licenses_page();
      echo json_encode( array( $GLOBALS['gk_menu'][0], $GLOBALS['gk_menu'][1], $GLOBALS['gk_menu'][2], $GLOBALS['gk_menu'][3], Xophz_Compass_Gatekeeper_Admin::PAGE_XP_PRO ) );
    `);
    assert.deepStrictEqual(res, ['Gatekeeper Licenses', 'Gatekeeper Licenses', 'manage_options', 'gatekeeper-xp-pro', 'gatekeeper-xp-pro']);
  });

  it('shows the page title and one section per product, xp-pro first, each headed with its label', () => {
    const html = page();
    assert.ok(html.includes('<h1>Gatekeeper Licenses</h1>'));
    const xpAt = html.indexOf('id="gk-product-xp-pro"');
    const testAt = html.indexOf('id="gk-product-test"');
    assert.ok(xpAt > 0 && testAt > xpAt, 'xp-pro section first, test section second');
    assert.strictEqual((html.match(/class="gk-license-product"/g) || []).length, 2);
    assert.ok(section(html, 'xp-pro').includes('<h2>XP Pro</h2>'));
    assert.ok(section(html, 'test').includes('<h2>Test Pro</h2>'));
    assert.ok(section(html, 'xp-pro').includes('<h3>XP Pro licenses</h3>'));
    assert.ok(section(html, 'test').includes('<h3>Test Pro licenses</h3>'));
    assert.ok(section(html, 'xp-pro').includes('Save XP Pro settings'));
    assert.ok(section(html, 'test').includes('Save Test Pro settings'));
  });

  it('shows nothing to a user without manage_options', () => {
    const res = run(`
      $GLOBALS['gk_can'] = false;
      echo json_encode( array( gk_render( 'render_license_sections' ), gk_post( 'handle_save_xp_pro', array( 'ai_daily_limit' => '5' ) ), gk_post( 'handle_restore_xp_pro', array( 'family_id' => '${FAMILY}' ) ), gk_post( 'handle_update_xp_pro_license', array( 'family_id' => '${FAMILY}', 'plan' => 'yearly' ) ) ) );
    `);
    assert.deepStrictEqual(res, ['', ['wp_die', null], ['wp_die', null], ['wp_die', null]]);
  });

  it('the main Gatekeeper dashboard embeds the same sections', () => {
    const html = run(`
      define( 'XOPHZ_COMPASS_GATEKEEPER_URL', 'https://example.test/' );
      echo json_encode( gk_render( 'render_settings_page' ) );
    `);
    assert.ok(html.includes('id="gk-product-xp-pro"') && html.includes('id="gk-product-test"'));
  });

  it('keeps serving xp-pro from the transition default when no plugin registered it', () => {
    const res = xp(`${PRELUDE}
      $GLOBALS['gk_license_products'] = array();
      Gatekeeper_License_Products::reset();
      $html = gk_render( 'render_license_sections' );
      echo json_encode( array( substr_count( $html, 'class="gk-license-product"' ), str_contains( $html, 'id="gk-product-xp-pro"' ), str_contains( $html, 'name="ai_daily_limit"' ) ) );
    `);
    assert.deepStrictEqual(res, [1, true, true]);
  });
});

describe('Gatekeeper Licenses page: settings and webhook ids from the schema', () => {
  it('renders each product\'s schema rows as its own fields, with the stored values', () => {
    const html = page(`
      update_option( 'xp_pro_ai_daily_limit', 250 );
      update_option( 'xp_pro_lifetime_on_sale', false );
      update_option( 'test_seats', 8 );
    `);
    const xpSection = section(html, 'xp-pro');
    const xpInputs = inputs(xpSection);
    const limit = xpInputs.find((i) => i.name === 'ai_daily_limit');
    assert.strictEqual(limit.type, 'number');
    assert.strictEqual(limit.value, '250');
    assert.strictEqual(limit.min, '1');
    assert.ok(xpSection.includes('AI requests per family per day'));
    const sale = xpInputs.filter((i) => i.name === 'lifetime_on_sale');
    assert.deepStrictEqual(sale.map((i) => i.type + ':' + i.value + ':' + (i.checked || '')), ['hidden:0:', 'checkbox:1:']);
    assert.ok(xpSection.includes('Lifetime plan on sale'));
    assert.ok(!xpSection.includes('name="seats"') && !xpSection.includes('name="tagline"'));

    const testSection = section(html, 'test');
    const testInputs = inputs(testSection);
    const seats = testInputs.find((i) => i.name === 'seats');
    assert.deepStrictEqual([seats.type, seats.value, seats.min], ['number', '8', '2']);
    assert.deepStrictEqual(testInputs.filter((i) => i.name === 'beta').map((i) => i.type + ':' + i.value + ':' + (i.checked || '')), ['hidden:0:', 'checkbox:1:']);
    const tagline = testInputs.find((i) => i.name === 'tagline');
    assert.deepStrictEqual([tagline.type, tagline.value], ['text', 'Hello']);
    assert.ok(!testSection.includes('name="ai_daily_limit"') && !testSection.includes('name="lifetime_on_sale"'));
  });

  it('checks the boxes of bool settings that are on', () => {
    const html = page(`update_option( 'test_beta', true );`);
    const sale = inputs(section(html, 'xp-pro')).find((i) => i.type === 'checkbox');
    const beta = inputs(section(html, 'test')).find((i) => i.type === 'checkbox');
    assert.strictEqual(sale.checked, 'checked', 'lifetime_on_sale defaults to on');
    assert.strictEqual(beta.checked, 'checked');
  });

  it('shows the three webhook id slots of each product from its own webhooks option', () => {
    const html = page(`
      update_option( 'gatekeeper_xp_pro_webhooks', array( 'stripe' => 11, 'stripe_test' => 12, 'revenuecat' => 13 ) );
      update_option( 'gatekeeper_test_webhooks', array( 'stripe' => 21 ) );
    `);
    const slots = (id) => Object.fromEntries(inputs(section(html, id)).filter((i) => /_webhook_id$/.test(i.name || '')).map((i) => [i.name, i.value]));
    assert.deepStrictEqual(slots('xp-pro'), { stripe_webhook_id: '11', stripe_test_webhook_id: '12', revenuecat_webhook_id: '13' });
    assert.deepStrictEqual(slots('test'), { stripe_webhook_id: '21', stripe_test_webhook_id: '0', revenuecat_webhook_id: '0' });
    assert.ok(section(html, 'xp-pro').includes('Post id of the Hookshot webhook "XP Pro: Stripe" (mode HMAC, header Stripe-Signature).'));
    assert.ok(section(html, 'xp-pro').includes('Post id of the Hookshot webhook "XP Pro: RevenueCat" (mode Bearer).'));
    assert.ok(section(html, 'test').includes('Post id of the Hookshot webhook "Test Pro: Stripe (test)"'));
  });

  it('offers a slot for every webhook slot the intake reads', () => {
    const res = run(`echo json_encode( array( array_keys( Xophz_Compass_Gatekeeper_Admin::WEBHOOK_FIELDS ), array_keys( Gatekeeper_License_Products::WEBHOOK_SLOTS ) ) );`);
    assert.deepStrictEqual(res[0], res[1]);
  });
});

describe('Gatekeeper Licenses page: license table', () => {
  it('lists the columns, with Last event after Devices and an unnamed column for the actions', () => {
    const html = page();
    for (const id of ['xp-pro', 'test']) {
      const head = section(html, id).match(/<thead>(.*?)<\/thead>/s)[1];
      const cols = [...head.matchAll(/<th>(.*?)<\/th>/g)].map((m) => m[1]);
      assert.deepStrictEqual(cols, ['Key', 'Plan', 'Status', 'Expires (blank = never)', 'Env', 'Email', 'Devices', 'Last event', '']);
    }
  });

  it('puts each license in its own product\'s table, with that product\'s key, plans and device cap', () => {
    const html = page();
    const xpSection = section(html, 'xp-pro');
    const testSection = section(html, 'test');
    assert.match(xpSection, /<code>XP-PRO-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}<\/code>/);
    assert.match(testSection, /<code>TEST-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}<\/code>/);
    assert.ok(!xpSection.includes('TEST-') && !testSection.includes('XP-PRO-'));
    assert.ok(xpSection.includes('parent@doit.forthexp.com') && !xpSection.includes('owner@test.example'));
    assert.ok(testSection.includes('owner@test.example') && !testSection.includes('parent@doit.forthexp.com'));
    assert.match(xpSection, /<td>0 \/ 10<\/td>/);
    assert.match(testSection, /<td>0 \/ 2<\/td>/);
    const planOptions = (chunk) => [...chunk.match(/name="plan">(.*?)<\/select>/s)[1].matchAll(/<option value="([^"]*)"( selected="selected")?>/g)].map((m) => m[1] + (m[2] ? '*' : ''));
    assert.deepStrictEqual(planOptions(xpSection), ['monthly*', 'yearly', 'lifetime']);
    assert.deepStrictEqual(planOptions(testSection), ['basic*', 'forever']);
    const envSelected = (chunk) => chunk.match(/name="env">.*?<option value="([^"]*)" selected="selected"/s)[1];
    assert.strictEqual(envSelected(xpSection), 'production');
    assert.strictEqual(envSelected(testSection), 'sandbox');
  });

  it('shows the Last event cell: action, source and time', () => {
    const html = page();
    for (const [id, action, source] of [['xp-pro', 'grant', 'stripe'], ['test', 'grant', 'revenuecat']]) {
      const cell = section(html, id).match(/<td class="gk-last-event">(.*?)<\/td>/s)[1];
      assert.ok(cell.includes(`<strong>${action}</strong>`), cell);
      assert.ok(cell.includes(` via ${source}`), cell);
      assert.match(cell, ISO);
    }
  });

  it('says none yet for a license from before last_event existed', () => {
    const html = page(`
      $record = get_option( 'gk_xp_pro_${FAMILY}' );
      unset( $record['last_event'] );
      update_option( 'gk_xp_pro_${FAMILY}', $record );
    `);
    assert.match(section(html, 'xp-pro'), /<td class="gk-last-event"><span class="description">none yet<\/span><\/td>/);
    assert.match(section(html, 'test'), /<td class="gk-last-event"><strong>grant<\/strong>/);
  });

  it('escapes what a record carries into the cells', () => {
    const html = page(`
      $record = get_option( 'gk_xp_pro_${FAMILY}' );
      $record['last_event'] = array( 'id' => 'e', 'action' => '<b>x</b>', 'source' => '"><script>', 'at' => '2026-10-25T00:00:00Z' );
      $record['email'] = '"><img src=x>';
      update_option( 'gk_xp_pro_${FAMILY}', $record );
    `);
    assert.ok(!html.includes('<script>') && !html.includes('<img src=x>') && !html.includes('<b>x</b>'));
  });

  it('keeps a plan the catalog no longer offers selected so Save does not change it', () => {
    const html = page(`
      $record = get_option( 'gk_xp_pro_${FAMILY}' );
      $record['plan'] = 'retired';
      update_option( 'gk_xp_pro_${FAMILY}', $record );
    `);
    assert.match(section(html, 'xp-pro'), /name="plan"><option value="retired" selected="selected">retired<\/option>/);
  });

  it('says no licenses yet for a product without any', () => {
    const html = page(`delete_option( 'gk_lic_test_${SITE}' );`);
    assert.ok(section(html, 'test').includes('<p>No Test Pro licenses yet.</p>'));
    assert.ok(!section(html, 'test').includes('<table class="widefat'));
    assert.ok(section(html, 'xp-pro').includes('<table class="widefat'));
  });
});

describe('Gatekeeper Licenses page: forms', () => {
  it('the xp-pro forms post the same action names as before, with the product and the family', () => {
    const xpSection = section(page(), 'xp-pro');
    const forms = [...xpSection.matchAll(/<form ([^>]*)>(.*?)<\/form>/gs)].map((m) => ({
      attrs: m[1],
      fields: Object.fromEntries(inputs(m[2]).map((i) => [i.name, i.value])),
    }));
    assert.deepStrictEqual(forms.map((f) => f.fields.action), [
      'gatekeeper_xp_pro_save', 'gatekeeper_xp_pro_update_license', 'gatekeeper_xp_pro_reset', 'gatekeeper_xp_pro_restore',
    ]);
    for (const f of forms) {
      assert.ok(f.attrs.includes('method="post"') && f.attrs.includes('action="https://example.test/wp-admin/admin-post.php"'));
      assert.strictEqual(f.fields.product, 'xp-pro');
    }
    for (const f of forms.slice(1)) assert.strictEqual(f.fields.family_id, FAMILY);
    assert.strictEqual(forms[0].fields.family_id, undefined);
  });

  it('the second product posts the same actions with its own account field and its own nonce', () => {
    const html = page();
    const rowForms = (id) => [...section(html, id).matchAll(/<form ([^>]*)>(.*?)<\/form>/gs)].map((m) => ({
      fields: Object.fromEntries(inputs(m[2]).map((i) => [i.name, i.value])),
    }));
    const testForms = rowForms('test');
    assert.deepStrictEqual(testForms.map((f) => f.fields.action), [
      'gatekeeper_xp_pro_save', 'gatekeeper_xp_pro_update_license', 'gatekeeper_xp_pro_reset', 'gatekeeper_xp_pro_restore',
    ]);
    for (const f of testForms) assert.strictEqual(f.fields.product, 'test');
    for (const f of testForms.slice(1)) {
      assert.strictEqual(f.fields.site_id, SITE);
      assert.strictEqual(f.fields.family_id, undefined);
    }
    // One nonce per action and product.
    assert.deepStrictEqual(testForms.map((f) => f.fields._wpnonce), [
      'nonce:gatekeeper_xp_pro_save:test', 'nonce:gatekeeper_xp_pro_update_license:test', 'nonce:gatekeeper_xp_pro_reset:test', 'nonce:gatekeeper_xp_pro_restore:test',
    ]);
    assert.deepStrictEqual(rowForms('xp-pro').map((f) => f.fields._wpnonce), [
      'nonce:gatekeeper_xp_pro_save:xp-pro', 'nonce:gatekeeper_xp_pro_update_license:xp-pro', 'nonce:gatekeeper_xp_pro_reset:xp-pro', 'nonce:gatekeeper_xp_pro_restore:xp-pro',
    ]);
  });

  it('ties each row\'s fields to its Save form by an id of its own product and account', () => {
    const xpSection = section(page(), 'xp-pro');
    const formId = xpSection.match(/<form id="([^"]*)"/)[1];
    assert.strictEqual(formId, `gk-lic-xp-pro-${FAMILY}`);
    assert.strictEqual((xpSection.match(new RegExp(`form="${formId}"`, 'g')) || []).length, 5, 'plan, status, expires_at, env, email');
  });

  it('the page keeps the notice keys and their texts', () => {
    const notice = (key, product = '') =>
      page(`$_GET = array( 'xp_pro_notice' => '${key}'${product ? `, 'gatekeeper_product' => '${product}'` : ''} );`).match(/<div class="notice[^>]*><p>(.*?)<\/p><\/div>/)?.[1] ?? null;
    assert.strictEqual(notice('saved', 'xp-pro'), 'XP Pro settings saved.');
    assert.strictEqual(notice('saved', 'test'), 'Test Pro settings saved.');
    assert.strictEqual(notice('saved'), 'License settings saved.');
    assert.strictEqual(notice('reset', 'xp-pro'), 'License devices reset.');
    assert.strictEqual(notice('restored', 'xp-pro'), 'License restored to active status.');
    assert.strictEqual(notice('updated', 'xp-pro'), 'License updated.');
    assert.strictEqual(notice('unknown_product'), 'Unknown license product.');
    assert.strictEqual(notice('nonsense'), null);
    assert.ok(page(`$_GET = array( 'xp_pro_notice' => 'saved' );`).includes('<div class="notice notice-success is-dismissible">'));
    assert.strictEqual(page().includes('class="notice'), false, 'no notice without the arg');
  });
});

describe('Gatekeeper Licenses page: handlers', () => {
  const stored = (extra = '') => `
    echo json_encode( array(
      'family' => Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' ),
      'site' => Gatekeeper_License_Store::get( 'test', '${SITE}' ),
      'nonces' => $GLOBALS['gk_nonces'],
      ${extra}
    ) );
  `;

  it('saves the xp-pro form: webhook ids, int and bool settings; the other product is untouched', () => {
    const res = run(`
      update_option( 'test_seats', 5 );
      update_option( 'gatekeeper_test_webhooks', array( 'stripe' => 99 ) );
      $out = gk_post( 'handle_save_xp_pro', array( 'product' => 'xp-pro', 'stripe_webhook_id' => '31', 'stripe_test_webhook_id' => ' 32 ', 'revenuecat_webhook_id' => '33', 'ai_daily_limit' => '250', 'lifetime_on_sale' => '0' ) );
      echo json_encode( array( $out, get_option( 'gatekeeper_xp_pro_webhooks' ), get_option( 'xp_pro_ai_daily_limit' ), Gatekeeper_License_Products::read_setting( 'xp-pro', 'lifetime_on_sale' ),
        get_option( 'gatekeeper_test_webhooks' ), get_option( 'test_seats' ), $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, [
      ['redirect', 'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=saved&gatekeeper_product=xp-pro#gk-product-xp-pro'],
      { stripe: 31, stripe_test: 32, revenuecat: 33 }, 250, false,
      { stripe: 99 }, 5, ['gatekeeper_xp_pro_save:xp-pro'],
    ]);
  });

  it('saves the second product\'s settings through its own schema, options and webhooks option', () => {
    const res = run(`
      update_option( 'xp_pro_ai_daily_limit', 100 );
      $out = gk_post( 'handle_save_xp_pro', array( 'product' => 'test', 'stripe_webhook_id' => '21', 'seats' => '7', 'beta' => '1', 'tagline' => ' <b>Hi</b> there ' ) );
      echo json_encode( array( $out[1], get_option( 'gatekeeper_test_webhooks' ), get_option( 'test_seats' ), Gatekeeper_License_Products::read_setting( 'test', 'beta' ), get_option( 'test_tagline' ),
        get_option( 'xp_pro_ai_daily_limit' ), get_option( 'gatekeeper_xp_pro_webhooks', 'unset' ), $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, [
      'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=saved&gatekeeper_product=test#gk-product-test',
      { stripe: 21 }, 7, true, 'Hi there', 100, 'unset', ['gatekeeper_xp_pro_save:test'],
    ]);
  });

  it('keeps the old value of an int setting posted below its minimum or as junk, and ignores a setting that was not posted', () => {
    const res = run(`
      update_option( 'xp_pro_ai_daily_limit', 100 );
      update_option( 'test_seats', 6 );
      update_option( 'test_beta', true );
      gk_post( 'handle_save_xp_pro', array( 'product' => 'xp-pro', 'ai_daily_limit' => '0' ) );
      gk_post( 'handle_save_xp_pro', array( 'product' => 'test', 'seats' => '1' ) );
      $below = array( get_option( 'xp_pro_ai_daily_limit' ), get_option( 'test_seats' ) );
      gk_post( 'handle_save_xp_pro', array( 'product' => 'xp-pro', 'ai_daily_limit' => 'abc' ) );
      gk_post( 'handle_save_xp_pro', array( 'product' => 'test', 'seats' => '2.5' ) );
      $junk = array( get_option( 'xp_pro_ai_daily_limit' ), get_option( 'test_seats' ) );
      echo json_encode( array( $below, $junk, Gatekeeper_License_Products::read_setting( 'test', 'beta' ), get_option( 'xp_pro_lifetime_on_sale', 'unset' ) ) );
    `);
    assert.deepStrictEqual(res, [[100, 6], [100, 6], true, 'unset']);
  });

  it('a form that posts no stripe_test slot (the old form, or the legacy helper) leaves a wired test webhook alone', () => {
    const res = run(`
      update_option( 'gatekeeper_xp_pro_webhooks', array( 'stripe' => 1, 'stripe_test' => 5, 'revenuecat' => 3 ) );
      Xophz_Compass_Gatekeeper_Admin::save_xp_pro_settings( array( 'stripe_webhook_id' => '9', 'revenuecat_webhook_id' => 'x' ) );
      echo json_encode( get_option( 'gatekeeper_xp_pro_webhooks' ) );
    `);
    assert.deepStrictEqual(res, { stripe: 9, stripe_test: 5, revenuecat: 0 });
  });

  it('resets the devices of the posted product only', () => {
    const res = run(`
      Gatekeeper_License_Store::attach_device( 'xp-pro', '${FAMILY}', 'device-aaaa1111' );
      Gatekeeper_License_Store::attach_device( 'test', '${SITE}', 'device-bbbb2222' );
      $out = gk_post( 'handle_reset_xp_pro_devices', array( 'product' => 'test', 'site_id' => '${SITE}' ) );
      echo json_encode( array( $out, count( Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['devices'] ), count( Gatekeeper_License_Store::get( 'test', '${SITE}' )['devices'] ), $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, [
      ['redirect', 'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=reset&gatekeeper_product=test#gk-product-test'],
      1, 0, ['gatekeeper_xp_pro_reset:test'],
    ]);
  });

  it('an xp-pro form posted without a product (rendered before the update) still drives xp-pro', () => {
    const res = run(`
      Gatekeeper_License_Store::attach_device( 'xp-pro', '${FAMILY}', 'device-aaaa1111' );
      $out = gk_post( 'handle_reset_xp_pro_devices', array( 'family_id' => '${FAMILY}' ) );
      echo json_encode( array( $out[0], count( Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['devices'] ), $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, ['redirect', 0, ['gatekeeper_xp_pro_reset:xp-pro']]);
  });

  it('an account of another product\'s shape does nothing', () => {
    const res = run(`
      Gatekeeper_License_Store::attach_device( 'xp-pro', '${FAMILY}', 'device-aaaa1111' );
      gk_post( 'handle_reset_xp_pro_devices', array( 'product' => 'test', 'site_id' => '${FAMILY}' ) );
      gk_post( 'handle_reset_xp_pro_devices', array( 'product' => 'xp-pro', 'family_id' => '${SITE}' ) );
      gk_post( 'handle_restore_xp_pro', array( 'product' => 'test', 'family_id' => '${FAMILY}' ) );
      echo json_encode( count( Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['devices'] ) );
    `);
    assert.strictEqual(res, 1);
  });

  it('restores a license to active through the store with the product id', () => {
    const res = run(`
      Gatekeeper_License_Store::update_admin( 'test', '${SITE}', array( 'status' => 'revoked' ), 7 );
      Gatekeeper_License_Store::update_admin( 'xp-pro', '${FAMILY}', array( 'status' => 'revoked' ), 7 );
      $out = gk_post( 'handle_restore_xp_pro', array( 'product' => 'test', 'site_id' => '${SITE}' ) );
      echo json_encode( array( $out[1], Gatekeeper_License_Store::get( 'test', '${SITE}' )['status'], Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['status'], $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, [
      'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=restored&gatekeeper_product=test#gk-product-test',
      'active', 'revoked', ['gatekeeper_xp_pro_restore:test'],
    ]);
  });

  it('updates the editable fields of a license, and only to values that product offers', () => {
    const res = run(`
      $out = gk_post( 'handle_update_xp_pro_license', array( 'product' => 'test', 'site_id' => '${SITE}', 'plan' => 'monthly', 'status' => 'expired', 'env' => 'production', 'expires_at' => '2030-01-02 03:04:05', 'email' => 'new@test.example' ) );
      $test = Gatekeeper_License_Store::get( 'test', '${SITE}' );
      $ok = gk_post( 'handle_update_xp_pro_license', array( 'product' => 'test', 'site_id' => '${SITE}', 'plan' => 'forever', 'status' => 'active', 'env' => 'production', 'expires_at' => '', 'email' => 'new@test.example' ) );
      $after = Gatekeeper_License_Store::get( 'test', '${SITE}' );
      echo json_encode( array( $out[1], array( $test['plan'], $test['status'], $test['env'], $test['expires_at'], $test['email'] ),
        array( $after['plan'], $after['status'], $after['expires_at'] ), Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['plan'], $GLOBALS['gk_nonces'] ) );
    `);
    assert.deepStrictEqual(res, [
      'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=updated&gatekeeper_product=test#gk-product-test',
      ['basic', 'expired', 'production', '2030-01-02T03:04:05Z', 'new@test.example'],
      ['forever', 'active', null], 'monthly',
      ['gatekeeper_xp_pro_update_license:test', 'gatekeeper_xp_pro_update_license:test'],
    ]);
  });

  it('an unknown product goes back to the page with an error and changes nothing', () => {
    const res = run(`
      $before = Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' );
      $a = gk_post( 'handle_save_xp_pro', array( 'product' => 'nope', 'ai_daily_limit' => '5' ) );
      $b = gk_post( 'handle_reset_xp_pro_devices', array( 'product' => 'nope', 'family_id' => '${FAMILY}' ) );
      echo json_encode( array( $a, $b[1], get_option( 'xp_pro_ai_daily_limit', 'unset' ), Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' ) === $before ) );
    `);
    assert.deepStrictEqual(res, [
      ['redirect', 'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=unknown_product'],
      'https://example.test/wp-admin/options-general.php?page=gatekeeper-xp-pro&xp_pro_notice=unknown_product', 'unset', true,
    ]);
  });
});

describe('Gatekeeper Licenses page: hygiene', () => {
  it('registers the four xp-pro admin_post actions and files carry no em dashes', () => {
    const adminFile = path.join(HERE, '..', 'admin', 'class-xophz-compass-gatekeeper-admin.php');
    const source = fs.readFileSync(adminFile, 'utf8');
    for (const name of ['save', 'reset', 'restore', 'update_license']) {
      assert.ok(source.includes(`add_action( 'admin_post_gatekeeper_xp_pro_${name}'`), name);
    }
    assertNoEmDashes(source, 'admin class');
    assertNoEmDashes(fs.readFileSync(path.join(HERE, 'admin-render.test.mjs'), 'utf8'), 'admin-render.test.mjs');
  });
});
