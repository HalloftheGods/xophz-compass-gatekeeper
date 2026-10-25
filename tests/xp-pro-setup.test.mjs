/**
 * License setup API: the Stripe webhook is created server-side with Stripe's signing secret written
 * straight into Hookshot, never returned; settings writes stay inside the product's own options.
 * Runs for XP Pro (its two route paths and option names unchanged) and for a second product.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SIGNING = 'whsec_' + 'A'.repeat(32);

/** WordPress post, meta and Stripe stubs, then `code`. `stripeKey` is what Bazaar resolves. */
function setup(code, { stripeKey = 'sk_test_' + 'k'.repeat(24), stripeReply = null, admin = true } = {}) {
  const reply = stripeReply ?? { id: 'we_1', secret: SIGNING, livemode: false, object: 'webhook_endpoint' };
  return xp(`
    $GLOBALS['gk_posts'] = array(); $GLOBALS['gk_meta'] = array(); $GLOBALS['gk_deleted'] = array();
    function current_user_can( $cap ) { return ${admin ? 'true' : 'false'} && 'manage_options' === $cap; }
    function wp_insert_post( $p ) { $id = 100 + count( $GLOBALS['gk_posts'] ); $GLOBALS['gk_posts'][ $id ] = $p; return $id; }
    function get_post( $id ) { return isset( $GLOBALS['gk_posts'][ $id ] ) ? (object) array( 'ID' => $id ) : null; }
    function wp_delete_post( $id, $force = false ) { $GLOBALS['gk_deleted'][] = $id; unset( $GLOBALS['gk_posts'][ $id ] ); return true; }
    function update_post_meta( $id, $k, $v ) { $GLOBALS['gk_meta'][ $id ][ $k ] = $v; return true; }
    function wp_remote_request( $url, $args = array() ) { $GLOBALS['gk_http'][] = array( 'url' => $url, 'args' => $args ); return array( 'body' => '{}' ); }
    class Xophz_Bazaar_Stripe_Key { public static function resolve( $test = false ) { return ${JSON.stringify(stripeKey)}; } }
    class Hookshot_Webhook_Secrets { public static function save( $id, array $p ) { update_post_meta( $id, 'hookshot_signing_secret', $p['signing_secret'] ); } }
    class Xophz_Compass_Hookshot_REST { public static function receive_url( $id ) { return 'https://doit.forthexp.com/wp-json/xophz/v1/hookshot/receive/' . (int) $id; } }
    $GLOBALS['gk_http_reply'] = array( 'body' => json_encode( ${lit(reply)} ) );
    function gk_call( array $params, string $product = 'xp-pro' ) {
      $r = new WP_REST_Request( 'POST' );
      foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
      return Gatekeeper_License_Setup::stripe_webhook( $product, $r );
    }
    ${code}
  `);
}

describe('License setup: XP Pro Stripe webhook', () => {
  it('creates the Hookshot webhook and the Stripe endpoint, stores the secret, never returns it', () => {
    const res = setup(`
      $out = gk_call( array( 'mode' => 'test' ) );
      echo json_encode( array(
        'status'  => $out->get_status(),
        'body'    => $out->get_data(),
        'meta'    => $GLOBALS['gk_meta'][100],
        'stripe'  => $GLOBALS['gk_http'][0],
        'option'  => get_option( 'gatekeeper_xp_pro_webhooks' ),
      ) );
    `);
    assert.strictEqual(res.status, 201);
    assert.strictEqual(res.body.webhook_id, 100);
    assert.strictEqual(res.body.endpoint_url, 'https://doit.forthexp.com/wp-json/xophz/v1/hookshot/receive/100');
    assert.ok(!JSON.stringify(res.body).includes('whsec_'), 'the signing secret is never in the response');
    assert.strictEqual(res.meta.hookshot_signing_secret, SIGNING);
    assert.strictEqual(res.meta.hookshot_sig_header, 'Stripe-Signature');
    assert.strictEqual(res.meta.hookshot_verify_mode, 'hmac');
    assert.strictEqual(res.meta.hookshot_type, 'incoming');
    assert.strictEqual(res.stripe.url, 'https://api.stripe.com/v1/webhook_endpoints');
    assert.ok(res.stripe.args.headers.Authorization.startsWith('Bearer sk_test_'));
    assert.ok(!res.stripe.url.includes('sk_'), 'the key is never in the URL');
    const body = decodeURIComponent(res.stripe.args.body);
    for (const event of ['checkout.session.completed', 'checkout.session.async_payment_failed', 'invoice.paid', 'customer.subscription.deleted', 'charge.refunded']) {
      assert.ok(body.includes(event), `subscribes ${event}`);
    }
    assert.deepStrictEqual(res.option, { stripe_test: 100 });
  });

  it('a second call returns the existing setup without a new endpoint', () => {
    const res = setup(`
      gk_call( array( 'mode' => 'test' ) );
      $again = gk_call( array( 'mode' => 'test' ) );
      echo json_encode( array( 'status' => $again->get_status(), 'created' => $again->get_data()['created'], 'calls' => count( $GLOBALS['gk_http'] ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.created, false);
    assert.strictEqual(res.calls, 1);
  });

  it('live mode refuses a test key', () => {
    const res = setup(`
      $out = gk_call( array( 'mode' => 'live' ) );
      echo json_encode( array( 'status' => $out->get_status(), 'error' => $out->get_data()['error'], 'calls' => count( $GLOBALS['gk_http'] ) ) );
    `);
    assert.strictEqual(res.status, 424);
    assert.strictEqual(res.error, 'stripe_key_missing');
    assert.strictEqual(res.calls, 0);
  });

  it('a Stripe error removes the half-made Hookshot webhook', () => {
    const res = setup(`
      $out = gk_call( array( 'mode' => 'test' ) );
      echo json_encode( array( 'status' => $out->get_status(), 'deleted' => $GLOBALS['gk_deleted'], 'option' => get_option( 'gatekeeper_xp_pro_webhooks', null ) ) );
    `, { stripeReply: { error: { message: 'Invalid URL' } } });
    assert.strictEqual(res.status, 502);
    assert.deepStrictEqual(res.deleted, [100]);
    assert.strictEqual(res.option, null);
  });

  it('only administrators pass the permission check', () => {
    const res = setup(`echo json_encode( array( 'admin' => Gatekeeper_License_Setup::is_admin() ) );`, { admin: false });
    assert.strictEqual(res.admin, false);
  });
});

describe('License setup: XP Pro settings', () => {
  it('writes webhook slots, the AI limit and the lifetime sale, and ignores site-wide security settings', () => {
    const res = setup(`
      update_option( 'siteurl', 'http://doit.forthexp.com' );
      update_option( 'compass_trusted_redirect_domains', array( 'forthexp.com' ) );
      $r = new WP_REST_Request( 'POST' );
      $r->set_param( 'webhooks', array( 'revenuecat' => 7, 'stripe' => 5 ) );
      $r->set_param( 'ai_daily_limit', 50 );
      $r->set_param( 'lifetime_on_sale', false );
      $r->set_param( 'https_siteurl', true );
      $r->set_param( 'trusted_redirect_domains_add', array( 'evil.example.org' ) );
      $out = Gatekeeper_License_Setup::update_settings( 'xp-pro', $r );
      echo json_encode( array( 'status' => $out->get_status(), 'body' => $out->get_data() ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.deepStrictEqual(res.body.webhooks, { revenuecat: 7, stripe: 5 });
    assert.strictEqual(res.body.ai_daily_limit, 50);
    assert.strictEqual(res.body.lifetime_on_sale, false);
    assert.strictEqual(res.body.siteurl, 'http://doit.forthexp.com');
    assert.deepStrictEqual(res.body.trusted_redirect_domains, ['forthexp.com']);
  });

  it('rejects an AI limit out of range', () => {
    const res = setup(`
      $r = new WP_REST_Request( 'POST' );
      $r->set_param( 'ai_daily_limit', 0 );
      $out = Gatekeeper_License_Setup::update_settings( 'xp-pro', $r );
      echo json_encode( array( 'status' => $out->get_status() ) );
    `);
    assert.strictEqual(res.status, 400);
  });

  it('the stripe_test slot written here is the one the webhook lookup routes to the Stripe adapter', () => {
    const res = setup(`
      update_option( 'gatekeeper_xp_pro_webhooks', array( 'stripe_test' => 9, 'revenuecat' => 8 ) );
      $source = function ( $id ) { $m = Gatekeeper_License_Products::by_webhook_id( $id ); return null === $m ? null : array( $m[0]['id'], $m[1] ); };
      echo json_encode( array( 'test' => $source( 9 ), 'rc' => $source( 8 ), 'none' => $source( 10 ) ) );
    `);
    assert.deepStrictEqual(res, { test: ['xp-pro', 'stripe'], rc: ['xp-pro', 'revenuecat'], none: null });
  });

  it('files contain no em dashes', () => {
    for (const f of ['../includes/class-gatekeeper-license-setup.php', 'xp-pro-setup.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, f), 'utf8'), f);
    }
  });
});

/** A second product with a settings schema of its own: an int with a range, a bool and a text. */
const ACME = `
  gk_register_license_product( 'acme', array(
    'label' => 'Acme Pro', 'app_name' => 'Acme Quest', 'key_prefix' => 'ACME-', 'option_prefix' => 'gk_lic_acme_',
    'account_param' => 'site_id', 'checkout_product' => 'acmepro',
    'webhooks_option' => 'gatekeeper_acme_webhooks', 'stripe_setup_option' => 'gatekeeper_acme_stripe_setup', 'route_prefix' => 'acme',
    'plans' => fn () => array(),
    'settings' => array(
      'seats'   => array( 'label' => 'Seats', 'type' => 'int', 'option' => 'acme_seats', 'default' => 3, 'min' => 2, 'max' => 5 ),
      'beta'    => array( 'label' => 'Beta', 'type' => 'bool', 'option' => 'acme_beta', 'default' => false ),
      'tagline' => array( 'label' => 'Tagline', 'type' => 'string', 'option' => 'acme_tagline', 'default' => 'hello' ),
    ),
  ) );
`;

describe('License setup: a second product', () => {
  it('its Stripe webhook is named for it and recorded in its own options', () => {
    const res = setup(`
      ${ACME}
      $out = gk_call( array( 'mode' => 'test' ), 'acme' );
      echo json_encode( array(
        'status' => $out->get_status(), 'webhook' => $out->get_data()['webhook_id'],
        'title'  => $GLOBALS['gk_posts'][100]['post_title'],
        'desc'   => $GLOBALS['gk_http'][0]['args']['body'],
        'acme'   => get_option( 'gatekeeper_acme_webhooks', null ), 'acme_setup' => array_keys( get_option( 'gatekeeper_acme_stripe_setup', array() ) ),
        'xp'     => get_option( 'gatekeeper_xp_pro_webhooks', null ), 'xp_setup' => get_option( 'gatekeeper_xp_pro_stripe_setup', null ),
      ) );
    `);
    assert.strictEqual(res.status, 201);
    assert.strictEqual(res.title, 'Acme Pro Stripe (test)');
    assert.ok(decodeURIComponent(res.desc.replace(/\+/g, ' ')).includes('description=Acme Quest: Acme Pro (Gatekeeper via Hookshot)'));
    assert.deepStrictEqual(res.acme, { stripe_test: 100 });
    assert.deepStrictEqual(res.acme_setup, ['test']);
    assert.strictEqual(res.xp, null);
    assert.strictEqual(res.xp_setup, null);
  });

  it('XP Pro keeps its wording: the webhook title and the Stripe description', () => {
    const res = setup(`
      gk_call( array( 'mode' => 'test' ) );
      echo json_encode( array( $GLOBALS['gk_posts'][100]['post_title'], $GLOBALS['gk_http'][0]['args']['body'], array_keys( get_option( 'gatekeeper_xp_pro_stripe_setup', array() ) ) ) );
    `);
    assert.strictEqual(res[0], 'XP Pro Stripe (test)');
    assert.ok(decodeURIComponent(res[1].replace(/\+/g, ' ')).includes('description=Do It For The XP: XP Pro (Gatekeeper via Hookshot)'));
    assert.deepStrictEqual(res[2], ['test']);
  });

  it('the settings routes read and write the product schema and nothing of the other product', () => {
    const res = setup(`
      ${ACME}
      $get = function ( $id ) { return Gatekeeper_License_Setup::get_settings( $id )->get_data(); };
      $before = $get( 'acme' );
      $r = new WP_REST_Request( 'POST' );
      $r->set_param( 'seats', '4' ); $r->set_param( 'beta', true ); $r->set_param( 'tagline', 'Questing' ); $r->set_param( 'ai_daily_limit', 7 );
      $r->set_param( 'webhooks', array( 'stripe' => 61 ) );
      $out = Gatekeeper_License_Setup::update_settings( 'acme', $r );
      echo json_encode( array(
        'before' => $before, 'status' => $out->get_status(), 'after' => $out->get_data(),
        'options' => array( get_option( 'acme_seats' ), get_option( 'acme_beta' ), get_option( 'acme_tagline' ), get_option( 'xp_pro_ai_daily_limit', null ), get_option( 'gatekeeper_acme_webhooks' ), get_option( 'gatekeeper_xp_pro_webhooks', null ) ),
        'xp' => $get( 'xp-pro' ),
      ) );
    `);
    assert.deepStrictEqual([res.before.seats, res.before.beta, res.before.tagline], [3, false, 'hello']);
    assert.strictEqual(res.status, 200);
    assert.deepStrictEqual([res.after.seats, res.after.beta, res.after.tagline], [4, true, 'Questing']);
    assert.ok(!('ai_daily_limit' in res.after), 'the other product setting is not part of this product');
    assert.deepStrictEqual(res.after.webhooks, { stripe: 61 });
    assert.deepStrictEqual(res.options, [4, '1', 'Questing', null, { stripe: 61 }, null]);
    assert.deepStrictEqual([res.xp.ai_daily_limit, res.xp.lifetime_on_sale, res.xp.webhooks], [100, true, []]);
  });

  it('an int setting stays inside the min and max of its schema row', () => {
    const res = setup(`
      ${ACME}
      $try = function ( $v ) { $r = new WP_REST_Request( 'POST' ); $r->set_param( 'seats', $v ); $o = Gatekeeper_License_Setup::update_settings( 'acme', $r ); return array( $o->get_status(), get_option( 'acme_seats', null ), $o->get_data()['messages'] ?? null ); };
      echo json_encode( array( $try( 1 ), $try( 6 ), $try( 5 ), $try( 2 ) ) );
    `);
    assert.deepStrictEqual(res[0], [400, null, ['seats must be between 2 and 5']]);
    assert.deepStrictEqual(res[1], [400, null, ['seats must be between 2 and 5']]);
    assert.deepStrictEqual(res[2], [200, 5, null]);
    assert.deepStrictEqual(res[3], [200, 2, null]);
  });

  it('XP Pro keeps its settings response shape and its option storage', () => {
    const res = setup(`
      $before = Gatekeeper_License_Setup::get_settings( 'xp-pro' )->get_data();
      $r = new WP_REST_Request( 'POST' );
      $r->set_param( 'lifetime_on_sale', false ); $r->set_param( 'ai_daily_limit', '250' );
      Gatekeeper_License_Setup::update_settings( 'xp-pro', $r );
      $r2 = new WP_REST_Request( 'POST' ); $r2->set_param( 'lifetime_on_sale', 'true' );
      $after_true = Gatekeeper_License_Setup::update_settings( 'xp-pro', $r2 )->get_data()['lifetime_on_sale'];
      $r3 = new WP_REST_Request( 'POST' ); $r3->set_param( 'lifetime_on_sale', 'maybe' );
      $bad = Gatekeeper_License_Setup::update_settings( 'xp-pro', $r3 );
      echo json_encode( array( array_keys( $before ), $before['ai_daily_limit'], $before['lifetime_on_sale'], get_option( 'xp_pro_ai_daily_limit' ), get_option( 'xp_pro_lifetime_on_sale' ), $after_true, $bad->get_status(), $bad->get_data()['messages'] ) );
    `);
    assert.deepStrictEqual(res[0], ['webhooks', 'stripe_setup', 'ai_daily_limit', 'lifetime_on_sale', 'trusted_redirect_domains', 'siteurl', 'home']);
    assert.strictEqual(res[1], 100);
    assert.strictEqual(res[2], true);
    assert.strictEqual(res[3], 250);
    assert.strictEqual(res[4], '1', 'the last write was true');
    assert.strictEqual(res[5], true);
    assert.strictEqual(res[6], 400);
    assert.deepStrictEqual(res[7], ['lifetime_on_sale must be true or false']);
  });

  it('turning a bool setting off on a fresh site stores 0, so the sale really ends', () => {
    const res = setup(`
      $r = new WP_REST_Request( 'POST' ); $r->set_param( 'lifetime_on_sale', false );
      Gatekeeper_License_Setup::update_settings( 'xp-pro', $r );
      echo json_encode( array( get_option( 'xp_pro_lifetime_on_sale', null ), Gatekeeper_License_Products::read_setting( 'xp-pro', 'lifetime_on_sale' ) ) );
    `);
    assert.deepStrictEqual(res, ['0', false]);
  });

  it('a product nobody registered answers 404 on every setup call', () => {
    const res = setup(`
      $r = new WP_REST_Request( 'POST' );
      echo json_encode( array(
        Gatekeeper_License_Setup::stripe_webhook( 'nope', $r )->get_status(),
        Gatekeeper_License_Setup::get_settings( 'nope' )->get_status(),
        Gatekeeper_License_Setup::update_settings( 'nope', $r )->get_status(),
      ) );
    `);
    assert.deepStrictEqual(res, [404, 404, 404]);
  });
});
