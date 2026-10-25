/**
 * License RevenueCat adapter: every row of the spec 3.3 table for XP Pro, from webhook v1 shaped
 * payloads, plus a second product proving the adapter maps store product ids through the product's
 * own plan catalog and ignores the events of any other product.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes, assertNoSyntheticData } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const INCLUDES = '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/includes';
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const ACME_ACCOUNT = 'acct_k3j9x2q7';

const fixture = (name) => JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'revenuecat', `${name}.json`), 'utf8'));

/**
 * Two products as the registry hands them to an adapter. $xpPro leaves account_validator out so the
 * default (lowercase uuid4) applies; $acme brings its own validator and store ids.
 */
const PRODUCTS = `
  $xpPro = [
    'account_param' => 'family_id',
    'plans'         => fn () => [
      'monthly'  => [ 'price' => 4.99, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 7, 'store_product_id' => 'xp_pro_monthly', 'on_sale' => true ],
      'yearly'   => [ 'price' => 39.99, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'store_product_id' => 'xp_pro_yearly', 'on_sale' => true ],
      'lifetime' => [ 'price' => 79, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'xp_pro_lifetime', 'on_sale' => true ],
    ],
  ];
  $acme = [
    'account_param'     => 'account_ref',
    'account_validator' => fn ( $s ) => is_string( $s ) && 1 === preg_match( '/\\Aacct_[a-z0-9]{8}\\z/', $s ),
    'plans'             => fn () => [
      'season'  => [ 'price' => 9, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 3, 'store_product_id' => 'acme_season', 'on_sale' => true ],
      'founder' => [ 'price' => 120, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'acme_founder', 'on_sale' => false ],
    ],
  ];
`;

/** The same kind of store seam the app uses: the generic store once it exists, else the XP Pro one. */
const STORE = `
  $apply = function ( array $op, string $event_id ) {
    return class_exists( 'Gatekeeper_License_Store' )
      ? Gatekeeper_License_Store::apply( 'xp-pro', $op, $event_id )
      : Gatekeeper_Xp_Pro_Licenses::apply( $op, $event_id );
  };
`;

/** Runs a PHP snippet with the adapter loaded and both products defined. */
function run(code) {
  return xp(`require_once '${INCLUDES}/class-gatekeeper-license-revenuecat.php';\n${PRODUCTS}\n${STORE}\n${code}`);
}

function toOp(name, product = '$xpPro') {
  return run(`echo json_encode(Gatekeeper_License_RevenueCat::to_op(${lit(fixture(name))}, ${product}));`);
}

function toOpOf(payload, product = '$xpPro') {
  return run(`echo json_encode(Gatekeeper_License_RevenueCat::to_op(${lit(payload)}, ${product}));`);
}

/** A payload of the second product: a real-shaped fixture with its account and store ids swapped in. */
function acmePayload(name, event = {}) {
  const payload = fixture(name);
  payload.event = { ...payload.event, app_user_id: ACME_ACCOUNT, original_app_user_id: ACME_ACCOUNT, aliases: [ACME_ACCOUNT], ...event };
  return payload;
}

describe('License RevenueCat: grants', () => {
  it('INITIAL_PURCHASE grants monthly with expiry from expiration_at_ms', () => {
    const op = toOp('initial-purchase-monthly');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.source, 'revenuecat');
    assert.strictEqual(op.family_id, FAMILY);
    assert.strictEqual(op.plan, 'monthly');
    assert.strictEqual(op.expires_at, 1790604800);
    assert.strictEqual(op.env, 'production');
    assert.deepStrictEqual(op.refs, { product_id: 'xp_pro_monthly', store: 'APP_STORE' });
  });

  it('RENEWAL grants yearly and floors the milliseconds', () => {
    const op = toOp('renewal-yearly');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'yearly');
    assert.strictEqual(op.expires_at, 1822140800);
    assert.strictEqual(op.refs.store, 'PLAY_STORE');
  });

  it('UNCANCELLATION grants', () => {
    const op = toOp('uncancellation');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'monthly');
  });

  it('PRODUCT_CHANGE grants the new product', () => {
    const op = toOp('product-change');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'yearly');
    assert.strictEqual(op.refs.product_id, 'xp_pro_yearly');
  });

  it('NON_RENEWING_PURCHASE of xp_pro_lifetime grants lifetime with no expiry', () => {
    const op = toOp('non-renewing-lifetime');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'lifetime');
    assert.strictEqual(op.expires_at, null);
  });

  it('NON_RENEWING_PURCHASE of another product is none', () => {
    assert.strictEqual(toOp('non-renewing-other').action, 'none');
  });

  it('SANDBOX environment sets env sandbox', () => {
    assert.strictEqual(toOp('initial-purchase-sandbox').env, 'sandbox');
  });
});

describe('License RevenueCat: endings and no-ops', () => {
  it('CANCELLATION is none', () => {
    assert.strictEqual(toOp('cancellation').action, 'none');
  });

  it('CANCELLATION with cancel_reason CUSTOMER_SUPPORT revokes the family', () => {
    const op = toOp('cancellation-support');
    assert.strictEqual(op.action, 'revoke');
    assert.strictEqual(op.family_id, FAMILY);
  });

  it('EXPIRATION expires the family', () => {
    const op = toOp('expiration');
    assert.strictEqual(op.action, 'expire');
    assert.strictEqual(op.family_id, FAMILY);
    assert.deepStrictEqual(op.refs, { product_id: 'xp_pro_monthly' });
  });

  it('BILLING_ISSUE and TRANSFER are none', () => {
    assert.strictEqual(toOp('billing-issue').action, 'none');
    assert.strictEqual(toOp('transfer').action, 'none');
  });

  it('a non-UUID app_user_id is none', () => {
    assert.strictEqual(toOp('anonymous-user').action, 'none');
  });

  it('an unknown product_id is none', () => {
    assert.strictEqual(toOp('unknown-product').action, 'none');
  });

  it('a payload without an event is none', () => {
    const res = run(`echo json_encode([Gatekeeper_License_RevenueCat::to_op([], $xpPro)['action'], Gatekeeper_License_RevenueCat::event_id([])]);`);
    assert.deepStrictEqual(res, ['none', '']);
  });

  it('event_id is the RevenueCat event id', () => {
    const res = run(`echo json_encode(Gatekeeper_License_RevenueCat::event_id(${lit(fixture('renewal-yearly'))}));`);
    assert.strictEqual(res, 'rc-evt-0003');
  });
});

describe('License RevenueCat: through the store', () => {
  it('purchase, support refund of a subscription, then renewal reuses the key', () => {
    const res = run(`
      $run = function ($payload) use ($apply, $xpPro) {
        return $apply(Gatekeeper_License_RevenueCat::to_op($payload, $xpPro), Gatekeeper_License_RevenueCat::event_id($payload));
      };
      $a = $run(${lit(fixture('initial-purchase-monthly'))});
      $b = $run(${lit(fixture('cancellation-support'))});
      $c = $run(${lit(fixture('renewal-yearly'))});
      echo json_encode([$a['created_key'], $b['record']['status'], $c['record']['status'], $a['record']['license_key'] === $c['record']['license_key'], $c['record']['sources']['revenuecat']['store']]);
    `);
    assert.deepStrictEqual(res, [true, 'expired', 'active', true, 'PLAY_STORE']);
  });
});

describe('License RevenueCat: a second product', () => {
  it("maps the store product id through the product's own plan catalog", () => {
    const op = toOpOf(acmePayload('initial-purchase-monthly', { product_id: 'acme_season' }), '$acme');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.source, 'revenuecat');
    assert.strictEqual(op.family_id, ACME_ACCOUNT);
    assert.strictEqual(op.plan, 'season');
    assert.strictEqual(op.expires_at, 1790604800);
    assert.strictEqual(op.env, 'production');
    assert.deepStrictEqual(op.refs, { product_id: 'acme_season', store: 'APP_STORE' });
  });

  it('RENEWAL floors the milliseconds and keeps the store', () => {
    const op = toOpOf(acmePayload('renewal-yearly', { product_id: 'acme_season' }), '$acme');
    assert.strictEqual(op.plan, 'season');
    assert.strictEqual(op.expires_at, 1822140800);
    assert.strictEqual(op.refs.store, 'PLAY_STORE');
  });

  it('PRODUCT_CHANGE grants the new product of the catalog', () => {
    const op = toOpOf(acmePayload('product-change', { product_id: 'acme_founder', new_product_id: 'acme_season' }), '$acme');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'season');
    assert.strictEqual(op.refs.product_id, 'acme_season');
  });

  it('a NON_RENEWING_PURCHASE of the payment plan is the lifetime grant; of a subscription plan it is none', () => {
    const lifetime = toOpOf(acmePayload('non-renewing-lifetime', { product_id: 'acme_founder' }), '$acme');
    assert.strictEqual(lifetime.action, 'grant');
    assert.strictEqual(lifetime.plan, 'founder');
    assert.strictEqual(lifetime.expires_at, null);
    assert.deepStrictEqual(lifetime.refs, { product_id: 'acme_founder', store: 'APP_STORE' });
    assert.strictEqual(toOpOf(acmePayload('non-renewing-other', { product_id: 'acme_season' }), '$acme').action, 'none');
  });

  it('a subscription event for the payment plan is none', () => {
    assert.strictEqual(toOpOf(acmePayload('initial-purchase-monthly', { product_id: 'acme_founder' }), '$acme').action, 'none');
  });

  it("each product ignores the other's purchases", () => {
    // The account is valid for the product asked, so only the store product id can reject it.
    const xpOnAcme = acmePayload('initial-purchase-monthly', { product_id: 'xp_pro_monthly' });
    assert.strictEqual(toOpOf(xpOnAcme, '$acme').action, 'none');
    const acmeOnXp = fixture('initial-purchase-monthly');
    acmeOnXp.event.product_id = 'acme_season';
    assert.strictEqual(toOpOf(acmeOnXp, '$xpPro').action, 'none');
    const lifetimeOnXp = fixture('non-renewing-lifetime');
    lifetimeOnXp.event.product_id = 'acme_founder';
    assert.strictEqual(toOpOf(lifetimeOnXp, '$xpPro').action, 'none');
  });

  it("each product ignores the other's endings", () => {
    const foreignExpiry = fixture('expiration');
    foreignExpiry.event.product_id = 'acme_season';
    assert.strictEqual(toOpOf(foreignExpiry, '$xpPro').action, 'none');
    const foreignSupportRefund = fixture('cancellation-support');
    foreignSupportRefund.event.product_id = 'acme_season';
    assert.strictEqual(toOpOf(foreignSupportRefund, '$xpPro').action, 'none');

    const own = toOpOf(acmePayload('expiration', { product_id: 'acme_season' }), '$acme');
    assert.strictEqual(own.action, 'expire');
    assert.strictEqual(own.family_id, ACME_ACCOUNT);
    assert.deepStrictEqual(own.refs, { product_id: 'acme_season' });
    assert.strictEqual(toOpOf(acmePayload('cancellation-support', { product_id: 'acme_season' }), '$acme').action, 'revoke');
    assert.strictEqual(toOpOf(acmePayload('expiration', { product_id: 'xp_pro_monthly' }), '$acme').action, 'none');
  });

  it('an ending that names no product still reaches the account, as it does for XP Pro', () => {
    const payload = acmePayload('expiration');
    delete payload.event.product_id;
    const op = toOpOf(payload, '$acme');
    assert.strictEqual(op.action, 'expire');
    assert.deepStrictEqual(op.refs, []);
  });

  it('the account validator decides what app_user_id is usable', () => {
    assert.strictEqual(toOpOf(acmePayload('initial-purchase-monthly', { product_id: 'acme_season', app_user_id: FAMILY }), '$acme').action, 'none');
    assert.strictEqual(toOpOf(acmePayload('initial-purchase-monthly', { product_id: 'acme_season', app_user_id: '$RCAnonymousID:abc' }), '$acme').action, 'none');
    assert.strictEqual(toOpOf(acmePayload('initial-purchase-monthly', { product_id: 'acme_season', app_user_id: null }), '$acme').action, 'none');
  });

  it('plans may be a plain array, and a plans callable that returns junk matches nothing', () => {
    const res = run(`
      $p = ${lit(fixture('initial-purchase-monthly'))};
      $asArray = [ 'plans' => [ 'monthly' => [ 'mode' => 'subscription', 'store_product_id' => 'xp_pro_monthly' ] ] ];
      $junk    = [ 'plans' => fn () => 'nope' ];
      echo json_encode( [
        Gatekeeper_License_RevenueCat::to_op( $p, $asArray )['plan'],
        Gatekeeper_License_RevenueCat::to_op( $p, $junk )['action'],
        Gatekeeper_License_RevenueCat::to_op( $p, [] )['action'],
      ] );
    `);
    assert.deepStrictEqual(res, ['monthly', 'none', 'none']);
  });

  it('a plan without a store_product_id never matches', () => {
    const res = run(`
      $p = ${lit(fixture('initial-purchase-monthly'))};
      $noStore = [ 'plans' => [ 'monthly' => [ 'mode' => 'subscription' ] ] ];
      echo json_encode( Gatekeeper_License_RevenueCat::to_op( $p, $noStore )['action'] );
    `);
    assert.strictEqual(res, 'none');
  });
});

describe('License RevenueCat: the XP Pro wrapper', () => {
  it('Gatekeeper_Xp_Pro_RevenueCat keeps its old signature and answers with the xp-pro product', () => {
    const res = xp(`
      $p  = ${lit(fixture('renewal-yearly'))};
      $op = Gatekeeper_Xp_Pro_RevenueCat::to_op( $p );
      echo json_encode( [ $op['action'], $op['plan'], $op['family_id'], Gatekeeper_Xp_Pro_RevenueCat::event_id( $p ) ] );
    `);
    assert.deepStrictEqual(res, ['grant', 'yearly', FAMILY, 'rc-evt-0003']);
  });
});

describe('License RevenueCat: hygiene', () => {
  it('files and fixtures carry no em dashes or synthetic contact data', () => {
    const files = ['../includes/class-gatekeeper-license-revenuecat.php', '../includes/class-gatekeeper-xp-pro-revenuecat.php', 'xp-pro-revenuecat.test.mjs'];
    for (const f of fs.readdirSync(path.join(HERE, 'fixtures', 'revenuecat'))) files.push(path.join('fixtures', 'revenuecat', f));
    for (const rel of files) {
      const file = path.join(HERE, rel);
      if (!fs.existsSync(file)) continue;
      const text = fs.readFileSync(file, 'utf8');
      assertNoEmDashes(text, rel);
      assertNoSyntheticData(text, rel);
    }
  });
});
