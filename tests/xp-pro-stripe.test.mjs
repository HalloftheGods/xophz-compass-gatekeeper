/**
 * License Stripe adapter: every row of the spec 3.3 table for XP Pro, from real-shaped events,
 * the refund paths run end to end through the license store, and a second product proving the
 * adapter reads everything product specific (checkout_product, plans, account_param, trial)
 * from the product array and ignores the events of any other product.
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
const NOW = 1790000000;

const fixture = (name) => JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'stripe', `${name}.json`), 'utf8'));

/**
 * Two products as the registry hands them to an adapter. $xpPro leaves account_validator out so the
 * default (lowercase uuid4) applies; $acme brings its own checkout_product, account_param,
 * validator, trial and store ids.
 */
const PRODUCTS = `
  $xpPro = [
    'checkout_product' => 'doitforthexp',
    'account_param'    => 'family_id',
    'trial_seconds'    => 604800,
    'plans'            => fn () => [
      'monthly'  => [ 'price' => 4.99, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 7, 'store_product_id' => 'xp_pro_monthly', 'on_sale' => true ],
      'yearly'   => [ 'price' => 39.99, 'mode' => 'subscription', 'interval' => 'year', 'trial_days' => 7, 'store_product_id' => 'xp_pro_yearly', 'on_sale' => true ],
      'lifetime' => [ 'price' => 79, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'xp_pro_lifetime', 'on_sale' => true ],
    ],
  ];
  $acme = [
    'checkout_product'  => 'acmepro',
    'account_param'     => 'account_ref',
    'account_validator' => fn ( $s ) => is_string( $s ) && 1 === preg_match( '/\\Aacct_[a-z0-9]{8}\\z/', $s ),
    'trial_seconds'     => 3 * 86400,
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
  return xp(`require_once '${INCLUDES}/class-gatekeeper-license-stripe.php';\n${PRODUCTS}\n${STORE}\n${code}`);
}

function toOp(name, product = '$xpPro') {
  return run(`echo json_encode(Gatekeeper_License_Stripe::to_op(${lit(fixture(name))}, ${product}, ${NOW}));`);
}

function toOpOf(event, product = '$xpPro') {
  return run(`echo json_encode(Gatekeeper_License_Stripe::to_op(${lit(event)}, ${product}, ${NOW}));`);
}

/** A checkout event of the second product, built from a real-shaped fixture. */
function acmeEvent(name, meta = {}) {
  const event = fixture(name);
  event.data.object.metadata = { product: 'acmepro', plan: 'season', account_ref: ACME_ACCOUNT, ...meta };
  return event;
}

describe('License Stripe: checkout.session.completed', () => {
  it('a monthly checkout grants with a 7 day trial window', () => {
    const op = toOp('checkout-session-completed-monthly');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.source, 'stripe');
    assert.strictEqual(op.family_id, FAMILY);
    assert.strictEqual(op.plan, 'monthly');
    assert.strictEqual(op.expires_at, NOW + 7 * 86400);
    assert.strictEqual(op.env, 'sandbox');
    assert.strictEqual(op.email, 'parent@doit.forthexp.com');
    assert.deepStrictEqual(op.refs, { sub: 'sub_1QxMonthly01', cus: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('an expanded subscription supplies trial_end', () => {
    const op = toOp('checkout-session-completed-yearly-expanded');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'yearly');
    assert.strictEqual(op.expires_at, 1790604800);
    assert.strictEqual(op.refs.sub, 'sub_1QxYearly01');
  });

  it('a lifetime checkout grants with no expiry and records the payment intent', () => {
    const op = toOp('checkout-session-completed-lifetime');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'lifetime');
    assert.strictEqual(op.expires_at, null);
    assert.deepStrictEqual(op.refs, { pi: 'pi_3QxLifetime01', cus: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('livemode sets env production', () => {
    assert.strictEqual(toOp('checkout-session-completed-live').env, 'production');
  });

  it("another product's checkout is none", () => {
    assert.strictEqual(toOp('checkout-session-completed-other-product').action, 'none');
  });

  it('a checkout with a bad family_id or unknown plan is none', () => {
    const res = run(`
      $base = ${lit(fixture('checkout-session-completed-monthly'))};
      $badFamily = $base;
      $badFamily['data']['object']['metadata']['family_id'] = 'not-a-uuid';
      $badPlan = $base;
      $badPlan['data']['object']['metadata']['plan'] = 'platinum';
      echo json_encode([
        Gatekeeper_License_Stripe::to_op($badFamily, $xpPro, ${NOW})['action'],
        Gatekeeper_License_Stripe::to_op($badPlan, $xpPro, ${NOW})['action'],
      ]);
    `);
    assert.deepStrictEqual(res, ['none', 'none']);
  });
});

describe('License Stripe: delayed payment methods (F4)', () => {
  it('an unpaid checkout grants nothing', () => {
    assert.strictEqual(toOp('checkout-session-completed-lifetime-unpaid').action, 'none');
    const res = run(`
      $e = ${lit(fixture('checkout-session-completed-monthly'))};
      $none = $e; unset($none['data']['object']['payment_status']);
      $free = $e; $free['data']['object']['payment_status'] = 'no_payment_required';
      echo json_encode([Gatekeeper_License_Stripe::to_op($none, $xpPro, ${NOW})['action'], Gatekeeper_License_Stripe::to_op($free, $xpPro, ${NOW})['action']]);
    `);
    assert.deepStrictEqual(res, ['none', 'grant']);
  });

  it('async success grants', () => {
    const op = toOp('checkout-session-async-payment-succeeded');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'lifetime');
    assert.strictEqual(op.family_id, FAMILY);
    assert.strictEqual(op.email, 'parent@doit.forthexp.com');
    assert.deepStrictEqual(op.refs, { pi: 'pi_3QxLifetime01', cus: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('async failure of a lifetime revokes', () => {
    const op = toOp('checkout-session-async-payment-failed');
    assert.strictEqual(op.action, 'revoke');
    assert.deepStrictEqual(op.ref, { type: 'pi', id: 'pi_3QxLifetime01' });
    const res = run(`
      $ok = ${lit(fixture('checkout-session-async-payment-succeeded'))};
      $bad = ${lit(fixture('checkout-session-async-payment-failed'))};
      $unpaid = ${lit(fixture('checkout-session-completed-lifetime-unpaid'))};
      $run = fn ( $e ) => $apply( Gatekeeper_License_Stripe::to_op( $e, $xpPro, ${NOW} ), Gatekeeper_License_Stripe::event_id( $e ) );
      $a = $run( $unpaid );
      $b = $run( $ok );
      $c = $run( $bad );
      echo json_encode( [ $a['changed'], $b['record']['status'], $b['record']['plan'], $c['record']['status'] ] );
    `);
    assert.deepStrictEqual(res, [false, 'active', 'lifetime', 'revoked']);
  });

  it('an async failure for a purchase that was never granted changes nothing', () => {
    const res = run(`
      $run = fn ( $e ) => $apply( Gatekeeper_License_Stripe::to_op( $e, $xpPro, ${NOW} ), Gatekeeper_License_Stripe::event_id( $e ) );
      $run( ${lit(fixture('checkout-session-completed-monthly'))} );
      $r = $run( ${lit(fixture('checkout-session-async-payment-failed'))} );
      echo json_encode( [ $r['changed'], Gatekeeper_Xp_Pro_Licenses::get( '${FAMILY}' )['status'] ] );
    `);
    assert.deepStrictEqual(res, [false, 'active']);
  });
});

describe('License Stripe: renewals and endings', () => {
  it('invoice.paid extends by sub ref to the line period end', () => {
    const op = toOp('invoice-paid');
    assert.strictEqual(op.action, 'extend');
    assert.deepStrictEqual(op.ref, { type: 'sub', id: 'sub_1QxMonthly01' });
    assert.strictEqual(op.expires_at, 1795184000);
  });

  it('invoice.paid reads the subscription from parent.subscription_details on new API versions', () => {
    const op = toOp('invoice-paid-parent');
    assert.strictEqual(op.action, 'extend');
    assert.deepStrictEqual(op.ref, { type: 'sub', id: 'sub_1QxMonthly01' });
    assert.strictEqual(op.expires_at, 1795184000);
  });

  it('invoice.paid with no subscription is none', () => {
    const res = run(`
      $e = ${lit(fixture('invoice-paid'))};
      unset($e['data']['object']['subscription']);
      echo json_encode(Gatekeeper_License_Stripe::to_op($e, $xpPro, ${NOW})['action']);
    `);
    assert.strictEqual(res, 'none');
  });

  it('customer.subscription.deleted expires by sub ref', () => {
    const op = toOp('subscription-deleted');
    assert.strictEqual(op.action, 'expire');
    assert.deepStrictEqual(op.ref, { type: 'sub', id: 'sub_1QxMonthly01' });
  });
});

describe('License Stripe: refunds and disputes', () => {
  it('a full refund revokes by payment intent', () => {
    const op = toOp('charge-refunded-full');
    assert.strictEqual(op.action, 'revoke');
    assert.deepStrictEqual(op.ref, { type: 'pi', id: 'pi_3QxLifetime01' });
  });

  it('a partial refund (refunded false) is none', () => {
    assert.strictEqual(toOp('charge-refunded-partial').action, 'none');
  });

  it('a refund with only a customer revokes by customer', () => {
    const op = toOp('charge-refunded-subscription');
    assert.strictEqual(op.action, 'revoke');
    assert.deepStrictEqual(op.ref, { type: 'cus', id: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('a dispute revokes by payment intent', () => {
    const op = toOp('dispute-created');
    assert.strictEqual(op.action, 'revoke');
    assert.deepStrictEqual(op.ref, { type: 'pi', id: 'pi_3QxLifetime01' });
  });

  it('other events are none', () => {
    assert.strictEqual(toOp('customer-created').action, 'none');
  });

  it('event_id is the Stripe event id', () => {
    const res = run(`echo json_encode(Gatekeeper_License_Stripe::event_id(${lit(fixture('invoice-paid'))}));`);
    assert.strictEqual(res, 'evt_1QxInvoicePaid');
  });
});

/** Grant from the monthly fixture, then apply the named fixture, then ask is_entitled. */
function refundScenario(name) {
  return run(`
    $grant = Gatekeeper_License_Stripe::to_op(${lit(fixture('checkout-session-completed-monthly'))}, $xpPro, ${NOW});
    $apply($grant, 'evt_grant');
    $before = Gatekeeper_Xp_Pro_Licenses::is_entitled(Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}'), ${NOW});
    $event = ${lit(fixture(name))};
    $r = $apply(Gatekeeper_License_Stripe::to_op($event, $xpPro, ${NOW}), Gatekeeper_License_Stripe::event_id($event));
    $after = Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}');
    echo json_encode([
      'before'  => $before,
      'changed' => $r['changed'],
      'status'  => $after['status'],
      'pro'     => Gatekeeper_Xp_Pro_Licenses::is_entitled($after, ${NOW}),
    ]);
  `);
}

describe('License Stripe: end to end through the store', () => {
  it('a subscription refund with only customer set ends the period (expired, not revoked)', () => {
    const res = refundScenario('charge-refunded-subscription');
    assert.deepStrictEqual(res, { before: true, changed: true, status: 'expired', pro: false });
  });

  it("a subscription refund whose charge carries the invoice's own payment intent still ends the period", () => {
    const res = refundScenario('charge-refunded-invoice-intent');
    assert.deepStrictEqual(res, { before: true, changed: true, status: 'expired', pro: false });
  });

  it('a partial refund leaves Pro on', () => {
    const res = refundScenario('charge-refunded-partial');
    assert.deepStrictEqual(res, { before: true, changed: false, status: 'active', pro: true });
  });

  it('renewal then cancel: invoice.paid extends, subscription.deleted expires', () => {
    const res = run(`
      $apply(Gatekeeper_License_Stripe::to_op(${lit(fixture('checkout-session-completed-monthly'))}, $xpPro, ${NOW}), 'evt_grant');
      $inv = ${lit(fixture('invoice-paid'))};
      $a = $apply(Gatekeeper_License_Stripe::to_op($inv, $xpPro, ${NOW}), Gatekeeper_License_Stripe::event_id($inv));
      $del = ${lit(fixture('subscription-deleted'))};
      $b = $apply(Gatekeeper_License_Stripe::to_op($del, $xpPro, ${NOW}), Gatekeeper_License_Stripe::event_id($del));
      echo json_encode([$a['record']['expires_at'], $a['record']['status'], $b['record']['status']]);
    `);
    assert.deepStrictEqual(res, ['2026-11-20T14:13:20Z', 'active', 'expired']);
  });
});

describe('License Stripe: a second product', () => {
  it("grants from the product's own checkout_product, plan catalog, account_param and trial", () => {
    const op = toOpOf(acmeEvent('checkout-session-completed-monthly'), '$acme');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.source, 'stripe');
    assert.strictEqual(op.family_id, ACME_ACCOUNT);
    assert.strictEqual(op.plan, 'season');
    assert.strictEqual(op.expires_at, NOW + 3 * 86400);
    assert.strictEqual(op.env, 'sandbox');
    assert.deepStrictEqual(op.refs, { sub: 'sub_1QxMonthly01', cus: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('the plan whose mode is payment is the lifetime one: no expiry', () => {
    const event = acmeEvent('checkout-session-completed-lifetime', { plan: 'founder' });
    const op = toOpOf(event, '$acme');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'founder');
    assert.strictEqual(op.expires_at, null);
    assert.deepStrictEqual(op.refs, { pi: 'pi_3QxLifetime01', cus: 'cus_Ne4iCg9mH2xQ7a' });
  });

  it('an expanded subscription still supplies trial_end', () => {
    const event = acmeEvent('checkout-session-completed-yearly-expanded');
    assert.strictEqual(toOpOf(event, '$acme').expires_at, 1790604800);
  });

  it("each product ignores the other's checkout", () => {
    assert.strictEqual(toOp('checkout-session-completed-monthly', '$acme').action, 'none');
    assert.strictEqual(toOpOf(acmeEvent('checkout-session-completed-monthly'), '$xpPro').action, 'none');
  });

  it("a plan from another product's catalog is none", () => {
    assert.strictEqual(toOpOf(acmeEvent('checkout-session-completed-monthly', { plan: 'monthly' }), '$acme').action, 'none');
    assert.strictEqual(toOpOf(acmeEvent('checkout-session-completed-monthly', { product: 'doitforthexp', plan: 'season', family_id: FAMILY }), '$xpPro').action, 'none');
  });

  it('account_param names the metadata field, and the account validator decides what is usable', () => {
    const wrongField = acmeEvent('checkout-session-completed-monthly', { account_ref: undefined, family_id: FAMILY });
    assert.strictEqual(toOpOf(wrongField, '$acme').action, 'none');
    const badValue = acmeEvent('checkout-session-completed-monthly', { account_ref: FAMILY });
    assert.strictEqual(toOpOf(badValue, '$acme').action, 'none');
  });

  it('an async payment failure revokes only its own product', () => {
    const own = acmeEvent('checkout-session-async-payment-failed', { plan: 'founder' });
    const op = toOpOf(own, '$acme');
    assert.strictEqual(op.action, 'revoke');
    assert.deepStrictEqual(op.ref, { type: 'pi', id: 'pi_3QxLifetime01' });
    assert.strictEqual(toOpOf(own, '$xpPro').action, 'none');
    assert.strictEqual(toOp('checkout-session-async-payment-failed', '$acme').action, 'none');
  });

  it('the async success of its own checkout grants', () => {
    const op = toOpOf(acmeEvent('checkout-session-async-payment-succeeded', { plan: 'founder' }), '$acme');
    assert.strictEqual(op.action, 'grant');
    assert.strictEqual(op.plan, 'founder');
    assert.strictEqual(op.family_id, ACME_ACCOUNT);
  });

  it('events that carry no metadata (invoices, endings, refunds, disputes) map the same for any product', () => {
    for (const name of ['invoice-paid', 'subscription-deleted', 'charge-refunded-full', 'charge-refunded-subscription', 'dispute-created']) {
      assert.deepStrictEqual(toOp(name, '$acme'), toOp(name, '$xpPro'));
    }
  });

  it('a product without a checkout_product matches no checkout, not even one without metadata.product', () => {
    const event = fixture('checkout-session-completed-monthly');
    delete event.data.object.metadata.product;
    const res = run(`
      $bare = [ 'account_param' => 'family_id', 'plans' => fn () => [ 'monthly' => [ 'mode' => 'subscription' ] ] ];
      echo json_encode( Gatekeeper_License_Stripe::to_op( ${lit(event)}, $bare, ${NOW} )['action'] );
    `);
    assert.strictEqual(res, 'none');
  });

  it('plans may be a plain array, and a plans callable that returns junk matches nothing', () => {
    const res = run(`
      $e = ${lit(fixture('checkout-session-completed-monthly'))};
      $asArray = [ 'checkout_product' => 'doitforthexp', 'plans' => [ 'monthly' => [ 'mode' => 'subscription' ] ] ];
      $junk    = [ 'checkout_product' => 'doitforthexp', 'plans' => fn () => 'nope' ];
      echo json_encode( [
        Gatekeeper_License_Stripe::to_op( $e, $asArray, ${NOW} )['plan'],
        Gatekeeper_License_Stripe::to_op( $e, $junk, ${NOW} )['action'],
      ] );
    `);
    assert.deepStrictEqual(res, ['monthly', 'none']);
  });
});

describe('License Stripe: hygiene', () => {
  it('files and fixtures carry no em dashes or synthetic contact data', () => {
    const files = ['../includes/class-gatekeeper-license-stripe.php', 'xp-pro-stripe.test.mjs'];
    for (const f of fs.readdirSync(path.join(HERE, 'fixtures', 'stripe'))) files.push(path.join('fixtures', 'stripe', f));
    for (const rel of files) {
      const file = path.join(HERE, rel);
      if (!fs.existsSync(file)) continue;
      const text = fs.readFileSync(file, 'utf8');
      assertNoEmDashes(text, rel);
      assertNoSyntheticData(text, rel);
    }
  });
});
