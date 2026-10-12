/**
 * XP Pro REST handlers: claim, validate, recover, manage and plans, called directly with a
 * stub request. Status codes follow spec 3.4.
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
    return Gatekeeper_Xp_Pro_Licenses::apply( array_merge( [
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
      $before = out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
      grant();
      $after = out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) ) );
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
      Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) );
      echo json_encode( Gatekeeper_Xp_Pro_Licenses::get( '${FAMILY}' )['devices'] );
    `);
    assert.deepStrictEqual(res, [DEVICE]);
  });

  it('claim with a bad family_id or device_id is 400', () => {
    const res = run(`
      echo json_encode( [
        out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => 'nope', 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => 'x' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => [ '${FAMILY}' ], 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::claim( req( [] ) ) ),
      ] );
    `);
    for (const r of res) assert.deepStrictEqual(r, { status: 400, data: { error: 'invalid_input' } });
  });

  it('claim on an 11th device is 409 device_limit', () => {
    const res = run(`
      grant();
      for ( $i = 1; $i <= 10; $i++ ) { Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => 'device-number-' . $i ] ) ); }
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => 'device-number-11' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 409, data: { error: 'device_limit' } });
  });
});

describe('XP Pro REST: validate', () => {
  it('validate returns the family so a restored device can adopt it', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => strtolower( $key ), 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.family_id, FAMILY);
    assert.strictEqual(res.data.pro, true);
  });

  it('validate an expired license returns pro false', () => {
    const res = run(`
      $key = grant( '${FAMILY}', [ 'expires_at' => time() - 86400 * 10 ] )['record']['license_key'];
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => $key, 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.pro, false);
    assert.strictEqual(res.data.family_id, FAMILY);
  });

  it('validate a revoked license returns pro false', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      Gatekeeper_Xp_Pro_Licenses::apply( [ 'action' => 'revoke', 'source' => 'stripe', 'family_id' => '${FAMILY}' ], 'evt_revoke' );
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => $key, 'device_id' => '${DEVICE}' ] ) ) ) );
    `);
    assert.strictEqual(res.status, 200);
    assert.strictEqual(res.data.pro, false);
  });

  it('validate on an 11th device is 409 device_limit', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      for ( $i = 1; $i <= 10; $i++ ) { Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => $key, 'device_id' => 'device-number-' . $i ] ) ); }
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => $key, 'device_id' => 'device-number-11' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 409, data: { error: 'device_limit' } });
  });

  it('validate: unknown key is 404, malformed key is 400', () => {
    const res = run(`
      echo json_encode( [
        out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => 'XP-PRO-AAAA-BBBB-CCCC', 'device_id' => '${DEVICE}' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::validate( req( [ 'license_key' => 'CX-DEV-PREVIEW', 'device_id' => '${DEVICE}' ] ) ) ),
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
      $known   = out( Gatekeeper_Xp_Pro_REST::recover( req( [ 'email' => '${EMAIL.toUpperCase()}' ] ) ) );
      $unknown = out( Gatekeeper_Xp_Pro_REST::recover( req( [ 'email' => 'nobody@doit.forthexp.com' ] ) ) );
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
      $bad = out( Gatekeeper_Xp_Pro_REST::recover( req( [ 'email' => 'not an email' ] ) ) );
      $codes = [];
      for ( $i = 0; $i < 6; $i++ ) { $codes[] = Gatekeeper_Xp_Pro_REST::recover( req( [ 'email' => 'nobody@doit.forthexp.com' ] ) )->get_status(); }
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
      for ( $i = 0; $i < 31; $i++ ) { $codes[] = Gatekeeper_Xp_Pro_REST::claim( req( [ 'family_id' => '${FAMILY}', 'device_id' => '${DEVICE}' ] ) )->get_status(); }
      echo json_encode( [ array_slice( $codes, 0, 30 ), $codes[30], out( Gatekeeper_Xp_Pro_REST::claim( req( [] ) ) )['data'] ] );
    `);
    assert.ok(res[0].every((c) => c === 404));
    assert.strictEqual(res[1], 429);
    assert.deepStrictEqual(res[2], { error: 'rate_limited' });
  });

  it('limits are per client address and the window resets', () => {
    const res = run(`
      for ( $i = 0; $i < 30; $i++ ) { Gatekeeper_Xp_Pro_REST::claim( req( [] ) ); }
      $same = Gatekeeper_Xp_Pro_REST::claim( req( [] ) )->get_status();
      $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
      $other = Gatekeeper_Xp_Pro_REST::claim( req( [] ) )->get_status();
      $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
      foreach ( $GLOBALS['gk_transients'] as $k => $v ) { $GLOBALS['gk_transients'][ $k ]['start'] -= 61; }
      $later = Gatekeeper_Xp_Pro_REST::claim( req( [] ) )->get_status();
      echo json_encode( [ $same, $other, $later ] );
    `);
    assert.deepStrictEqual(res, [429, 400, 400]);
  });

  it('X-Forwarded-For does not change the bucket', () => {
    const res = run(`
      for ( $i = 0; $i < 30; $i++ ) { Gatekeeper_Xp_Pro_REST::claim( req( [] ) ); }
      $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
      echo json_encode( Gatekeeper_Xp_Pro_REST::claim( req( [] ) )->get_status() );
    `);
    assert.strictEqual(res, 429);
  });
});

describe('XP Pro REST: manage', () => {
  it('manage for a Stripe license posts the customer to billing_portal/sessions', () => {
    const res = run(`
      $key = grant()['record']['license_key'];
      $GLOBALS['gk_http_reply'] = [ 'response' => [ 'code' => 200 ], 'body' => json_encode( [ 'url' => 'https://billing.stripe.com/p/session/test_123' ] ) ];
      $r = out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) ) );
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
      Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) );
      echo json_encode( Xophz_Bazaar_Stripe_Key::$forced );
    `);
    assert.deepStrictEqual(res, [true]);
  });

  it('manage for a store license returns the store', () => {
    const res = run(`
      $apple = Gatekeeper_Xp_Pro_Licenses::apply( [ 'action' => 'grant', 'source' => 'revenuecat', 'family_id' => '${FAMILY}', 'plan' => 'yearly', 'expires_at' => time() + 86400, 'refs' => [ 'product_id' => 'xp_pro_yearly', 'store' => 'APP_STORE' ] ], 'rc1' )['record']['license_key'];
      $play = Gatekeeper_Xp_Pro_Licenses::apply( [ 'action' => 'grant', 'source' => 'revenuecat', 'family_id' => '${OTHER_FAMILY}', 'plan' => 'yearly', 'expires_at' => time() + 86400, 'refs' => [ 'product_id' => 'xp_pro_yearly', 'store' => 'PLAY_STORE' ] ], 'rc2' )['record']['license_key'];
      echo json_encode( [
        out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $apple, 'return_url' => '${RETURN_URL}' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $play, 'return_url' => '${RETURN_URL}' ] ) ) ),
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
        out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $key, 'return_url' => 'https://evil.doit-forthexp.example/steal' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $key, 'return_url' => 'javascript:alert(1)' ] ) ) ),
        out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => 'XP-PRO-AAAA-BBBB-CCCC', 'return_url' => '${RETURN_URL}' ] ) ) ),
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
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::manage( req( [ 'license_key' => $key, 'return_url' => '${RETURN_URL}' ] ) ) ) );
    `);
    assert.deepStrictEqual(res, { status: 502, data: { error: 'billing_unavailable' } });
  });
});

describe('XP Pro REST: plans', () => {
  it('plans returns what the filter provides', () => {
    const res = run(`
      $GLOBALS['gk_filters']['gatekeeper_xp_pro_plans'] = function ( $v ) { return [ 'monthly' => [ 'price' => 4.99, 'on_sale' => true ] ]; };
      echo json_encode( out( Gatekeeper_Xp_Pro_REST::plans() ) );
    `);
    assert.deepStrictEqual(res, { status: 200, data: { monthly: { price: 4.99, on_sale: true } } });
  });

  it('plans is an empty list until the XP plugin provides them', () => {
    const res = run(`echo json_encode( out( Gatekeeper_Xp_Pro_REST::plans() ) );`);
    assert.deepStrictEqual(res, { status: 200, data: [] });
  });
});

describe('XP Pro REST: wiring', () => {
  it('registers five public routes under the gatekeeper namespace', () => {
    const res = run(`
      class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
      $GLOBALS['routes'] = [];
      function register_rest_route( $ns, $path, $args ) { $GLOBALS['routes'][] = [ $ns, $path, $args[0]['methods'], $args[0]['permission_callback'] ]; }
      Gatekeeper_Xp_Pro_REST::register( 'compass/v1/gatekeeper' );
      echo json_encode( $GLOBALS['routes'] );
    `);
    assert.deepStrictEqual(res.map((r) => [r[1], r[2]]), [
      ['/xp-pro/plans', 'GET'], ['/xp-pro/claim', 'POST'], ['/xp-pro/validate', 'POST'], ['/xp-pro/recover', 'POST'], ['/xp-pro/manage', 'POST'],
    ]);
    assert.ok(res.every((r) => r[0] === 'compass/v1/gatekeeper' && r[3] === '__return_true'));
  });

  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-xp-pro-rest.php', 'xp-pro-rest.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
