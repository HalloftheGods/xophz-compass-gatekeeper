/**
 * XP Pro license store: one record per family, keys, idempotent grants, lifetime wins,
 * the 3 day grace window and the 10 device cap.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const KEY_PATTERN = /^XP-PRO-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/;
const DAY = 86400;

const op = (overrides = {}) => ({
  action: 'grant',
  source: 'stripe',
  family_id: FAMILY,
  ref: null,
  plan: 'monthly',
  expires_at: 1800000000,
  env: 'production',
  email: 'parent@doit.forthexp.com',
  refs: { sub: 'sub_100', pi: 'pi_100', cus: 'cus_100' },
  ...overrides,
});

describe('XP Pro licenses: grant and key', () => {
  it('grant creates one key in XP-PRO-XXXX-XXXX-XXXX form and indexes it', () => {
    const res = xp(`
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $found = Gatekeeper_Xp_Pro_Licenses::find_by_key($r['record']['license_key']);
      echo json_encode(['r' => $r, 'found' => $found, 'got' => Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')]);
    `);
    assert.strictEqual(res.r.changed, true);
    assert.strictEqual(res.r.created_key, true);
    assert.match(res.r.record.license_key, KEY_PATTERN);
    assert.strictEqual(res.found.family_id, FAMILY);
    assert.strictEqual(res.got.license_key, res.r.record.license_key);
    assert.strictEqual(res.got.status, 'active');
    assert.strictEqual(res.got.plan, 'monthly');
  });

  it('find_by_key accepts lowercase and refuses malformed or unknown keys', () => {
    const res = xp(`
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      echo json_encode([
        'lower' => Gatekeeper_Xp_Pro_Licenses::find_by_key(strtolower($r['record']['license_key'])) !== null,
        'bad'   => Gatekeeper_Xp_Pro_Licenses::find_by_key('XP-PRO-AAAA') === null,
        'cx'    => Gatekeeper_Xp_Pro_Licenses::find_by_key('CX-AAAA-BBBB-CCCC') === null,
        'none'  => Gatekeeper_Xp_Pro_Licenses::find_by_key('XP-PRO-AAAA-BBBB-CCCC') === null,
      ]);
    `);
    assert.deepStrictEqual(res, { lower: true, bad: true, cx: true, none: true });
  });

  it('a second grant keeps the key', () => {
    const res = xp(`
      $a = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $b = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'yearly' }))}, 'evt_2');
      echo json_encode(['a' => $a['record']['license_key'], 'b' => $b['record']['license_key'], 'created' => $b['created_key'], 'plan' => $b['record']['plan']]);
    `);
    assert.strictEqual(res.created, false);
    assert.strictEqual(res.a, res.b);
    assert.strictEqual(res.plan, 'yearly');
  });

  it('grant indexes the subscription, payment intent and customer', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      echo json_encode([
        'sub' => Gatekeeper_Xp_Pro_Licenses::find_by_ref('sub', 'sub_100')['family_id'] ?? null,
        'pi'  => Gatekeeper_Xp_Pro_Licenses::find_by_ref('pi', 'pi_100')['family_id'] ?? null,
        'cus' => Gatekeeper_Xp_Pro_Licenses::find_by_ref('cus', 'cus_100')['family_id'] ?? null,
        'bad' => Gatekeeper_Xp_Pro_Licenses::find_by_ref('xyz', 'sub_100'),
        'unk' => Gatekeeper_Xp_Pro_Licenses::find_by_ref('sub', 'sub_999'),
      ]);
    `);
    assert.deepStrictEqual(res, { sub: FAMILY, pi: FAMILY, cus: FAMILY, bad: null, unk: null });
  });

  it('a grant for another family cannot take over an indexed subscription', () => {
    const other = '9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d';
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ family_id: other }))}, 'evt_2');
      echo json_encode(['sub' => Gatekeeper_Xp_Pro_Licenses::find_by_ref('sub', 'sub_100')['family_id']]);
    `);
    assert.strictEqual(res.sub, FAMILY);
  });

  it('a grant without a valid family_id changes nothing', () => {
    const res = xp(`
      $a = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ family_id: 'NOT-A-UUID' }))}, 'evt_1');
      $b = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ family_id: null }))}, 'evt_2');
      echo json_encode([$a['changed'], $b['changed']]);
    `);
    assert.deepStrictEqual(res, [false, false]);
  });
});

describe('XP Pro licenses: extend, expire, revoke', () => {
  it('extend through a sub ref moves expires_at', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit({ action: 'extend', source: 'stripe', family_id: null, ref: { type: 'sub', id: 'sub_100' }, expires_at: 1802592000 })}, 'evt_2');
      echo json_encode($r);
    `);
    assert.strictEqual(res.changed, true);
    assert.strictEqual(res.record.expires_at, '2027-02-14T08:00:00Z');
    assert.strictEqual(res.record.status, 'active');
  });

  it('an unknown ref changes nothing', () => {
    const res = xp(`
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit({ action: 'expire', source: 'stripe', family_id: null, ref: { type: 'sub', id: 'sub_404' } })}, 'evt_1');
      echo json_encode($r);
    `);
    assert.strictEqual(res.changed, false);
    assert.strictEqual(res.record, null);
  });

  it('replayed event id changes nothing', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $again = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'yearly' }))}, 'evt_1');
      echo json_encode(['changed' => $again['changed'], 'plan' => Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')['plan']]);
    `);
    assert.strictEqual(res.changed, false);
    assert.strictEqual(res.plan, 'monthly');
  });

  it('remembers only the last 50 event ids', () => {
    const res = xp(`
      for ($i = 1; $i <= 60; $i++) {
        Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_' . $i);
      }
      $ids = Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')['event_ids'];
      echo json_encode(['count' => count($ids), 'first' => $ids[0], 'last' => end($ids)]);
    `);
    assert.deepStrictEqual(res, { count: 50, first: 'evt_11', last: 'evt_60' });
  });

  it('expire then extend brings an expired subscription back, revoke does not come back by extend', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $ref = ['type' => 'sub', 'id' => 'sub_100'];
      $exp = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'expire', 'source' => 'stripe', 'ref' => $ref], 'evt_2');
      $ext = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'extend', 'source' => 'stripe', 'ref' => $ref, 'expires_at' => 1810000000], 'evt_3');
      $rev = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'revoke', 'source' => 'stripe', 'ref' => ['type' => 'pi', 'id' => 'pi_100']], 'evt_4');
      $ext2 = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'extend', 'source' => 'stripe', 'ref' => $ref, 'expires_at' => 1820000000], 'evt_5');
      echo json_encode([$exp['record']['status'], $ext['record']['status'], $rev['record']['status'], $ext2['record']['status']]);
    `);
    assert.deepStrictEqual(res, ['expired', 'active', 'revoked', 'revoked']);
  });

  it('revoke then re-grant reuses the key and sets status active', () => {
    const res = xp(`
      $a = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'revoke', 'source' => 'stripe', 'ref' => ['type' => 'pi', 'id' => 'pi_100']], 'evt_2');
      $b = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ refs: { sub: 'sub_200', pi: 'pi_200', cus: 'cus_100' } }))}, 'evt_3');
      echo json_encode(['same' => $a['record']['license_key'] === $b['record']['license_key'], 'status' => $b['record']['status'], 'created' => $b['created_key']]);
    `);
    assert.deepStrictEqual(res, { same: true, status: 'active', created: false });
  });
});

describe('XP Pro licenses: lifetime wins', () => {
  it('a grant of lifetime then a subscription expire leaves plan lifetime, expires_at null, status active', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'lifetime', expires_at: null, refs: { pi: 'pi_life', cus: 'cus_100' } }))}, 'evt_2');
      Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'expire', 'source' => 'stripe', 'ref' => ['type' => 'sub', 'id' => 'sub_100']], 'evt_3');
      $r = Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}');
      echo json_encode(['plan' => $r['plan'], 'expires_at' => $r['expires_at'], 'status' => $r['status']]);
    `);
    assert.deepStrictEqual(res, { plan: 'lifetime', expires_at: null, status: 'active' });
  });

  it('a later subscription grant or extend never shortens lifetime', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'lifetime', expires_at: null }))}, 'evt_1');
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'monthly', expires_at: 1800000000, refs: { sub: 'sub_300' } }))}, 'evt_2');
      Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'extend', 'source' => 'stripe', 'ref' => ['type' => 'sub', 'id' => 'sub_300'], 'expires_at' => 1810000000], 'evt_3');
      $r = Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}');
      echo json_encode(['plan' => $r['plan'], 'expires_at' => $r['expires_at'], 'status' => $r['status']]);
    `);
    assert.deepStrictEqual(res, { plan: 'lifetime', expires_at: null, status: 'active' });
  });

  it('a refund still revokes a lifetime license', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'lifetime', expires_at: null }))}, 'evt_1');
      $r = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'revoke', 'source' => 'stripe', 'ref' => ['type' => 'pi', 'id' => 'pi_100']], 'evt_2');
      echo json_encode(['status' => $r['record']['status']]);
    `);
    assert.strictEqual(res.status, 'revoked');
  });
});

describe('XP Pro licenses: entitlement', () => {
  const NOW = 1790000000;
  const record = (extra) => ({ status: 'active', ...extra });

  it('is_entitled is true until expires_at + 3 days and false after', () => {
    const exp = NOW;
    const iso = new Date(exp * 1000).toISOString().replace('.000Z', 'Z');
    const res = xp(`
      $rec = ${lit(record({ expires_at: iso }))};
      $t = ${exp};
      echo json_encode([
        'before'    => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t - 10),
        'at'        => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t),
        'day2'      => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t + 2 * ${DAY}),
        'edge'      => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t + 3 * ${DAY} - 1),
        'day3'      => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t + 3 * ${DAY}),
        'day4'      => Gatekeeper_Xp_Pro_Licenses::is_entitled($rec, $t + 4 * ${DAY}),
      ]);
    `);
    assert.deepStrictEqual(res, { before: true, at: true, day2: true, edge: true, day3: false, day4: false });
  });

  it('lifetime is entitled, expired and revoked are not', () => {
    const res = xp(`
      $now = ${NOW};
      echo json_encode([
        'lifetime' => Gatekeeper_Xp_Pro_Licenses::is_entitled(${lit(record({ expires_at: null }))}, $now),
        'expired'  => Gatekeeper_Xp_Pro_Licenses::is_entitled(${lit({ status: 'expired', expires_at: null })}, $now),
        'revoked'  => Gatekeeper_Xp_Pro_Licenses::is_entitled(${lit({ status: 'revoked', expires_at: null })}, $now),
        'garbage'  => Gatekeeper_Xp_Pro_Licenses::is_entitled(${lit(record({ expires_at: 'soon' }))}, $now),
      ]);
    `);
    assert.deepStrictEqual(res, { lifetime: true, expired: false, revoked: false, garbage: false });
  });

  it('public_view reports pro, plan, ISO expiry and the family', () => {
    const res = xp(`
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      echo json_encode(Gatekeeper_Xp_Pro_Licenses::public_view($r['record'], 1790000000));
    `);
    assert.deepStrictEqual(Object.keys(res).sort(), ['env', 'expires_at', 'family_id', 'license_key', 'plan', 'pro']);
    assert.strictEqual(res.pro, true);
    assert.strictEqual(res.family_id, FAMILY);
    assert.strictEqual(res.expires_at, '2027-01-15T08:00:00Z');
    assert.strictEqual(res.env, 'production');
  });
});

describe('XP Pro licenses: devices', () => {
  it('attach_device allows 10 and refuses the 11th with device_limit 409', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $oks = [];
      for ($i = 1; $i <= 10; $i++) {
        $oks[] = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'device-number-' . $i);
      }
      $again = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'device-number-3');
      $eleventh = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'device-number-11');
      echo json_encode([
        'oks'      => array_map(fn($v) => $v === true, $oks),
        'again'    => $again === true,
        'code'     => $eleventh->get_error_code(),
        'status'   => $eleventh->get_error_data()['status'],
        'devices'  => count(Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')['devices']),
      ]);
    `);
    assert.deepStrictEqual(res.oks, Array(10).fill(true));
    assert.strictEqual(res.again, true);
    assert.strictEqual(res.code, 'device_limit');
    assert.strictEqual(res.status, 409);
    assert.strictEqual(res.devices, 10);
  });

  it('attach_device refuses a bad device id and an unknown family', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $bad = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'short');
      $unknown = Gatekeeper_Xp_Pro_Licenses::attach_device('9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', 'device-number-1');
      echo json_encode([$bad->get_error_data()['status'], $unknown->get_error_data()['status']]);
    `);
    assert.deepStrictEqual(res, [400, 404]);
  });

  it('reset_devices empties the list so the 11th device fits', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      for ($i = 1; $i <= 10; $i++) {
        Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'device-number-' . $i);
      }
      $reset = Gatekeeper_Xp_Pro_Licenses::reset_devices('${FAMILY}');
      $after = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', 'device-number-11');
      echo json_encode(['reset' => $reset, 'after' => $after === true, 'unknown' => Gatekeeper_Xp_Pro_Licenses::reset_devices('9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d')]);
    `);
    assert.deepStrictEqual(res, { reset: true, after: true, unknown: false });
  });
});

describe('XP Pro licenses: validators', () => {
  it('invalid family_id or device_id is rejected by the validators', () => {
    const res = xp(`
      $u = 'Gatekeeper_Xp_Pro_Licenses::is_uuid4';
      $d = 'Gatekeeper_Xp_Pro_Licenses::is_device_id';
      echo json_encode([
        'ok'       => $u('${FAMILY}'),
        'upper'    => $u(strtoupper('${FAMILY}')),
        'v1'       => $u('3f6c2a1e-8b4d-1c7a-9e2f-1a2b3c4d5e6f'),
        'badvar'   => $u('3f6c2a1e-8b4d-4c7a-1e2f-1a2b3c4d5e6f'),
        'newline'  => $u("${FAMILY}\\n"),
        'dev_ok'   => $d('abcd1234'),
        'dev_dash' => $d('Abc_def-123456'),
        'dev_64'   => $d(str_repeat('a', 64)),
        'dev_7'    => $d(str_repeat('a', 7)),
        'dev_65'   => $d(str_repeat('a', 65)),
        'dev_space'=> $d('abcd 1234'),
        'dev_nl'   => $d("abcd1234\\n"),
      ]);
    `);
    assert.deepStrictEqual(res, {
      ok: true, upper: false, v1: false, badvar: false, newline: false,
      dev_ok: true, dev_dash: true, dev_64: true, dev_7: false, dev_65: false, dev_space: false, dev_nl: false,
    });
  });
});

describe('XP Pro licenses: isolation and hygiene', () => {
  it('never touches the legacy license option', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      $legacy = array_filter(array_keys($GLOBALS['gk_options']), fn($k) => $k === Gatekeeper_Licenses::OPTION_LICENSES);
      $all = array_keys($GLOBALS['gk_options']);
      sort($all);
      echo json_encode(['legacy' => array_values($legacy), 'all' => $all]);
    `);
    assert.deepStrictEqual(res.legacy, []);
    assert.ok(res.all.every((k) => k.startsWith('gk_xp_pro_')));
  });

  it('files contain no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-xp-pro-licenses.php', 'xp-pro-licenses.test.mjs', 'xp-pro-bootstrap.php', 'xp-pro-php.mjs']) {
      const file = path.join(HERE, rel);
      if (fs.existsSync(file)) assertNoEmDashes(fs.readFileSync(file, 'utf8'), rel);
    }
  });
});

describe('XP Pro licenses: email lookup', () => {
  it('find_by_email is case-insensitive, lists each family once, and ignores unknown addresses', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op())}, 'evt_1');
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(op({ plan: 'yearly' }))}, 'evt_2');
      echo json_encode([
        'upper' => count(Gatekeeper_Xp_Pro_Licenses::find_by_email('PARENT@doit.forthexp.com')),
        'none'  => count(Gatekeeper_Xp_Pro_Licenses::find_by_email('nobody@doit.forthexp.com')),
        'empty' => count(Gatekeeper_Xp_Pro_Licenses::find_by_email('')),
      ]);
    `);
    assert.deepStrictEqual(res, { upper: 1, none: 0, empty: 0 });
  });
});
