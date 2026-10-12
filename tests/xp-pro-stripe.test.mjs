/**
 * Stripe adapter: every row of the spec 3.3 table, from real-shaped events, plus the
 * refund paths run end to end through the license store.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes, assertNoSyntheticData } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const NOW = 1790000000;

const fixture = (name) => JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'stripe', `${name}.json`), 'utf8'));

function toOp(name) {
  return xp(`echo json_encode(Gatekeeper_Xp_Pro_Stripe::to_op(${lit(fixture(name))}, ${NOW}));`);
}

describe('XP Pro Stripe: checkout.session.completed', () => {
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
    const res = xp(`
      $base = ${lit(fixture('checkout-session-completed-monthly'))};
      $badFamily = $base;
      $badFamily['data']['object']['metadata']['family_id'] = 'not-a-uuid';
      $badPlan = $base;
      $badPlan['data']['object']['metadata']['plan'] = 'platinum';
      echo json_encode([
        Gatekeeper_Xp_Pro_Stripe::to_op($badFamily, ${NOW})['action'],
        Gatekeeper_Xp_Pro_Stripe::to_op($badPlan, ${NOW})['action'],
      ]);
    `);
    assert.deepStrictEqual(res, ['none', 'none']);
  });
});

describe('XP Pro Stripe: renewals and endings', () => {
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
    const res = xp(`
      $e = ${lit(fixture('invoice-paid'))};
      unset($e['data']['object']['subscription']);
      echo json_encode(Gatekeeper_Xp_Pro_Stripe::to_op($e, ${NOW})['action']);
    `);
    assert.strictEqual(res, 'none');
  });

  it('customer.subscription.deleted expires by sub ref', () => {
    const op = toOp('subscription-deleted');
    assert.strictEqual(op.action, 'expire');
    assert.deepStrictEqual(op.ref, { type: 'sub', id: 'sub_1QxMonthly01' });
  });
});

describe('XP Pro Stripe: refunds and disputes', () => {
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
    const res = xp(`echo json_encode(Gatekeeper_Xp_Pro_Stripe::event_id(${lit(fixture('invoice-paid'))}));`);
    assert.strictEqual(res, 'evt_1QxInvoicePaid');
  });
});

/** Grant from the monthly fixture, then apply the named fixture, then ask is_entitled. */
function refundScenario(name) {
  return xp(`
    $grant = Gatekeeper_Xp_Pro_Stripe::to_op(${lit(fixture('checkout-session-completed-monthly'))}, ${NOW});
    Gatekeeper_Xp_Pro_Licenses::apply($grant, 'evt_grant');
    $before = Gatekeeper_Xp_Pro_Licenses::is_entitled(Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}'), ${NOW});
    $event = ${lit(fixture(name))};
    $r = Gatekeeper_Xp_Pro_Licenses::apply(Gatekeeper_Xp_Pro_Stripe::to_op($event, ${NOW}), Gatekeeper_Xp_Pro_Stripe::event_id($event));
    $after = Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}');
    echo json_encode([
      'before'  => $before,
      'changed' => $r['changed'],
      'status'  => $after['status'],
      'pro'     => Gatekeeper_Xp_Pro_Licenses::is_entitled($after, ${NOW}),
    ]);
  `);
}

describe('XP Pro Stripe: end to end through the store', () => {
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
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(Gatekeeper_Xp_Pro_Stripe::to_op(${lit(fixture('checkout-session-completed-monthly'))}, ${NOW}), 'evt_grant');
      $inv = ${lit(fixture('invoice-paid'))};
      $a = Gatekeeper_Xp_Pro_Licenses::apply(Gatekeeper_Xp_Pro_Stripe::to_op($inv, ${NOW}), Gatekeeper_Xp_Pro_Stripe::event_id($inv));
      $del = ${lit(fixture('subscription-deleted'))};
      $b = Gatekeeper_Xp_Pro_Licenses::apply(Gatekeeper_Xp_Pro_Stripe::to_op($del, ${NOW}), Gatekeeper_Xp_Pro_Stripe::event_id($del));
      echo json_encode([$a['record']['expires_at'], $a['record']['status'], $b['record']['status']]);
    `);
    assert.deepStrictEqual(res, ['2026-11-20T14:13:20Z', 'active', 'expired']);
  });
});

describe('XP Pro Stripe: hygiene', () => {
  it('files and fixtures carry no em dashes or synthetic contact data', () => {
    const files = ['../includes/class-gatekeeper-xp-pro-stripe.php', 'xp-pro-stripe.test.mjs'];
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
