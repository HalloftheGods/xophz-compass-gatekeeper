/**
 * XP Pro setup API: the Stripe webhook is created server-side with Stripe's signing secret written
 * straight into Hookshot, never returned; settings writes stay inside XP Pro's own options.
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
    function gk_call( array $params ) {
      $r = new WP_REST_Request( 'POST' );
      foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
      return Gatekeeper_Xp_Pro_Setup::stripe_webhook( $r );
    }
    ${code}
  `);
}

describe('XP Pro setup: Stripe webhook', () => {
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
    const res = setup(`echo json_encode( array( 'admin' => Gatekeeper_Xp_Pro_Setup::is_admin() ) );`, { admin: false });
    assert.strictEqual(res.admin, false);
  });
});

describe('XP Pro setup: settings', () => {
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
      $out = Gatekeeper_Xp_Pro_Setup::update_settings( $r );
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
      $out = Gatekeeper_Xp_Pro_Setup::update_settings( $r );
      echo json_encode( array( 'status' => $out->get_status() ) );
    `);
    assert.strictEqual(res.status, 400);
  });

  it('intake routes the stripe_test slot to the Stripe adapter', () => {
    const res = setup(`
      update_option( 'gatekeeper_xp_pro_webhooks', array( 'stripe_test' => 9, 'revenuecat' => 8 ) );
      echo json_encode( array( 'test' => Gatekeeper_Xp_Pro_Intake::source_for( 9 ), 'rc' => Gatekeeper_Xp_Pro_Intake::source_for( 8 ), 'none' => Gatekeeper_Xp_Pro_Intake::source_for( 10 ) ) );
    `);
    assert.deepStrictEqual(res, { test: 'stripe', rc: 'revenuecat', none: null });
  });

  it('files contain no em dashes', () => {
    for (const f of ['../includes/class-gatekeeper-xp-pro-setup.php', 'xp-pro-setup.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, f), 'utf8'), f);
    }
  });
});
