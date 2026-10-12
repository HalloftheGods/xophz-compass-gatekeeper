/**
 * Gatekeeper Submodule Test Runner
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './licenses.test.mjs';
import './hookshot-fulfilment.test.mjs';
import './xp-pro-licenses.test.mjs';
import './xp-pro-stripe.test.mjs';
import './xp-pro-revenuecat.test.mjs';
import './xp-pro-intake.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  for (const f of results.failures) {
    console.error(`FAIL ${f.suiteName} > ${f.testName}\n${f.error.split('\n').slice(0, 3).join('\n')}`);
  }
  console.error(`Gatekeeper Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`Gatekeeper Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
