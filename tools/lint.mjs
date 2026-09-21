// Syntax-checks every PHP file of the plugin (php -l) on PHP 7.4 and 8.3 through php-wasm.
// Usage: node lint.mjs [plugin-dir] [versions]   (defaults: the parent of tools/, "7.4,8.3")
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pluginDir = resolve(process.argv[2] || join(here, '..')).replace(/\\/g, '/');
const versions = (process.argv[3] || '7.4,8.3').split(',');

function phpFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    if (name === 'node_modules' || name === '.git' || name === 'dist') continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) out.push(...phpFiles(full));
    else if (name.endsWith('.php')) out.push(full);
  }
  return out;
}

let pid = 1000;
let failures = 0;
const files = phpFiles(pluginDir).map((f) => relative(pluginDir, f).replace(/\\/g, '/')).sort();
for (const version of versions) {
  for (const file of files) {
    const php = new PHP(await loadNodeRuntime(version, { emscriptenOptions: { processId: ++pid } }));
    php.mkdir('/plugin');
    await php.mount('/plugin', createNodeFsMountHandler(pluginDir));
    const result = await php.cli(['php', '-d', 'display_errors=1', '-l', `/plugin/${file}`]);
    const stdout = (await result.stdoutText).trim();
    const stderr = (await result.stderrText).trim();
    const code = await result.exitCode;
    const ok = code === 0 && /No syntax errors detected/.test(stdout);
    if (!ok) failures += 1;
    console.log(`${ok ? 'ok  ' : 'FAIL'} php ${version}  ${file}${ok ? '' : `\n     ${stdout}\n     ${stderr}`}`);
  }
}
console.log(`\n${files.length} files x ${versions.length} PHP versions, ${failures} failure(s)`);
process.exit(failures ? 1 : 0);
