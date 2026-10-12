/**
 * License validation: only minted keys unlock, and the preview keys work only on a local install.
 */

import { describe, it, assert } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { php } from './php.mjs';

function validate(key, { env = 'production', setup = '' } = {}) {
  return php(`
    $GLOBALS['gk_env_type'] = ${JSON.stringify(env)};
    ${setup}
    echo json_encode(Gatekeeper_Licenses::validate(${JSON.stringify(key)}, 'device-1'));
  `);
}

describe('Gatekeeper licenses: preview keys', () => {
  for (const key of ['CX-DEV-PREVIEW', 'QUANTUM-VIP', 'cx-dev-preview']) {
    it(`refuses ${key} outside a local install`, () => {
      for (const env of ['production', 'staging', 'development']) {
        const res = validate(key, { env });
        assert.strictEqual(res.valid, false, `${key} unlocked on ${env}`);
      }
    });
  }

  it('honours CX-DEV-PREVIEW when the environment type is local', () => {
    const res = validate('CX-DEV-PREVIEW', { env: 'local' });
    assert.strictEqual(res.valid, true);
    assert.strictEqual(res.tier, 'vip_bundle');
  });
});

describe('Gatekeeper licenses: unknown keys', () => {
  for (const key of ['CX-AAAA-BBBB-VIP', 'CX-MASTER-0000-0000', 'CX-ST-AAAA-BBBB-CCCC', 'CX-VIP-AAAA-BBBB-CCCC']) {
    it(`refuses the made-up key ${key}`, () => {
      const res = validate(key, { env: 'local' });
      assert.strictEqual(res.valid, false);
      assert.strictEqual(res.tier, undefined);
    });
  }
});

describe('Gatekeeper licenses: minted keys', () => {
  it('validates a minted key and its tier', () => {
    const res = php(`
      $record = Gatekeeper_Licenses::mint_license('vip_bundle', 'octocat', null);
      echo json_encode(Gatekeeper_Licenses::validate(strtolower($record['license_key']), 'device-1'));
    `);
    assert.strictEqual(res.valid, true);
    assert.strictEqual(res.tier, 'vip_bundle');
    assert.strictEqual(res.github_user, 'octocat');
  });

  it('refuses a revoked key', () => {
    const res = php(`
      $record = Gatekeeper_Licenses::mint_license('standard');
      $all = Gatekeeper_Licenses::get_all_licenses();
      $all[$record['license_key']]['status'] = 'revoked';
      update_option(Gatekeeper_Licenses::OPTION_LICENSES, $all);
      echo json_encode(Gatekeeper_Licenses::validate($record['license_key'], 'device-1'));
    `);
    assert.strictEqual(res.valid, false);
  });
});
