/**
 * Gatekeeper Submodule Test Runner
 */

import { harness } from '../../xophz-compass/tests/harness/test-framework.mjs';
import './licenses.test.mjs';
import './hookshot-fulfilment.test.mjs';

const results = await harness.run();
if (results.failed > 0) {
  console.error(`Gatekeeper Tests FAILED: ${results.failed} failed, ${results.passed} passed.`);
  process.exit(1);
} else {
  console.log(`Gatekeeper Tests PASSED: ${results.passed} passed.`);
  process.exit(0);
}
