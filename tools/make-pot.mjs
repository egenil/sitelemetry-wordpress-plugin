// Extracts translatable strings (text domain sitelemetry-audit) from the shipped PHP
// files into languages/sitelemetry-audit.pot and fails when a translation call lacks
// the text domain. Usage: node make-pot.mjs [plugin-dir]   (default: the parent of tools/)
// With WP-CLI available, `wp i18n make-pot . languages/sitelemetry-audit.pot` is equivalent.
import { readdirSync, readFileSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pluginDir = resolve(process.argv[2] || join(here, '..')).replace(/\\/g, '/');
const version = /^\s*\*\s*Version:\s*(\S+)/m.exec(readFileSync(join(pluginDir, 'sitelemetry-audit.php'), 'utf8'))?.[1] || '0.0.0';

function phpFiles(dir) {
  const out = [];
  for (const name of readdirSync(dir)) {
    if (['node_modules', '.git', 'tests', 'docs', 'tools', 'dist'].includes(name)) continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) out.push(...phpFiles(full));
    else if (name.endsWith('.php')) out.push(full);
  }
  return out;
}

const STRING = String.raw`'((?:[^'\\]|\\.)*)'`;
const DOMAIN = String.raw`\s*,\s*'sitelemetry-audit'`;
const patterns = [
  { re: new RegExp(String.raw`\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*${STRING}${DOMAIN}\s*\)`, 'g'), kind: 'single' },
  { re: new RegExp(String.raw`\b_n\(\s*${STRING}\s*,\s*${STRING}\s*,\s*[^,]+${DOMAIN}\s*\)`, 'g'), kind: 'plural' },
  { re: new RegExp(String.raw`\b_x\(\s*${STRING}\s*,\s*${STRING}${DOMAIN}\s*\)`, 'g'), kind: 'context' }
];
const unescape = (s) => s.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
const pot = (s) => s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');

const entries = new Map();
let calls = 0;
for (const file of phpFiles(pluginDir)) {
  const rel = relative(pluginDir, file).replace(/\\/g, '/');
  const lines = readFileSync(file, 'utf8').split(/\r?\n/);
  lines.forEach((line, index) => {
    for (const { re, kind } of patterns) {
      re.lastIndex = 0;
      let match;
      while ((match = re.exec(line))) {
        calls += 1;
        const comment = /\/\*\s*translators:\s*(.*?)\s*\*\//.exec(lines[index - 1] || '')?.[1] || '';
        let key; let entry;
        if (kind === 'single') { entry = { msgid: unescape(match[1]) }; key = `|${entry.msgid}`; }
        else if (kind === 'plural') { entry = { msgid: unescape(match[1]), plural: unescape(match[2]) }; key = `|${entry.msgid}`; }
        else { entry = { msgid: unescape(match[1]), context: unescape(match[2]) }; key = `${entry.context}|${entry.msgid}`; }
        const existing = entries.get(key) || { ...entry, refs: [], comments: new Set() };
        existing.refs.push(`${rel}:${index + 1}`);
        if (comment) existing.comments.add(comment);
        entries.set(key, existing);
      }
    }
  });
}

// Every translation call in the plugin must carry the text domain.
const bare = [];
for (const file of phpFiles(pluginDir)) {
  const rel = relative(pluginDir, file).replace(/\\/g, '/');
  readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, index) => {
    const re = new RegExp(String.raw`\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_n|_x)\(\s*${STRING}`, 'g');
    let m;
    while ((m = re.exec(line))) {
      const tail = line.slice(m.index);
      if (!/'sitelemetry-audit'/.test(tail)) bare.push(`${rel}:${index + 1}: ${tail.slice(0, 80)}`);
    }
  });
}

const header = `# Copyright (C) 2026 Sitelemetry
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: Sitelemetry Audit ${version}\\n"
"Report-Msgid-Bugs-To: https://sitelemetry.com\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: ${new Date().toISOString().slice(0, 19)}+00:00\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: \\n"
"Language-Team: \\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: sitelemetry-audit\\n"
`;
const blocks = [];
for (const entry of [...entries.values()].sort((a, b) => a.refs[0].localeCompare(b.refs[0]))) {
  const lines = [];
  for (const c of entry.comments) lines.push(`#. translators: ${c}`);
  lines.push(`#: ${entry.refs.join(' ')}`);
  if (entry.context) lines.push(`msgctxt "${pot(entry.context)}"`);
  lines.push(`msgid "${pot(entry.msgid)}"`);
  if (entry.plural) {
    lines.push(`msgid_plural "${pot(entry.plural)}"`, 'msgstr[0] ""', 'msgstr[1] ""');
  } else {
    lines.push('msgstr ""');
  }
  blocks.push(lines.join('\n'));
}
mkdirSync(join(pluginDir, 'languages'), { recursive: true });
writeFileSync(join(pluginDir, 'languages/sitelemetry-audit.pot'), `${header}\n${blocks.join('\n\n')}\n`);
console.log(`${entries.size} unique strings from ${calls} translation calls written to languages/sitelemetry-audit.pot`);
if (bare.length) {
  console.log(`\n${bare.length} translation call(s) without the text domain:`);
  for (const line of bare) console.log(`  ${line}`);
  process.exit(1);
}
process.exit(0);
