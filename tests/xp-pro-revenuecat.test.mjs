/**
 * RevenueCat adapter: every row of the spec 3.3 table, from webhook v1 shaped payloads.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes, assertNoSyntheticData } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';

const fixture = (name) => JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'revenuecat', `${name}.json`), 'utf8'));

function toOp(name) {
  return xp(`echo json_encode(Gatekeeper_Xp_Pro_RevenueCat::to_op(${lit(fixture(name))}));`);
}

describe('XP Pro RevenueCat: grants', () => {
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

describe('XP Pro RevenueCat: endings and no-ops', () => {
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
    const res = xp(`echo json_encode([Gatekeeper_Xp_Pro_RevenueCat::to_op([])['action'], Gatekeeper_Xp_Pro_RevenueCat::event_id([])]);`);
    assert.deepStrictEqual(res, ['none', '']);
  });

  it('event_id is the RevenueCat event id', () => {
    const res = xp(`echo json_encode(Gatekeeper_Xp_Pro_RevenueCat::event_id(${lit(fixture('renewal-yearly'))}));`);
    assert.strictEqual(res, 'rc-evt-0003');
  });
});

describe('XP Pro RevenueCat: through the store', () => {
  it('purchase, support refund, then re-purchase reuses the key', () => {
    const res = xp(`
      $run = function ($payload) {
        return Gatekeeper_Xp_Pro_Licenses::apply(Gatekeeper_Xp_Pro_RevenueCat::to_op($payload), Gatekeeper_Xp_Pro_RevenueCat::event_id($payload));
      };
      $a = $run(${lit(fixture('initial-purchase-monthly'))});
      $b = $run(${lit(fixture('cancellation-support'))});
      $c = $run(${lit(fixture('renewal-yearly'))});
      echo json_encode([$a['created_key'], $b['record']['status'], $c['record']['status'], $a['record']['license_key'] === $c['record']['license_key'], $c['record']['sources']['revenuecat']['store']]);
    `);
    assert.deepStrictEqual(res, [true, 'revoked', 'active', true, 'PLAY_STORE']);
  });
});

describe('XP Pro RevenueCat: hygiene', () => {
  it('files and fixtures carry no em dashes or synthetic contact data', () => {
    const files = ['../includes/class-gatekeeper-xp-pro-revenuecat.php', 'xp-pro-revenuecat.test.mjs'];
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
