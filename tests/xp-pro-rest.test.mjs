/**
 * License REST handlers: claim, validate, recover, manage and plans, called directly with a stub
 * request for XP Pro (status codes follow spec 3.4) and for a second product under its own prefix.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const OTHER_FAMILY = '9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d';
const DEVICE = 'devicetest01';
const EMAIL = 'parent@doit.forthexp.com';
const RETURN_URL = 'https://doit.forthexp.com/pro/return';
const SITE = 'site-abc123';

/** The second product, registered after XP Pro: a site license with its own fields, prefix and 2 devices. */
const ACME = `
  gk_register_license_product( 'acme', [
    'label' => 'Acme Pro', 'app_name' => 'Acme Quest', 'key_prefix' => 'ACME-', 'option_prefix' => 'gk_lic_acme_',
    'account_param' => 'site_id', 'account_validator' => fn ( $s ) => 1 === preg_match( '/\\Asite-[a-z0-9]{4,12}\\z/', $s ),
    'device_param' => 'install_id', 'max_devices' => 2, 'grace_seconds' => 0,
    'checkout_product' => 'acmepro', 'webhooks_option' => 'gatekeeper_acme_webhooks', 'stripe_setup_option' => 'gatekeeper_acme_stripe_setup',
    'route_prefix' => 'acme',
    'plans' => fn () => [
      'season'  => [ 'price' => 9, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 3, 'store_product_id' => 'acme_season', 'on_sale' => true ],
      'founder' => [ 'price' => 120, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'acme_founder', 'on_sale' => false ],
    ],
  ] );
`;

const SETUP = `
  $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
  class Xophz_Compass_Security {
    public static function is_allowed_redirect_url( string $url ): bool {
      return 0 === strpos( $url, 'https://doit.forthexp.com/' ) || 0 === strpos( $url, 'http://localhost' );
    }
  }
  class Xophz_Bazaar_Stripe_Key {
    public static $forced = [];
    public static function resolve( bool $force_test = false ): string { self::$forced[] = $force_test; return 'sk_test_unit_only'; }
  }
  function req( array $params ) {
    $r = new WP_REST_Request( 'POST', '/x' );
    foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
    return $r;
  }
  function out( $res ) { return [ 'status' => $res->get_status(), 'data' => $res->get_data() ]; }
  function grant( $family = '${FAMILY}', $extra = [] ) {
    return Gatekeeper_License_Store::apply( 'xp-pro', array_merge( [
      'action' => 'grant', 'source' => 'stripe', 'family_id' => $family, 'plan' => 'monthly',
      'expires_at' => time() + 86400 * 20, 'env' => 'production', 'email' => '${EMAIL}',
      'refs' => [ 'cus' => 'cus_100', 'sub' => 'sub_100' ],
    ], $extra ), 'evt_' . $family . count( $GLOBALS['gk_options'] ) );
  }
`;

const run = (code) => xp(`${SETUP}\n${code}`);

describe('XP Pro REST: claim', () => {
  it('claim returns 404 before any grant and 200 after', () => {
    const res = run(`
      $before = out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
      grant();
      $after = out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
      echo json_encode( [ $before, $after ] );
    `);
    assert.deepStrictEqual(res[0], { status: 404, data: { pro: false } });
    assert.strictEqual(res[1].status, 200);
    assert.strictEqual(res[1].data.pro, true);
    assert.strictEqual(res[1].data.family_id, FAMILY);
    assert.strictEqual(res[1].data.plan, 'monthly');
    assert.match(res[1].data.license_key, /^XP-PRO-/);
  });

  it('claim adds the device', () => {
    const res = run(`
      grant();
      Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) );
      echo json_encode( Gatekeeper_License_Store::get( 'xp-pro', '${FAMILY}' )['devices'] );
    `);
    assert.deepStrictEqual(res, [DEVICE]);
  });

  it('claim with a bad family_id or device_id is 400', () => {
    const res = run(`
      echo json_encode( [
        out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => 'nope', 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => 'x' ] ) ) ),
        out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => [ '${FAMILY}' ], 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) ) ),
      ] );
    `);
    for (const r of res) assert.deepStrictEqual(r, { status: 400, data: { error: 'invalid_input' } });
  });

  it('claim on an 11th device is 409 device_limit', () => {
    const res = run(`
      grant();
      for ( $i = 1; $i <= 10; $i++ ) { Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => 'device-number-' . $i ] ) ); }
      echo json_encode( out( Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => 'device-number-11' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 409, data: { error: 'device_limit' } });
  });
});

describe('XP Pro REST: validate', () => {
  it('validate returns the family so a restored device can adopt it', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      echo json_encode( out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => strtolower( $key ), 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.family_id, FAMILY);
    assert.strictEqual(res.data.pro, true);
  });

  it('validate an expired license returns pro false', () => {
    const res = run(`
      $key = grant( '${FAMILY}', [ 'expires_at' => time() - 86400 * 10 ] )['record']['license_key'];
      echo json_encode( out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $key, 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.pro, false);
    assert.strictEqual(res.data.family_id, FAMILY);
  });

  it('validate a revoked license returns pro false', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      Gatekeeper_License_Store::apply( 'xp-pro', [ 'action' => 'revoke', 'source' => 'stripe', 'family_id' => '${FAMILY}' ], 'evt_revoke' );
      echo json_encode( out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $key, 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.pro, false);
  });

  it('validate on an 11th device is 409 device_limit', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      for ( $i = 1; $i <= 10; $i++ ) { Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $key, 'device_id' => 'device-number-' . $i ] ) ); }
      echo json_encode( out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $key, 'device_id' => 'device-number-11' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 409, data: { error: 'device_limit' } });
  });

  it('validate: unknown key is 404, malformed key is 400', () => {
    const res = run(`
      echo json_encode( [
        out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => 'XP-PRO-AAAA-BBBB-CCCC', 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => 'CX-DEV-PREVIEW', 'device_id' => '${DEVICE}' ] ) ) ),
      ] );
    `);
    assert.deepStrictEqual(res[0], { status: 404, data: { pro: false } });
    assert.deepStrictEqual(res[1], { status: 400, data: { error: 'invalid_input' } });
  });
});

describe('XP Pro REST: recover', () => {
  it('recover answers 202 for a known and an unknown email and mails only the known one', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      $known   = out( Gatekeeper_License_REST::recover( 'xp-pro', req( [ 'email' => '${EMAIL.toUpperCase()}' ] ) ) );
      $unknown = out( Gatekeeper_License_REST::recover( 'xp-pro', req( [ 'email' => 'nobody@doit.forthexp.com' ] ) ) );
      echo json_encode( [ $known, $unknown, $GLOBALS['gk_mail'], $key ] );
    `);
    assert.strictEqual(res[0].status, 202);
    assert.strictEqual(res[1].status, 202);
    assert.deepStrictEqual(res[0].data, res[1].data);
    assert.strictEqual(res[2].length, 1);
    assert.strictEqual(res[2][0].to, EMAIL.toUpperCase());
    assert.ok(res[2][0].message.includes(res[3]));
  });

  it('recover with a malformed email is 400 and the 6th request in an hour is 429', () => {
    const res = run(`
      $bad = out( Gatekeeper_License_REST::recover( 'xp-pro', req( [ 'email' => 'not an email' ] ) ) );
      $codes = [];
      for ( $i = 0; $i < 6; $i++ ) { $codes[] = Gatekeeper_License_REST::recover( 'xp-pro', req( [ 'email' => 'nobody@doit.forthexp.com' ] ) )->get_status(); }
      echo json_encode( [ $bad, $codes ] );
    `);
    assert.deepStrictEqual(res[0], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[1], [202, 202, 202, 202, 429, 429]);
  });
});

describe('XP Pro REST: rate limit', () => {
  it('the 31st request in a minute is 429', () => {
    const res = run(`
      $codes = [];
      for ( $i = 0; $i < 31; $i++ ) { $codes[] = Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) )->get_status(); }
      echo json_encode( [ array_slice( $codes, 0, 30 ), $codes[30], out( Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) ) )['data'] ] );
    `);
    assert.ok(res[0].every((c) => c === 404));
    assert.strictEqual(res[1], 429);
    assert.deepStrictEqual(res[2], { error: 'rate_limited' });
  });

  it('limits are per client address and the window resets', () => {
    const res = run(`
      for ( $i = 0; $i < 30; $i++ ) { Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) ); }
      $same = Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) )->get_status();
      $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
      $other = Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) )->get_status();
      $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
      Gatekeeper_License_Db::$now = time() + 61;
      $later = Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) )->get_status();
      echo json_encode( [ $same, $other, $later ] );
    `);
    assert.deepStrictEqual(res, [429, 400, 400]);
  });

  it('X-Forwarded-For does not change the bucket', () => {
    const res = run(`
      for ( $i = 0; $i < 30; $i++ ) { Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) ); }
      $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
      echo json_encode( Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) )->get_status() );
    `);
    assert.strictEqual(res, 429);
  });
});

describe('XP Pro REST: rate limit buckets (F3)', () => {
  it('claim buckets are per family', () => {
    const res = run(`
      grant( '${FAMILY}' );
      grant( '${OTHER_FAMILY}' );
      $a = [];
      for ( $i = 0; $i < 31; $i++ ) { $a[] = Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) )->get_status(); }
      $b = Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => '${OTHER_FAMILY}', 'device_id' => '${DEVICE}' ] ) )->get_status();
      // Invented family ids all share the address-only bucket, so they cannot open fresh buckets.
      $made_up = [];
      for ( $i = 0; $i < 31; $i++ ) { $made_up[] = Gatekeeper_License_REST::claim( 'xp-pro', req( [ 'family_id' => sprintf( '3f6c2a1e-8b4d-4c7a-9e2f-%012d', $i ), 'device_id' => '${DEVICE}' ] ) )->get_status(); }
      echo json_encode( [ $a[29], $a[30], $b, $made_up[29], $made_up[30] ] );
    `);
    assert.deepStrictEqual(res, [200, 429, 200, 404, 429]);
  });

  it('validate buckets follow the key family', () => {
    const res = run(`
      $k1 = grant( '${FAMILY}' )['record']['license_key'];
      $k2 = grant( '${OTHER_FAMILY}' )['record']['license_key'];
      $codes = [];
      for ( $i = 0; $i < 31; $i++ ) { $codes[] = Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $k1, 'device_id' => '${DEVICE}' ] ) )->get_status(); }
      $other = Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $k2, 'device_id' => '${DEVICE}' ] ) )->get_status();
      echo json_encode( [ $codes[29], $codes[30], $other ] );
    `);
    assert.deepStrictEqual(res, [200, 429, 200]);
  });

  it('the resolver is used when present', () => {
    const res = run(`
      class Hookshot_Client_Ip {
        public static $calls = 0;
        public static function resolve( WP_REST_Request $request ) { self::$calls++; return (string) $request->get_header( 'x_forwarded_for' ); }
      }
      $with = function ( $ip ) { $r = req( [] ); $r->set_header( 'X-Forwarded-For', $ip ); return $r; };
      for ( $i = 0; $i < 30; $i++ ) { Gatekeeper_License_REST::claim( 'xp-pro', $with( '198.51.100.1' ) ); }
      $same  = Gatekeeper_License_REST::claim( 'xp-pro', $with( '198.51.100.1' ) )->get_status();
      $other = Gatekeeper_License_REST::claim( 'xp-pro', $with( '198.51.100.2' ) )->get_status();
      // An empty answer falls back to REMOTE_ADDR.
      $none  = Gatekeeper_License_Db::client_ip( $with( '' ) );
      echo json_encode( [ $same, $other, $none, Gatekeeper_License_Db::client_ip( $with( '198.51.100.9' ) ), Hookshot_Client_Ip::$calls > 30 ] );
    `);
    assert.deepStrictEqual(res, [429, 400, '203.0.113.7', '198.51.100.9', true]);
  });

  it('without the resolver the address is REMOTE_ADDR', () => {
    const res = run(`
      $r = req( [] );
      $r->set_header( 'X-Forwarded-For', '198.51.100.9' );
      echo json_encode( [ class_exists( 'Hookshot_Client_Ip' ), Gatekeeper_License_Db::client_ip( $r ) ] );
    `);
    assert.deepStrictEqual(res, [false, '203.0.113.7']);
  });
});

describe('XP Pro REST: manage', () => {
  it('manage for a Stripe license posts the customer to billing_portal/sessions', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      $GLOBALS['gk_http_reply'] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'url' => 'https://billing.stripe.com/p/session/test_123' ] ) ];
      $r = out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) ) );
      echo json_encode( [ $r, $GLOBALS['gk_http'], $key, Xophz_Bazaar_Stripe_Key::$forced ] );
    `);
    assert.deepStrictEqual(res[0], { status: 200, data: { url: 'https://billing.stripe.com/p/session/test_123' } });
    assert.strictEqual(res[1].length, 1);
    const call = res[1][0];
    assert.strictEqual(call.url, 'https://api.stripe.com/v1/billing_portal/sessions');
    assert.strictEqual(call.args.headers.Authorization, 'Bearer sk_test_unit_only');
    assert.deepStrictEqual(call.args.body, { customer: 'cus_100', return_url: RETURN_URL });
    assert.ok(!call.url.includes('sk_test'));
    assert.ok(!call.url.includes(res[2]));
    assert.deepStrictEqual(res[3], [false]);
  });

  it('manage uses the test key for a sandbox license', () => {
    const res = run(`
      $key = grant( '${FAMILY}', [ 'env' => 'sandbox' ] )['record']['license_key'];
      $GLOBALS['gk_http_reply'] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'url' => 'https://billing.stripe.com/p/session/test_123' ] ) ];
      Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) );
      echo json_encode( Xophz_Bazaar_Stripe_Key::$forced );
    `);
    assert.deepStrictEqual(res, [true]);
  });

  it('manage for a store license returns the store', () => {
    const res = run(`
      $apple = Gatekeeper_License_Store::apply( 'xp-pro', [ 'action' => 'grant', 'source' => 'revenuecat', 'family_id' => '${FAMILY}', 'plan' => 'yearly', 'expires_at' => time() + 86400, 'refs' => [ 'product_id' => 'xp_pro_yearly', 'store' => 'APP_STORE' ] ], 'rc1' )['record']['license_key'];
      $play = Gatekeeper_License_Store::apply( 'xp-pro', [ 'action' => 'grant', 'source' => 'revenuecat', 'family_id' => '${OTHER_FAMILY}', 'plan' => 'yearly', 'expires_at' => time() + 86400, 'refs' => [ 'product_id' => 'xp_pro_yearly', 'store' => 'PLAY_STORE' ] ], 'rc2' )['record']['license_key'];
      echo json_encode( [
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $apple, 'return_url' => '${RETURN_URL}' ] ) ) ),
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $play, 'return_url' => '${RETURN_URL}' ] ) ) ),
        count( $GLOBALS['gk_http'] ),
      ] );
    `);
    assert.deepStrictEqual(res[0], { status: 200, data: { store: 'APP_STORE' } });
    assert.deepStrictEqual(res[1], { status: 200, data: { store: 'PLAY_STORE' } });
    assert.strictEqual(res[2], 0);
  });

  it('manage refuses a return_url that is not allowed, and an unknown key', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      echo json_encode( [
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $key, 'return_url' => 'https://evil.doit-forthexp.example/steal' ] ) ) ),
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $key, 'return_url' => 'javascript:alert(1)' ] ) ) ),
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => 'XP-PRO-AAAA-BBBB-CCCC', 'return_url' => '${RETURN_URL}' ] ) ) ),
        count( $GLOBALS['gk_http'] ),
      ] );
    `);
    assert.deepStrictEqual(res[0], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[1], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[2], { status: 404, data: { pro: false } });
    assert.strictEqual(res[3], 0);
  });

  it('manage is 502 when Stripe fails, without leaking the secret', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      $GLOBALS['gk_http_reply'] = [ 'response' => [ 'code' => 401 ], 'body' => '{"error":{"message":"Invalid API Key provided: sk_test_unit_only"}}' ];
      echo json_encode( out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 502, data: { error: 'billing_unavailable' } });
  });
});

describe('XP Pro REST: plans', () => {
  it('plans returns what the filter provides', () => {
    const res = run(`
      $GLOBALS['gk_filters']['gatekeeper_xp_pro_plans'] = function ( $v ) { return [ 'monthly' => [ 'price' => 4.99, 'on_sale' => true ] ]; };
      echo json_encode( out( Gatekeeper_License_REST::plans( 'xp-pro' ) ) );
    `);
    assert.deepStrictEqual(res, { status: 200, data: { monthly: { price: 4.99, on_sale: true } } });
  });

  it('plans is the product catalog and an empty list when a product has none', () => {
    const res = run(`
      $catalog = out( Gatekeeper_License_REST::plans( 'xp-pro' ) );
      gk_register_license_product( 'bare', [ 'label' => 'Bare', 'plans' => fn () => [] ] );
      echo json_encode( [ array_keys( $catalog['data'] ), $catalog['status'], out( Gatekeeper_License_REST::plans( 'bare' ) ) ] );
    `);
    assert.deepStrictEqual(res[0], ['monthly', 'yearly', 'lifetime']);
    assert.strictEqual(res[1], 200);
    assert.deepStrictEqual(res[2], { status: 200, data: [] });
  });

  it('plans of a product nobody registered is 404', () => {
    const res = run(`echo json_encode( out( Gatekeeper_License_REST::plans( 'nope' ) ) );`);
    assert.deepStrictEqual(res, { status: 404, data: { error: 'not_found' } });
  });
});

/** The routes as the REST server would see them, with the registered callbacks kept to call them. */
const ROUTES = `
  class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
  $GLOBALS['routes'] = [];
  function register_rest_route( $ns, $path, $args ) {
    foreach ( isset( $args['methods'] ) ? [ $args ] : $args as $a ) {
      $cb = $a['permission_callback'];
      $GLOBALS['routes'][] = [ $ns, $path, $a['methods'], is_array( $cb ) ? implode( '::', $cb ) : $cb, $a['callback'] ];
    }
  }
`;

describe('License REST: wiring', () => {
  it('XP Pro keeps its route list: five public routes here, the AI route in the XP plugin, and the admin-only setup routes', () => {
    const res = run(`
      ${ROUTES}
      Gatekeeper_License_REST::register( 'compass/v1/gatekeeper' );
      echo json_encode( array_map( fn ( $r ) => array_slice( $r, 0, 4 ), $GLOBALS['routes'] ) );
    `);
    const NS = 'compass/v1/gatekeeper';
    assert.deepStrictEqual(res, [
      [NS, '/xp-pro/plans', 'GET', '__return_true'],
      [NS, '/xp-pro/claim', 'POST', '__return_true'],
      [NS, '/xp-pro/validate', 'POST', '__return_true'],
      [NS, '/xp-pro/recover', 'POST', '__return_true'],
      [NS, '/xp-pro/manage', 'POST', '__return_true'],
      [NS, '/xp-pro/setup/stripe-webhook', 'POST', 'Gatekeeper_License_Setup::is_admin'],
      [NS, '/xp-pro/settings', 'GET', 'Gatekeeper_License_Setup::is_admin'],
      [NS, '/xp-pro/settings', 'POST', 'Gatekeeper_License_Setup::is_admin'],
    ]);
  });

  it('no AI route is registered here: the XP plugin serves /xp-pro/ai', () => {
    const res = run(`
      ${ROUTES}
      Gatekeeper_License_REST::register( 'compass/v1/gatekeeper' );
      echo json_encode( [ class_exists( 'Gatekeeper_Xp_Pro_AI' ), array_map( fn ( $r ) => $r[1], $GLOBALS['routes'] ) ] );
    `);
    assert.strictEqual(res[0], false);
    assert.ok(!res[1].some((path) => path.endsWith('/ai')));
    assert.strictEqual(res[1].length, 8);
  });

  it("a second product's routes appear under its own prefix", () => {
    const res = run(`
      ${ROUTES}
      ${ACME}
      Gatekeeper_License_REST::register( 'compass/v1/gatekeeper' );
      echo json_encode( array_map( fn ( $r ) => [ $r[1], $r[2], $r[3] ], $GLOBALS['routes'] ) );
    `);
    assert.deepStrictEqual(res, [
      ['/xp-pro/plans', 'GET', '__return_true'], ['/xp-pro/claim', 'POST', '__return_true'], ['/xp-pro/validate', 'POST', '__return_true'],
      ['/xp-pro/recover', 'POST', '__return_true'], ['/xp-pro/manage', 'POST', '__return_true'],
      ['/acme/plans', 'GET', '__return_true'], ['/acme/claim', 'POST', '__return_true'], ['/acme/validate', 'POST', '__return_true'],
      ['/acme/recover', 'POST', '__return_true'], ['/acme/manage', 'POST', '__return_true'],
      ['/xp-pro/setup/stripe-webhook', 'POST', 'Gatekeeper_License_Setup::is_admin'], ['/xp-pro/settings', 'GET', 'Gatekeeper_License_Setup::is_admin'], ['/xp-pro/settings', 'POST', 'Gatekeeper_License_Setup::is_admin'],
      ['/acme/setup/stripe-webhook', 'POST', 'Gatekeeper_License_Setup::is_admin'], ['/acme/settings', 'GET', 'Gatekeeper_License_Setup::is_admin'], ['/acme/settings', 'POST', 'Gatekeeper_License_Setup::is_admin'],
    ]);
  });

  it("each route's callback serves its own product", () => {
    const res = run(`
      ${ROUTES}
      ${ACME}
      Gatekeeper_License_REST::register( 'compass/v1/gatekeeper' );
      $by = []; foreach ( $GLOBALS['routes'] as $r ) { $by[ $r[1] ] = $r[4]; }
      $plans_xp   = out( $by['/xp-pro/plans']( req( [] ) ) );
      $plans_acme = out( $by['/acme/plans']( req( [] ) ) );
      $bad_acme   = out( $by['/acme/claim']( req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
      $bad_xp     = out( $by['/xp-pro/claim']( req( [ 'site_id' => '${SITE}', 'install_id' => '${DEVICE}' ] ) ) );
      echo json_encode( [ array_keys( $plans_xp['data'] ), array_keys( $plans_acme['data'] ), $bad_acme, $bad_xp ] );
    `);
    assert.deepStrictEqual(res[0], ['monthly', 'yearly', 'lifetime']);
    assert.deepStrictEqual(res[1], ['season', 'founder']);
    assert.deepStrictEqual(res[2], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[3], { status: 400, data: { error: 'invalid_input' } });
  });

  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-license-rest.php', 'xp-pro-rest.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});

describe('License REST: a second product', () => {
  const asAcme = (code) => run(`${ACME}\n${code}`);
  const grantAcme = (extra = '[]') => `
    function grant_acme( $site = '${SITE}', $extra = [] ) {
      return Gatekeeper_License_Store::apply( 'acme', array_merge( [
        'action' => 'grant', 'source' => 'stripe', 'site_id' => $site, 'plan' => 'season',
        'expires_at' => time() + 86400 * 20, 'env' => 'production', 'email' => '${EMAIL}',
        'refs' => [ 'cus' => 'cus_acme1', 'sub' => 'sub_acme1' ],
      ], $extra ), 'evt_acme_' . $site . count( $GLOBALS['gk_options'] ) );
    }
  `;

  it('claim reads its own account and device fields and answers with its own field names', () => {
    const res = asAcme(`
      ${grantAcme()}
      $before = out( Gatekeeper_License_REST::claim( 'acme', req( [ 'site_id' => '${SITE}', 'install_id' => '${DEVICE}' ] ) ) );
      grant_acme();
      $after = out( Gatekeeper_License_REST::claim( 'acme', req( [ 'site_id' => '${SITE}', 'install_id' => '${DEVICE}' ] ) ) );
      echo json_encode( [ $before, $after, Gatekeeper_License_Store::get( 'acme', '${SITE}' )['devices'] ] );
    `);
    assert.deepStrictEqual(res[0], { status: 404, data: { pro: false } });
    assert.strictEqual(res[1].status, 200);
    assert.strictEqual(res[1].data.pro, true);
    assert.strictEqual(res[1].data.site_id, SITE);
    assert.ok(!('family_id' in res[1].data));
    assert.match(res[1].data.license_key, /^ACME-/);
    assert.deepStrictEqual(res[2], [DEVICE]);
  });

  it('claim with the XP Pro field names is 400, and the second product holds 2 devices', () => {
    const res = asAcme(`
      ${grantAcme()}
      grant_acme();
      $wrong = out( Gatekeeper_License_REST::claim( 'acme', req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
      $codes = [];
      foreach ( [ 'device-number-1', 'device-number-2', 'device-number-3' ] as $d ) {
        $codes[] = out( Gatekeeper_License_REST::claim( 'acme', req( [ 'site_id' => '${SITE}', 'install_id' => $d ] ) ) );
      }
      echo json_encode( [ $wrong, array_map( fn ( $c ) => $c['status'], $codes ), $codes[2]['data'] ] );
    `);
    assert.deepStrictEqual(res[0], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[1], [200, 200, 409]);
    assert.deepStrictEqual(res[2], { error: 'device_limit' });
  });

  it('a key belongs to one product: the other product answers 400 or 404', () => {
    const res = asAcme(`
      ${grantAcme()}
      $acme_key = grant_acme()['record']['license_key'];
      $xp_key   = grant()['record']['license_key'];
      echo json_encode( [
        out( Gatekeeper_License_REST::validate( 'acme', req( [ 'license_key' => $acme_key, 'install_id' => '${DEVICE}' ] ) ) )['status'],
        out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $acme_key, 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_License_REST::validate( 'acme', req( [ 'license_key' => $xp_key, 'install_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_License_REST::validate( 'xp-pro', req( [ 'license_key' => $xp_key, 'device_id' => '${DEVICE}' ] ) ) )['data']['family_id'],
      ] );
    `);
    assert.strictEqual(res[0], 200);
    assert.deepStrictEqual(res[1], { status: 400, data: { error: 'invalid_input' } });
    assert.deepStrictEqual(res[2], { status: 400, data: { error: 'invalid_input' } });
    assert.strictEqual(res[3], FAMILY);
  });

  it('rate limit buckets are per product: one product at its limit leaves the other open', () => {
    const res = asAcme(`
      $codes = [];
      for ( $i = 0; $i < 31; $i++ ) { $codes[] = Gatekeeper_License_REST::claim( 'xp-pro', req( [] ) )->get_status(); }
      $acme = Gatekeeper_License_REST::claim( 'acme', req( [] ) )->get_status();
      echo json_encode( [ $codes[29], $codes[30], $acme, Gatekeeper_License_REST::bucket( Gatekeeper_License_Products::get( 'xp-pro' ), 'claim' ), Gatekeeper_License_REST::bucket( Gatekeeper_License_Products::get( 'acme' ), 'recover' ) ] );
    `);
    assert.deepStrictEqual(res, [400, 429, 400, 'xp_pro_claim', 'acme_recover']);
  });

  it('recover mails only the keys of its own product, with its own text', () => {
    const res = asAcme(`
      ${grantAcme()}
      $acme_key = grant_acme()['record']['license_key'];
      $xp_key   = grant()['record']['license_key'];
      update_option( 'admin_email', 'help@doit.forthexp.com' );
      $r = out( Gatekeeper_License_REST::recover( 'acme', req( [ 'email' => '${EMAIL}' ] ) ) );
      echo json_encode( [ $r, $GLOBALS['gk_mail'], $acme_key, $xp_key ] );
    `);
    assert.strictEqual(res[0].status, 202);
    assert.strictEqual(res[1].length, 1);
    assert.strictEqual(res[1][0].subject, 'Your Acme Pro key');
    assert.ok(res[1][0].message.includes(res[2]));
    assert.ok(!res[1][0].message.includes(res[3]));
    assert.ok(res[1][0].message.startsWith('Here is your Acme Pro key:'));
    assert.ok(res[1][0].message.includes('open Acme Quest, go to Settings > Acme Pro > Restore with key'));
    assert.ok(res[1][0].message.includes('Questions? Write to help@doit.forthexp.com.'));
  });

  it('manage finds its own license and refuses an XP Pro key', () => {
    const res = asAcme(`
      ${grantAcme()}
      $acme_key = grant_acme()['record']['license_key'];
      $GLOBALS['gk_http_reply'] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'url' => 'https://billing.stripe.com/p/session/acme' ] ) ];
      echo json_encode( [
        out( Gatekeeper_License_REST::manage( 'acme', req( [ 'license_key' => $acme_key, 'return_url' => '${RETURN_URL}' ] ) ) ),
        out( Gatekeeper_License_REST::manage( 'xp-pro', req( [ 'license_key' => $acme_key, 'return_url' => '${RETURN_URL}' ] ) ) ),
        $GLOBALS['gk_http'][0]['args']['body']['customer'],
      ] );
    `);
    assert.deepStrictEqual(res[0], { status: 200, data: { url: 'https://billing.stripe.com/p/session/acme' } });
    assert.deepStrictEqual(res[1], { status: 400, data: { error: 'invalid_input' } });
    assert.strictEqual(res[2], 'cus_acme1');
  });
});

