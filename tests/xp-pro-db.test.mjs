/**
 * XP Pro database primitives: the per-family lock around license writes and the atomic counters.
 * The first block runs on the SQL stand-in; the "real database" block runs the actual statements
 * against MySQL through wp-load.php in the u-wordpress container.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, xpWp, xpWpParallel, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const DEVICE = 'devicetest01';

const grantOp = (extra = {}) => ({
  action: 'grant', source: 'stripe', family_id: FAMILY, plan: 'monthly', expires_at: 1800000000, env: 'production',
  email: 'parent@doit.forthexp.com', refs: { sub: 'sub_100', cus: 'cus_100' }, ...extra,
});

describe('XP Pro db: the family lock', () => {
  it('apply re-reads the record inside the lock', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(grantOp())}, 'evt_1');
      // Another request writes a device the moment our lock is taken; a stale read would drop it.
      $GLOBALS['gk_lock_hook'] = function ( $name ) {
        $rec = $GLOBALS['gk_options']['gk_xp_pro_${FAMILY}'];
        $rec['devices'][] = 'sneaked-in-device';
        $GLOBALS['gk_options']['gk_xp_pro_${FAMILY}'] = $rec;
      };
      $r = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'extend', 'source' => 'stripe', 'ref' => ['type' => 'sub', 'id' => 'sub_100'], 'expires_at' => 1810000000], 'evt_2');
      echo json_encode(['devices' => $r['record']['devices'], 'stored' => Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')['devices'], 'locked' => $GLOBALS['wpdb']->locks]);
    `);
    assert.deepStrictEqual(res.devices, ['sneaked-in-device']);
    assert.deepStrictEqual(res.stored, ['sneaked-in-device']);
    assert.deepStrictEqual(res.locked, [], 'the lock is released');
  });

  it('attach_device re-reads inside the lock, and the lock is released after an error too', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(grantOp())}, 'evt_1');
      $GLOBALS['gk_lock_hook'] = function ( $name ) {
        unset( $GLOBALS['gk_lock_hook'] );
        $rec = $GLOBALS['gk_options']['gk_xp_pro_${FAMILY}'];
        $rec['devices'][] = 'first-device-x';
        $GLOBALS['gk_options']['gk_xp_pro_${FAMILY}'] = $rec;
      };
      Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', '${DEVICE}');
      $missing = Gatekeeper_Xp_Pro_Licenses::attach_device('9a8b7c6d-1e2f-4a3b-8c4d-5e6f7a8b9c0d', '${DEVICE}');
      echo json_encode([Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}')['devices'], $missing->get_error_code(), $GLOBALS['wpdb']->locks]);
    `);
    assert.deepStrictEqual(res[0], ['first-device-x', DEVICE]);
    assert.strictEqual(res[1], 'not_found');
    assert.deepStrictEqual(res[2], []);
  });

  it('the lock is named per family', () => {
    const res = xp(`
      $names = [];
      $GLOBALS['gk_lock_hook'] = function ( $name ) use ( &$names ) { $names[] = $name; };
      Gatekeeper_Xp_Pro_Licenses::apply(${lit(grantOp())}, 'evt_1');
      $q = array_values( array_filter( $GLOBALS['wpdb']->log, fn( $s ) => false !== strpos( $s, 'GET_LOCK' ) ) );
      echo json_encode([$names, $q[0]]);
    `);
    assert.deepStrictEqual(res[0], [`gk_xp_pro_${FAMILY}`]);
    assert.strictEqual(res[1], `SELECT GET_LOCK('gk_xp_pro_${FAMILY}', 10)`);
  });

  it('a lock that cannot be taken writes nothing and logs the event id', () => {
    const res = xp(`
      $logfile = tempnam( sys_get_temp_dir(), 'gklog' );
      ini_set( 'error_log', $logfile );
      $GLOBALS['gk_lock_result'] = '0';
      $r = Gatekeeper_Xp_Pro_Licenses::apply(${lit(grantOp())}, 'evt_stuck');
      $dev = Gatekeeper_Xp_Pro_Licenses::attach_device('${FAMILY}', '${DEVICE}');
      echo json_encode([$r['changed'], $r['lock_failed'] ?? false, $GLOBALS['gk_options'], str_contains( file_get_contents( $logfile ), 'evt_stuck' ), is_wp_error( $dev ) ? $dev->get_error_code() : 'ok']);
      unlink( $logfile );
    `);
    assert.strictEqual(res[0], false);
    assert.strictEqual(res[1], true);
    assert.deepStrictEqual(Object.keys(res[2]).filter((k) => k.startsWith('gk_xp_pro_')), [], 'nothing was written');
    assert.strictEqual(res[3], true);
    assert.strictEqual(res[4], 'busy');
  });
});

describe('XP Pro db: counters on the SQL stand-in', () => {
  it('increment returns the new value and decrement never goes below zero', () => {
    const res = xp(`
      $n = 'gk_xppro_rl_unit_x_1';
      $a = Gatekeeper_Xp_Pro_Db::increment($n);
      $b = Gatekeeper_Xp_Pro_Db::increment($n);
      Gatekeeper_Xp_Pro_Db::decrement($n); Gatekeeper_Xp_Pro_Db::decrement($n); Gatekeeper_Xp_Pro_Db::decrement($n);
      echo json_encode([$a, $b, Gatekeeper_Xp_Pro_Db::read($n)]);
    `);
    assert.deepStrictEqual(res, [1, 2, 0]);
  });

  it('counter names carry their window', () => {
    const res = xp(`
      Gatekeeper_Xp_Pro_Db::$now = 1790000045;
      $a = Gatekeeper_Xp_Pro_Db::rate_counter('Claim', '203.0.113.7', 60);
      Gatekeeper_Xp_Pro_Db::$now = 1790000090;
      $b = Gatekeeper_Xp_Pro_Db::rate_counter('Claim', '203.0.113.7', 60);
      Gatekeeper_Xp_Pro_Db::$now = 1790000110;
      $c = Gatekeeper_Xp_Pro_Db::rate_counter('Claim', '203.0.113.7', 60);
      echo json_encode([$a === $b, $a === $c, $a, Gatekeeper_Xp_Pro_Db::ai_counter('${FAMILY}')]);
    `);
    assert.strictEqual(res[0], true);
    assert.strictEqual(res[1], false);
    assert.match(res[2], /^gk_xppro_rl_claim_[0-9a-f]{32}_1790000040$/);
    assert.match(res[3], /^gk_xppro_ai_3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f_\d{8}$/);
  });
});

describe('XP Pro db: real database', () => {
  const unique = () => `gk_xppro_rl_unit${Date.now()}${Math.floor(Math.random() * 1e6)}_${'a'.repeat(32)}_`;

  it('two concurrent increments both count', async () => {
    const name = `${unique()}1790000000`;
    const script = `
      $n = '${name}';
      for ($i = 0; $i < 60; $i++) { Gatekeeper_Xp_Pro_Db::increment($n); }
      echo json_encode(true);
    `;
    try {
      await xpWpParallel([script, script, script]);
      const total = xpWp(`echo json_encode(Gatekeeper_Xp_Pro_Db::read('${name}'));`);
      assert.strictEqual(total, 180);
    } finally {
      xpWp(`global $wpdb; $wpdb->delete($wpdb->options, ['option_name' => '${name}']); echo json_encode(true);`);
    }
  });

  it('decrement is atomic and floors at zero in MySQL', () => {
    const name = `${unique()}1790000001`;
    try {
      const res = xpWp(`
        $n = '${name}';
        Gatekeeper_Xp_Pro_Db::increment($n); Gatekeeper_Xp_Pro_Db::increment($n);
        Gatekeeper_Xp_Pro_Db::decrement($n);
        $one = Gatekeeper_Xp_Pro_Db::read($n);
        Gatekeeper_Xp_Pro_Db::decrement($n); Gatekeeper_Xp_Pro_Db::decrement($n);
        echo json_encode([$one, Gatekeeper_Xp_Pro_Db::read($n)]);
      `);
      assert.deepStrictEqual(res, [1, 0]);
    } finally {
      xpWp(`global $wpdb; $wpdb->delete($wpdb->options, ['option_name' => '${name}']); echo json_encode(true);`);
    }
  });

  it('the daily cleanup deletes counters older than 2 days and keeps newer ones', () => {
    const old = `gk_xppro_rl_unitold_${'b'.repeat(32)}_${Math.floor(Date.now() / 1000) - 3 * 86400}`;
    const fresh = `gk_xppro_rl_unitnew_${'b'.repeat(32)}_${Math.floor(Date.now() / 1000) - 3600}`;
    const day = (offset) => new Date(Date.now() - offset * 86400000).toISOString().slice(0, 10).replace(/-/g, '');
    const oldAi = `gk_xppro_ai_${FAMILY}_${day(3)}`;
    const freshAi = `gk_xppro_ai_${FAMILY}_${day(0)}`;
    const names = [old, fresh, oldAi, freshAi];
    try {
      const res = xpWp(`
        foreach (${lit(names)} as $n) { Gatekeeper_Xp_Pro_Db::increment($n); }
        $deleted = Gatekeeper_Xp_Pro_Db::cleanup_counters();
        $left = [];
        foreach (${lit(names)} as $n) { $left[$n] = Gatekeeper_Xp_Pro_Db::read($n); }
        echo json_encode([$deleted >= 2, $left]);
      `);
      assert.strictEqual(res[0], true);
      assert.deepStrictEqual(res[1], { [old]: 0, [fresh]: 1, [oldAi]: 0, [freshAi]: 1 });
    } finally {
      xpWp(`global $wpdb; foreach (${lit(names)} as $n) { $wpdb->delete($wpdb->options, ['option_name' => $n]); } echo json_encode(true);`);
    }
  });

  it('a named lock held by another connection is not granted, and is granted once released', () => {
    const res = xpWp(`
      $name = 'gk_xp_pro_unit' . uniqid();
      $other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
      $other->query("SELECT GET_LOCK('" . $name . "', 1)");
      $start = microtime(true);
      $blocked = Gatekeeper_Xp_Pro_Db::lock($name, 1);
      $waited = microtime(true) - $start;
      $other->query("SELECT RELEASE_LOCK('" . $name . "')");
      $free = Gatekeeper_Xp_Pro_Db::lock($name, 1);
      Gatekeeper_Xp_Pro_Db::unlock($name);
      echo json_encode([$blocked, $waited >= 0.9, $free]);
    `);
    assert.deepStrictEqual(res, [false, true, true]);
  });

  it('apply on real tables re-reads the record after the lock (object cache bypassed)', () => {
    const family = '5d1c9a7e-3b2f-4e8a-9c6d-7f0a1b2c3d4e';
    const opt = `gk_xp_pro_${family}`;
    try {
      const res = xpWp(`
        $op = ${lit(grantOp({ family_id: family, refs: { sub: 'sub_unit_lock', cus: 'cus_unit_lock' }, email: null }))};
        Gatekeeper_Xp_Pro_Licenses::apply($op, 'evt_unit_lock_1');
        Gatekeeper_Xp_Pro_Licenses::get('${family}'); // warm the object cache
        global $wpdb;
        $rec = Gatekeeper_Xp_Pro_Licenses::get('${family}');
        $rec['devices'] = ['written-behind-our-back'];
        $wpdb->update($wpdb->options, ['option_value' => serialize($rec)], ['option_name' => '${opt}']);
        $r = Gatekeeper_Xp_Pro_Licenses::apply(['action' => 'extend', 'source' => 'stripe', 'ref' => ['type' => 'sub', 'id' => 'sub_unit_lock'], 'expires_at' => 1810000000], 'evt_unit_lock_2');
        echo json_encode($r['record']['devices']);
      `);
      assert.deepStrictEqual(res, ['written-behind-our-back']);
    } finally {
      xpWp(`
        $rec = get_option('${opt}');
        if (is_array($rec)) { delete_option('gk_xp_pro_key_' . $rec['license_key']); }
        foreach (['${opt}', 'gk_xp_pro_sub_sub_unit_lock', 'gk_xp_pro_cus_cus_unit_lock'] as $n) { delete_option($n); }
        echo json_encode(true);
      `);
    }
  });
});

describe('XP Pro db: hygiene', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-xp-pro-db.php', 'xp-pro-db.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
