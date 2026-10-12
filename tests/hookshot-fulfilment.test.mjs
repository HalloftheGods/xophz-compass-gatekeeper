/**
 * forthexp checkout fulfilment: the minted key belongs to the buyer or to nobody, never to the admin.
 */

import { describe, it, assert } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { php } from './php.mjs';

function fulfil(sessionPhp, usersPhp = '') {
  return php(`
    $log = tempnam(sys_get_temp_dir(), 'gk-log');
    ini_set('error_log', $log);
    ${usersPhp}
    $plugin = (new ReflectionClass('Xophz_Compass_Gatekeeper'))->newInstanceWithoutConstructor();
    $plugin->handle_hookshot_incoming(array(
      'type' => 'checkout.session.completed',
      'data' => array('object' => ${sessionPhp}),
    ), 42);
    $keys = array_values(get_option(Gatekeeper_Keys::OPTION_KEYS, array()));
    $activations = array_values(array_filter($GLOBALS['gk_actions'], fn($a) => $a['hook'] === 'xp_subscription_activated'));
    echo json_encode(array(
      'keys'       => $keys,
      'activated'  => array_map(fn($a) => $a['args'][0], $activations),
      'log'        => file_get_contents($log),
    ));
    unlink($log);
  `);
}

const ADMIN_AND_BUYER = `
  $GLOBALS['gk_users'][] = (object) array('ID' => 1, 'user_email' => 'admin@customer.invalid');
  $GLOBALS['gk_users'][] = (object) array('ID' => 7, 'user_email' => 'buyer@customer.invalid');
`;

describe('Gatekeeper hookshot fulfilment: key ownership', () => {
  it('mints the key for the user matching the customer email', () => {
    const res = fulfil(`array('customer_email' => 'BUYER@customer.invalid', 'metadata' => array('product' => 'forthexp', 'tier' => 'pro'))`, ADMIN_AND_BUYER);
    assert.strictEqual(res.keys.length, 1);
    assert.strictEqual(res.keys[0].user_id, 7);
    assert.deepStrictEqual(res.activated, [7]);
    assert.strictEqual(res.log, '');
  });

  it('leaves the key unowned with the email recorded when no user matches', () => {
    const res = fulfil(`array('customer_email' => 'stranger@customer.invalid', 'metadata' => array('product' => 'forthexp', 'tier' => 'pro'))`, ADMIN_AND_BUYER);
    assert.strictEqual(res.keys.length, 1);
    assert.strictEqual(res.keys[0].user_id, 0);
    assert.strictEqual(res.keys[0].owner_email, 'stranger@customer.invalid');
    assert.deepStrictEqual(res.activated, [0]);
    assert.match(res.log, /webhook 42; minted key key_[0-9a-f]+ unowned with the customer email recorded/);
    assert.ok(!res.log.includes('stranger@customer.invalid'), 'the log line must not carry the customer email');
  });

  it('reads the email from customer_details when customer_email is empty', () => {
    const res = fulfil(`array('customer_email' => null, 'customer_details' => array('email' => 'buyer@customer.invalid'), 'metadata' => array('product' => 'forthexp'))`, ADMIN_AND_BUYER);
    assert.strictEqual(res.keys[0].user_id, 7);
  });

  it('leaves the key unowned when the checkout carries no email at all', () => {
    const res = fulfil(`array('metadata' => array('product' => 'forthexp'))`, ADMIN_AND_BUYER);
    assert.strictEqual(res.keys[0].user_id, 0);
    assert.strictEqual(res.keys[0].owner_email, null);
    assert.match(res.log, /unowned and no customer email/);
  });
});
