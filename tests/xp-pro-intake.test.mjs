/**
 * License intake: a delivery counts only on a webhook id some product configured and that Hookshot
 * verified; the id picks the product and the adapter, never the payload; the key email goes out
 * once and is built from the product's strings. Run for XP Pro and for a second product.
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
      'record' => Gatekeeper_License_Store::get('xp-pro', '${FAMILY}'),
      'mail'   => $GLOBALS['gk_mail'],
    ]);
  `);
}

describe('License intake: XP Pro verified deliveries', () => {
  it('a verified Stripe checkout grants and emails once', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`);
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
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
    `);
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.record.event_ids.length, 1);
  });

  it('a later event for the same family does not email again', () => {
    const renewal = JSON.parse(JSON.stringify(stripeEvent));
    renewal.id = 'evt_1QxCheckoutAgain';
    const res = intake(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(renewal)}, ${STRIPE_ID});
    `);
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.record.event_ids.length, 2);
  });

  it('a verified RevenueCat purchase grants without an email', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(rcEvent)}, ${RC_ID});`);
    assert.strictEqual(res.record.status, 'active');
    assert.strictEqual(res.record.sources.revenuecat.store, 'APP_STORE');
    assert.strictEqual(res.mail.length, 0);
  });

  it('an object payload is accepted', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(json_decode(json_encode(${lit(stripeEvent)})), ${STRIPE_ID});`);
    assert.strictEqual(res.record.status, 'active');
  });
});

describe('License intake: XP Pro deliveries that change nothing', () => {
  it('an unverified delivery changes nothing', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { verified: [] });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('verified on a different webhook id does not count', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { verified: [RC_ID] });
    assert.strictEqual(res.record, null);
  });

  it('without Hookshot loaded nothing changes', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, { withHookshot: false });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('a delivery on an unconfigured webhook id changes nothing', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, 99);`, { verified: [99, STRIPE_ID, RC_ID] });
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('nothing is configured by default, so webhook id 0 or any id is ignored', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, 0); Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});`, {
      config: {},
      verified: [0, STRIPE_ID],
    });
    assert.strictEqual(res.record, null);
  });

  it('a RevenueCat payload on the Stripe webhook id is ignored', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(rcEvent)}, ${STRIPE_ID});`);
    assert.strictEqual(res.record, null);
  });

  it('a Stripe payload on the RevenueCat webhook id is ignored', () => {
    const res = intake(`Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${RC_ID});`);
    assert.strictEqual(res.record, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('a goodwill refund from the Stripe webhook ends the period, from an unverified one it does not', () => {
    const refund = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'stripe', 'charge-refunded-subscription.json'), 'utf8'));
    const ok = intake(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(refund)}, ${STRIPE_ID});
    `);
    assert.strictEqual(ok.record.status, 'expired');
    const blocked = intake(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Hookshot_Signature::$ok = [];
      Gatekeeper_License_Intake::handle(${lit(refund)}, ${STRIPE_ID});
    `);
    assert.strictEqual(blocked.record.status, 'active');
  });
});

/** The second product: a site license with its own account field, devices, plans and webhooks option. */
const ACME_SITE = 'site-abc123';
const ACME_STRIPE_ID = 21;
const ACME_RC_ID = 22;
const acmeProduct = (strings = {}) => ({
  label: 'Acme Pro', app_name: 'Acme Quest', key_prefix: 'ACME-', option_prefix: 'gk_lic_acme_',
  account_param: 'site_id', device_param: 'install_id', max_devices: 2, grace_seconds: 0, trial_seconds: 3 * 86400,
  checkout_product: 'acmepro', webhooks_option: 'gatekeeper_acme_webhooks', stripe_setup_option: 'gatekeeper_acme_stripe_setup',
  route_prefix: 'acme', strings,
});
/** PHP that builds $acme: the JSON config plus its plans and its site id validator. */
const acmeConfig = (strings = {}) => `
  $acme = ${lit(acmeProduct(strings))};
  $acme['account_validator'] = fn ( $s ) => 1 === preg_match( '/\\Asite-[a-z0-9]{4,12}\\z/', $s );
  $acme['plans'] = fn () => array(
    'season'  => array( 'price' => 9, 'mode' => 'subscription', 'interval' => 'month', 'trial_days' => 3, 'store_product_id' => 'acme_season', 'on_sale' => true ),
    'founder' => array( 'price' => 120, 'mode' => 'payment', 'interval' => null, 'trial_days' => 0, 'store_product_id' => 'acme_founder', 'on_sale' => false ),
  );
`;

const acmeStripeEvent = JSON.parse(JSON.stringify(stripeEvent));
acmeStripeEvent.data.object.metadata = { product: 'acmepro', plan: 'season', site_id: ACME_SITE };
const acmeRcEvent = JSON.parse(JSON.stringify(rcEvent));
acmeRcEvent.event.app_user_id = ACME_SITE;
acmeRcEvent.event.product_id = 'acme_season';

/** Register XP Pro and Acme, configure both products' webhooks, then run `code`; answers with both stores' views. */
function twoProducts(code, { verified = [STRIPE_ID, RC_ID, ACME_STRIPE_ID, ACME_RC_ID], strings = {} } = {}) {
  return xp(`
    ${acmeConfig(strings)}
    gk_register_license_product( 'acme', $acme );
    update_option('gatekeeper_xp_pro_webhooks', ${lit({ stripe: STRIPE_ID, revenuecat: RC_ID })});
    update_option('gatekeeper_acme_webhooks', ${lit({ stripe_test: ACME_STRIPE_ID, revenuecat: ACME_RC_ID })});
    update_option('admin_email', 'support@doit.forthexp.com');
    class Hookshot_Signature {
      public static $ok = [];
      public static function was_verified( $id ) { return in_array( (int) $id, self::$ok, true ); }
    }
    Hookshot_Signature::$ok = ${lit(verified)};
    ${code}
    echo json_encode([
      'acme' => Gatekeeper_License_Store::get('acme', '${ACME_SITE}'),
      'xp'   => Gatekeeper_License_Store::get('xp-pro', '${FAMILY}'),
      'acmeOnXp' => Gatekeeper_License_Store::get('xp-pro', '${ACME_SITE}'),
      'mail' => $GLOBALS['gk_mail'],
      'probe' => $GLOBALS['probe'] ?? null,
    ]);
  `);
}

describe('License intake: a second product', () => {
  it('its Stripe webhook grants under its own account field and prefix, and emails from its strings', () => {
    const res = twoProducts(`Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});`);
    assert.strictEqual(res.acme.status, 'active');
    assert.strictEqual(res.acme.plan, 'season');
    assert.strictEqual(res.acme.site_id, ACME_SITE);
    assert.ok(!('family_id' in res.acme), 'the record names the account in the product field only');
    assert.match(res.acme.license_key, /^ACME-/);
    assert.strictEqual(res.xp, null);
    assert.strictEqual(res.acmeOnXp, null);
    assert.strictEqual(res.mail.length, 1);
    assert.strictEqual(res.mail[0].to, 'parent@doit.forthexp.com');
    assert.strictEqual(res.mail[0].subject, 'Your Acme Pro key');
    assert.ok(res.mail[0].message.includes(res.acme.license_key));
    assert.ok(res.mail[0].message.includes('Open Acme Quest.'));
    assert.ok(res.mail[0].message.includes('Settings > Acme Pro > Restore with key'));
    assert.ok(res.mail[0].message.includes('up to 2 devices'));
    assert.ok(res.mail[0].message.includes('Questions? Write to support@doit.forthexp.com.'));
  });

  it('a stripe_test slot feeds the Stripe adapter and the same event twice grants once', () => {
    const res = twoProducts(`
      $found = Gatekeeper_License_Products::by_webhook_id(${ACME_STRIPE_ID});
      $GLOBALS['probe'] = array( $found[0]['id'], $found[1] );
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
    `);
    assert.deepStrictEqual(res.probe, ['acme', 'stripe']);
    assert.strictEqual(res.acme.event_ids.length, 1);
    assert.strictEqual(res.mail.length, 1);
  });

  it('its RevenueCat webhook grants without an email', () => {
    const res = twoProducts(`Gatekeeper_License_Intake::handle(${lit(acmeRcEvent)}, ${ACME_RC_ID});`);
    assert.strictEqual(res.acme.status, 'active');
    assert.strictEqual(res.acme.plan, 'season');
    assert.strictEqual(res.acme.sources.revenuecat.store, 'APP_STORE');
    assert.strictEqual(res.mail.length, 0);
    assert.strictEqual(res.xp, null);
  });

  it('XP Pro and the second product keep their own webhooks apart', () => {
    const res = twoProducts(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
    `);
    assert.strictEqual(res.xp.status, 'active');
    assert.match(res.xp.license_key, /^XP-PRO-/);
    assert.strictEqual(res.acme.status, 'active');
    assert.match(res.acme.license_key, /^ACME-/);
    assert.strictEqual(res.mail.length, 2);
    assert.deepStrictEqual(res.mail.map((m) => m.subject), ['Your XP Pro key', 'Your Acme Pro key']);
  });

  it("one product's checkout on the other product's webhook is ignored", () => {
    const res = twoProducts(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, ${ACME_STRIPE_ID});
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${STRIPE_ID});
    `);
    assert.strictEqual(res.xp, null);
    assert.strictEqual(res.acme, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('a webhook id no product owns is ignored, verified or not', () => {
    const res = twoProducts(`
      Gatekeeper_License_Intake::handle(${lit(stripeEvent)}, 99);
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, 99);
      Gatekeeper_License_Intake::handle(${lit(acmeRcEvent)}, 0);
    `, { verified: [99, 0, STRIPE_ID, RC_ID, ACME_STRIPE_ID, ACME_RC_ID] });
    assert.strictEqual(res.xp, null);
    assert.strictEqual(res.acme, null);
    assert.strictEqual(res.mail.length, 0);
  });

  it('an unverified delivery on its webhook changes nothing', () => {
    const res = twoProducts(`Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});`, { verified: [STRIPE_ID, RC_ID] });
    assert.strictEqual(res.acme, null);
    assert.strictEqual(res.mail.length, 0);
  });
});

describe('License intake: the key email', () => {
  it("uses the product's key_email_lines and support_line", () => {
    const res = xp(`
      ${acmeConfig({ key_email_subject: 'Welcome to Acme', support_line: 'Need help? {support_email}' })}
      $acme['strings']['key_email_lines'] = fn ( array $record ) => array( 'Hi from Acme', 'Key=' . $record['license_key'] );
      gk_register_license_product( 'acme', $acme );
      update_option('gatekeeper_acme_webhooks', ${lit({ stripe: ACME_STRIPE_ID })});
      update_option('admin_email', 'help@doit.forthexp.com');
      class Hookshot_Signature { public static function was_verified( $id ) { return true; } }
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
      echo json_encode([ $GLOBALS['gk_mail'], Gatekeeper_License_Store::get('acme', '${ACME_SITE}')['license_key'] ]);
    `);
    assert.strictEqual(res[0].length, 1);
    assert.strictEqual(res[0][0].subject, 'Welcome to Acme');
    assert.strictEqual(res[0][0].message, `Hi from Acme\nKey=${res[1]}\nNeed help? help@doit.forthexp.com`);
  });

  it('leaves the support line out when the site has no admin email', () => {
    const res = twoProducts(`
      update_option('admin_email', '');
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
    `);
    assert.strictEqual(res.mail.length, 1);
    assert.ok(!res.mail[0].message.includes('Questions?'));
  });

  it('sends the key alone when a product line builder throws', () => {
    const res = xp(`
      ${acmeConfig()}
      $acme['strings']['key_email_lines'] = function ( array $record ) { throw new RuntimeException( 'boom' ); };
      gk_register_license_product( 'acme', $acme );
      update_option('gatekeeper_acme_webhooks', ${lit({ stripe: ACME_STRIPE_ID })});
      class Hookshot_Signature { public static function was_verified( $id ) { return true; } }
      Gatekeeper_License_Intake::handle(${lit(acmeStripeEvent)}, ${ACME_STRIPE_ID});
      echo json_encode([ $GLOBALS['gk_mail'], Gatekeeper_License_Store::get('acme', '${ACME_SITE}')['license_key'] ]);
    `);
    assert.strictEqual(res[0].length, 1);
    assert.ok(res[0][0].message.startsWith(`Your key: ${res[1]}`));
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

describe('License intake: hygiene', () => {
  it('files carry no em dashes', () => {
    for (const rel of ['../includes/class-gatekeeper-license-intake.php', '../includes/class-gatekeeper-xp-pro-intake.php', '../admin/class-xophz-compass-gatekeeper-admin.php', 'xp-pro-intake.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, rel), 'utf8'), rel);
    }
  });
});
