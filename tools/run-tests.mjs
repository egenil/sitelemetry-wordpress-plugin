// Runs the plugin's PHP test suite (tests/run.php) on PHP 7.4 and 8.3 through php-wasm.
// Usage: node run-tests.mjs [plugin-dir] [versions]   (defaults: the parent of tools/, "7.4,8.3")
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pluginDir = resolve(process.argv[2] || join(here, '..')).replace(/\\/g, '/');
const versions = (process.argv[3] || '7.4,8.3').split(',');

let pid = 2000;
let failed = false;
for (const version of versions) {
  console.log(`=== PHP ${version} ===`);
  const php = new PHP(await loadNodeRuntime(version, { emscriptenOptions: { processId: ++pid } }));
  php.mkdir('/plugin');
  await php.mount('/plugin', createNodeFsMountHandler(pluginDir));
  const result = await php.cli(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '/plugin/tests/run.php']);
  const stdout = await result.stdoutText;
  const stderr = (await result.stderrText).trim();
  const code = await result.exitCode;
  process.stdout.write(stdout);
  if (stderr) console.log(`stderr:\n${stderr}`);
  console.log(`exit code ${code}\n`);
  if (code !== 0) failed = true;
}
process.exit(failed ? 1 : 0);
