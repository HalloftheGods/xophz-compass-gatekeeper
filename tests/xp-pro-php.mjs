/**
 * Runs a PHP snippet after tests/xp-pro-bootstrap.php inside the u-wordpress container
 * and parses the JSON it echoes. Same idea as php.mjs, with the XP Pro stubs and classes loaded.
 */

import { spawnSync } from 'node:child_process';

const BOOTSTRAP = '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/tests/xp-pro-bootstrap.php';

/** A JS value as a PHP expression: JSON in a nowdoc, decoded to arrays. */
export function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

export function xp(code) {
  const script = `<?php\nrequire '${BOOTSTRAP}';\n${code}`;
  const result = spawnSync('docker', ['exec', '-i', 'u-wordpress', 'php'], {
    input: script,
    encoding: 'utf8',
    timeout: 20000,
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
