/**
 * Runs a PHP snippet after tests/bootstrap.php inside the u-wordpress container
 * and parses the JSON it echoes. The shared harness stubs get_option() as a no-op,
 * which cannot hold licenses, so Gatekeeper brings its own bootstrap.
 */

import { spawnSync } from 'node:child_process';

const BOOTSTRAP = '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/tests/bootstrap.php';

export function php(code) {
  const script = `<?php\nrequire '${BOOTSTRAP}';\n${code}`;
  const result = spawnSync('docker', ['exec', '-i', 'u-wordpress', 'php'], {
    input: script,
    encoding: 'utf8',
    timeout: 15000,
  });
  if (result.status !== 0) {
    throw new Error(`PHP execution failed with exit code ${result.status}: ${result.stderr || result.stdout}`);
  }
  try {
    return JSON.parse(result.stdout.trim());
  } catch {
    throw new Error(`Failed to parse PHP JSON output: ${result.stdout}. Stderr: ${result.stderr}`);
  }
}
