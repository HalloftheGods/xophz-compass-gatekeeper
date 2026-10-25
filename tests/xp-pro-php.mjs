/**
 * Runs a PHP snippet after tests/xp-pro-bootstrap.php inside the u-wordpress container
 * and parses the JSON it echoes. Same idea as php.mjs, with the XP Pro stubs and classes loaded.
 */

import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BOOTSTRAP = '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper/tests/xp-pro-bootstrap.php';
const PLUGIN = '/var/www/html/wp-content/plugins/xophz-compass-gatekeeper';
const INCLUDES = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'includes');

/**
 * require_once lines for the license engine, the license classes and every XP Pro file that exists
 * (the facade, the db alias and the intake shim).
 */
function engineRequires() {
  const first = ['class-gatekeeper-license-db.php', 'class-gatekeeper-license-products.php', 'class-gatekeeper-license-store.php', 'gatekeeper-license-functions.php'];
  const rest = fs
    .readdirSync(INCLUDES)
    .filter((f) => /^class-gatekeeper-(license|xp-pro)-.*\.php$/.test(f) && !first.includes(f))
    .sort();
  return [...first, ...rest].map((f) => `require_once '${PLUGIN}/includes/${f}';`).join('\n');
}

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

/**
 * Like xp(), but loads the real WordPress (wp-load.php) with its database, so the SQL primitives
 * run against MySQL. The Gatekeeper classes are required if the plugin is not active.
 */
export function xpWp(code) {
  const requires = engineRequires();
  const script = `<?php\ndefine('WP_USE_THEMES', false);\nrequire '/var/www/html/wp-load.php';\n${requires}\n${code}`;
  const result = spawnSync('docker', ['exec', '-i', 'u-wordpress', 'php'], { input: script, encoding: 'utf8', timeout: 60000 });
  if (result.status !== 0) {
    throw new Error(`PHP execution failed with exit code ${result.status}: ${result.stderr || result.stdout}`);
  }
  try {
    return JSON.parse(result.stdout.trim());
  } catch {
    throw new Error(`Failed to parse PHP JSON output: ${result.stdout}. Stderr: ${result.stderr}`);
  }
}

/** Run several PHP scripts at the same time in separate processes; resolves to their parsed outputs. */
export function xpWpParallel(codes) {
  return Promise.all(
    codes.map(
      (code) =>
        new Promise((resolve, reject) => {
          const requires = engineRequires();
          const script = `<?php\ndefine('WP_USE_THEMES', false);\nrequire '/var/www/html/wp-load.php';\n${requires}\n${code}`;
          const child = spawn('docker', ['exec', '-i', 'u-wordpress', 'php']);
          let out = '';
          let err = '';
          child.stdout.on('data', (d) => (out += d));
          child.stderr.on('data', (d) => (err += d));
          child.on('close', (status) => {
            if (status !== 0) return reject(new Error(`PHP exit ${status}: ${err || out}`));
            try {
              resolve(JSON.parse(out.trim()));
            } catch {
              reject(new Error(`Bad output: ${out}. Stderr: ${err}`));
            }
          });
          child.stdin.end(script);
        }),
    ),
  );
}
