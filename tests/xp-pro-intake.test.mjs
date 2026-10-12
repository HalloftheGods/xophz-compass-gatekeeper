/**
 * XP Pro intake: a delivery counts only on a configured webhook id that Hookshot verified;
 * the id, not the payload, picks the adapter; the key email goes out once.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { xp, lit } from './xp-pro-php.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FAMILY = '3f6c2a1e-8b4d-4c7a-9e2f-1a2b3c4d5e6f';
const STRIPE_ID = 11;
const RC_ID = 12;

const stripeEvent = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'stripe', 'checkout-session-completed-monthly.json'), 'utf8'));
const rcEvent = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'revenuecat', 'initial-purchase-monthly.json'), 'utf8'));

/** Set up options and a Hookshot_Signature stub that has verified `verified`, then run `code`. */
function intake(code, { verified = [STRIPE_ID, RC_ID], config = { stripe: STRIPE_ID, revenuecat: RC_ID }, withHookshot = true } = {}) {
  return xp(`
    update_option('gatekeeper_xp_pro_webhooks', ${lit(config)});
    update_option('admin_email', 'support@doit.forthexp.com');
    ${withHookshot ? `
    class Hookshot_Signature {
      public static $ok = [];
      public static function was_verified( $id ) { return in_array( (int) $id, self::$ok, true ); }
    }
    Hookshot_Signature::$ok = ${lit(verified)};` : ''}
    ${code}
    echo json_encode([
      'record' => Gatekeeper_Xp_Pro_Licenses::get('${FAMILY}'),
      'mail'   => $GLOBALS['gk_mail'],
    ]);
  `);
}

describe('XP Pro intake: verified deliveries', () => {
  it('a verified Stripe checkout grants and emails once', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`);
    assert.strictEqual(res.record.status, 'active');
    assert.strictEqual(res.record.plan, 'monthly');
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.mail[0].to, 'parent@doit.forthexp.com');
    assert.strictEqual(res.mail[0].subject, 'Your XP Pro key');
    assert.ok(res.mail[0].message.includes(res.record.license_key));
    assert.ok(res.mail[0].message.includes('Settings > XP Pro > Restore with key'));
    assert.ok(res.mail[0].message.includes('support@doit.forthexp.com'));
  });

  it('the same event delivered twice grants once and emails once', () => {
    const res = intake(`
      Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
    `);
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.record.event_ids.length, 1);
  });

  it('a later event for the same family does not email again', () => {
    const renewal = JSON.parse(JSON.stringify(stripeEvent));
    renewal.id = 'evt_1QxCheckoutAgain';
    const res = intake(`
      Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_Xp_Pro_Intake::handle(${lit(renewal)}, ${STRIPE_ID});
    `);
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.record.event_ids.length, 2);
  });

  it('a verified RevenueCat purchase grants without an email', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(rcEvent)}, ${RC_ID});`);
    assert.strictEqual(res.record.status, 'active');
    assert.strictEqual(res.record.sources.revenuecat.store, 'APP_STORE');
    assert.strictEqual(res.mail.length, 0);
  });

  it('an object payload is accepted', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(json_decode(json_encode(${lit(stripeEvent)})), ${STRIPE_ID});`);
    assert.strictEqual(res.record.status, 'active');
  });
});

describe('XP Pro intake: deliveries that change nothing', () => {
  it('an unverified delivery changes nothing', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { verified: [] });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('verified on a different webhook id does not count', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { verified: [RC_ID] });
    assert.strictEqual(res.record, null);
  });

  it('without Hookshot loaded nothing changes', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { withHookshot: false });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('a delivery on an unconfigured webhook id changes nothing', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, 99);`, { verified: [99, STRIPE_ID, RC_ID] });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('nothing is configured by default, so webhook id 0 or any id is ignored', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, 0); Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, {
      config: {},
      verified: [0, STRIPE_ID],
    });
    assert.strictEqual(res.record, null);
  });

  it('a RevenueCat payload on the Stripe webhook id is ignored', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(rcEvent)}, ${STRIPE_ID});`);
    assert.strictEqual(res.record, null);
  });

  it('a Stripe payload on the RevenueCat webhook id is ignored', () => {
    const res = intake(`Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${RC_ID});`);
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('a goodwill refund from the Stripe webhook ends the period, from an unverified one it does not', () => {
    const refund = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'stripe', 'charge-refunded-subscription.json'), 'utf8'));
    const ok = intake(`
      Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_Xp_Pro_Intake::handle(${lit(refund)}, ${STRIPE_ID});
    `);
    assert.strictEqual(ok.record.status, 'expired');
    const blocked = intake(`
      Gatekeeper_Xp_Pro_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Hookshot_Signature::$ok = [];
      Gatekeeper_Xp_Pro_Intake::handle(${lit(refund)}, ${STRIPE_ID});
    `);
    assert.strictEqual(blocked.record.status, 'active');
  });
});

describe('XP Pro admin settings', () => {
  const ADMIN = "require_once '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/admin/class-xophz-compass-gatekeeper-admin.php';";

  it('saves the two webhook ids and the daily limit', () => {
    const res = xp(`
      ${ADMIN}
      Xophz_Compass_Gatekeeper_Admin::save_xp_pro_settings(['stripe_webhook_id' => '31', 'revenuecat_webhook_id' => ' 32 ', 'ai_daily_limit' => '250']);
      echo json_encode([get_option('gatekeeper_xp_pro_webhooks'), get_option('xp_pro_ai_daily_limit')]);
    `);
    assert.deepStrictEqual(res, [{ stripe: 31, revenuecat: 32 }, 250]);
  });

  it('rejects junk: non-numeric ids become 0, a limit below 1 keeps the old value', () => {
    const res = xp(`
      ${ADMIN}
      update_option('xp_pro_ai_daily_limit', 100);
      Xophz_Compass_Gatekeeper_Admin::save_xp_pro_settings(['stripe_webhook_id' => 'abc', 'revenuecat_webhook_id' => '-4', 'ai_daily_limit' => '0']);
      echo json_encode([get_option('gatekeeper_xp_pro_webhooks'), get_option('xp_pro_ai_daily_limit')]);
    `);
    assert.deepStrictEqual(res, [{ stripe: 0, revenuecat: 0 }, 100]);
  });

  it('the save and reset handlers refuse a user without manage_options', () => {
    const res = xp(`
      ${ADMIN}
      function current_user_can( $cap ) { return false; }
      function check_admin_referer( $action ) { return true; }
      function wp_die( $message = '', $title = '', $args = [] ) { throw new RuntimeException( 'wp_die' ); }
      function esc_html__( $t, $d = '' ) { return $t; }
      $admin = new Xophz_Compass_Gatekeeper_Admin( 'gatekeeper', '1' );
      $_POST = ['stripe_webhook_id' => '31', 'revenuecat_webhook_id' => '32', 'ai_daily_limit' => '5', 'family_id' => '${FAMILY}'];
      $out = [];
      foreach ( ['handle_save_xp_pro', 'handle_reset_xp_pro_devices'] as $method ) {
        try { $admin->$method(); $out[] = 'ran'; } catch ( RuntimeException $e ) { $out[] = $e->getMessage(); }
      }
      echo json_encode([$out, get_option('gatekeeper_xp_pro_webhooks', 'unset')]);
    `);
    assert.deepStrictEqual(res, [['wp_die', 'wp_die'], 'unset']);
  });
});

describe('XP Pro intake: hygiene', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-xp-pro-intake.php', '../admin/class-xophz-compass-gatekeeper-admin.php', 'xp-pro-intake.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
