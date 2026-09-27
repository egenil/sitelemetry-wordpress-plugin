// Checks every languages/sitelemetry-audit-LOCALE.po against the template and
// compiles it to a .mo file (GNU gettext format, readable by WordPress).
// Pure Node.js; no gettext tools needed.
//
// Usage: node make-mo.mjs [plugin-dir] [--sync]   (default: the parent of tools/)
//   --sync  first merges the current .pot into every .po, like msgmerge: kept
//           translations stay, new strings are added untranslated, strings no
//           longer in the template are dropped, references and translator
//           comments are taken from the template.
//
// Fails (exit 1) when a .po cannot be parsed, a translation drops or adds a
// printf placeholder, a plural entry has the wrong number of forms, or a
// compiled .mo does not read back to the same translations. Untranslated strings
// are reported but do not fail the run.
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const sync = args.includes('--sync');
const pluginDir = resolve(args.find((a) => !a.startsWith('--')) || join(here, '..'));
const langDir = join(pluginDir, 'languages');
const DOMAIN = 'sitelemetry-audit';

// --- PO parsing and writing -------------------------------------------------

const unquote = (s) => JSON.parse(s.replace(/\\([^"\\nrt])/g, '\\\\$1'));
const quote = (s) => `"${s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t')}"`;

export function parsePo(text, file) {
  const entries = [];
  let entry = null;
  let field = null;
  const start = () => {
    if (entry && entry.msgid !== undefined) entries.push(entry);
    entry = { comments: [], extracted: [], refs: [], flags: [], msgstr: [] };
    field = null;
  };
  start();
  text.split(/\r?\n/).forEach((raw, index) => {
    const line = raw.trim();
    const fail = (why) => { throw new Error(`${file}:${index + 1}: ${why}`); };
    if (line === '') { if (entry.msgid !== undefined) start(); return; }
    if (line.startsWith('#~')) return;
    if (line.startsWith('#.')) { if (entry.msgid !== undefined) start(); entry.extracted.push(line.slice(2).trim()); return; }
    if (line.startsWith('#:')) { if (entry.msgid !== undefined) start(); entry.refs.push(line.slice(2).trim()); return; }
    if (line.startsWith('#,')) { if (entry.msgid !== undefined) start(); entry.flags.push(...line.slice(2).split(',').map((f) => f.trim()).filter(Boolean)); return; }
    if (line.startsWith('#')) { if (entry.msgid !== undefined) start(); entry.comments.push(line.slice(1).trim()); return; }
    let m;
    if ((m = /^msgctxt\s+(".*")$/.exec(line))) { if (entry.msgid !== undefined) start(); entry.msgctxt = unquote(m[1]); field = ['msgctxt']; return; }
    if ((m = /^msgid\s+(".*")$/.exec(line))) { if (entry.msgid !== undefined) start(); entry.msgid = unquote(m[1]); field = ['msgid']; return; }
    if (/^msg(?:id_plural|str)/.test(line) && entry.msgid === undefined) fail('msgid_plural or msgstr without a msgid before it');
    if ((m = /^msgid_plural\s+(".*")$/.exec(line))) { entry.msgid_plural = unquote(m[1]); field = ['msgid_plural']; return; }
    if ((m = /^msgstr\s+(".*")$/.exec(line))) { entry.msgstr[0] = unquote(m[1]); field = ['msgstr', 0]; return; }
    if ((m = /^msgstr\[(\d+)\]\s+(".*")$/.exec(line))) { entry.msgstr[Number(m[1])] = unquote(m[2]); field = ['msgstr', Number(m[1])]; return; }
    if (/^".*"$/.test(line) && field) {
      const value = unquote(line);
      if (field[0] === 'msgstr') entry.msgstr[field[1]] += value; else entry[field[0]] += value;
      return;
    }
    fail(`cannot parse: ${line.slice(0, 60)}`);
  });
  if (entry && entry.msgid !== undefined) entries.push(entry);
  const headerEntry = entries.find((e) => e.msgid === '' && e.msgctxt === undefined);
  const headers = {};
  for (const h of (headerEntry ? headerEntry.msgstr[0] : '').split('\n')) {
    const i = h.indexOf(':');
    if (i > 0) headers[h.slice(0, i).trim()] = h.slice(i + 1).trim();
  }
  return { headerEntry, headers, entries: entries.filter((e) => e !== headerEntry) };
}

const keyOf = (e) => (e.msgctxt !== undefined ? `${e.msgctxt}\u0004${e.msgid}` : e.msgid);

function writePo(po) {
  const out = [];
  if (po.headerEntry) {
    for (const c of po.headerEntry.comments) out.push(`# ${c}`.trimEnd());
    out.push('msgid ""', 'msgstr ""');
    for (const line of po.headerEntry.msgstr[0].split('\n').filter(Boolean)) out.push(quote(`${line}\n`));
    out.push('');
  }
  for (const e of po.entries) {
    for (const c of e.extracted) out.push(`#. ${c}`);
    for (const r of e.refs) out.push(`#: ${r}`);
    if (e.flags.length) out.push(`#, ${e.flags.join(', ')}`);
    if (e.msgctxt !== undefined) out.push(`msgctxt ${quote(e.msgctxt)}`);
    out.push(`msgid ${quote(e.msgid)}`);
    if (e.msgid_plural !== undefined) {
      out.push(`msgid_plural ${quote(e.msgid_plural)}`);
      e.msgstr.forEach((s, i) => out.push(`msgstr[${i}] ${quote(s || '')}`));
    } else {
      out.push(`msgstr ${quote(e.msgstr[0] || '')}`);
    }
    out.push('');
  }
  return out.join('\n');
}

// --- Checks ------------------------------------------------------------------

// printf conversions as WordPress uses them: %s, %d, %1$s, %2$d ...; "%%" is a literal.
function placeholders(text) {
  const found = [];
  const re = /%(?:(\d+)\$)?[-+ 0#']*\d*(?:\.\d+)?([bcdeEfFgGosuxX%])/g;
  let m;
  let next = 1;
  while ((m = re.exec(text))) {
    if (m[2] === '%') continue;
    const position = m[1] ? Number(m[1]) : next++;
    found.push(`${position}:${m[2]}`);
  }
  return found.sort().join(' ');
}

function nplurals(headers) {
  const m = /nplurals\s*=\s*(\d+)/.exec(headers['Plural-Forms'] || '');
  return m ? Number(m[1]) : 2;
}

// --- MO compile and read-back -------------------------------------------------

function compileMo(po) {
  const items = [];
  items.push({ key: '', value: po.headerEntry ? po.headerEntry.msgstr[0] : '' });
  for (const e of po.entries) {
    if (e.flags.includes('fuzzy')) continue;
    const forms = e.msgid_plural !== undefined ? e.msgstr : [e.msgstr[0]];
    if (!forms.length || forms.some((f) => !f)) continue;
    const key = (e.msgctxt !== undefined ? `${e.msgctxt}\u0004` : '') + e.msgid + (e.msgid_plural !== undefined ? `\u0000${e.msgid_plural}` : '');
    items.push({ key, value: forms.join('\u0000') });
  }
  items.sort((a, b) => Buffer.compare(Buffer.from(a.key, 'utf8'), Buffer.from(b.key, 'utf8')));
  const n = items.length;
  const origTable = 28;
  const transTable = origTable + n * 8;
  let offset = transTable + n * 8;
  const keys = items.map((i) => Buffer.from(i.key, 'utf8'));
  const values = items.map((i) => Buffer.from(i.value, 'utf8'));
  const head = Buffer.alloc(28);
  head.writeUInt32LE(0x950412de, 0);
  head.writeUInt32LE(0, 4);
  head.writeUInt32LE(n, 8);
  head.writeUInt32LE(origTable, 12);
  head.writeUInt32LE(transTable, 16);
  head.writeUInt32LE(0, 20);
  head.writeUInt32LE(offset, 24);
  const orig = Buffer.alloc(n * 8);
  const trans = Buffer.alloc(n * 8);
  const data = [];
  keys.forEach((k, i) => { orig.writeUInt32LE(k.length, i * 8); orig.writeUInt32LE(offset, i * 8 + 4); data.push(k, Buffer.from([0])); offset += k.length + 1; });
  values.forEach((v, i) => { trans.writeUInt32LE(v.length, i * 8); trans.writeUInt32LE(offset, i * 8 + 4); data.push(v, Buffer.from([0])); offset += v.length + 1; });
  return Buffer.concat([head, orig, trans, ...data]);
}

export function readMo(buf) {
  const magic = buf.readUInt32LE(0);
  const read = magic === 0x950412de ? (o) => buf.readUInt32LE(o) : magic === 0xde120495 ? (o) => buf.readUInt32BE(o) : null;
  if (!read) throw new Error('not a .mo file');
  if (read(4) !== 0) throw new Error(`unsupported .mo revision ${read(4)}`);
  const n = read(8);
  const origTable = read(12);
  const transTable = read(16);
  const map = new Map();
  for (let i = 0; i < n; i += 1) {
    const kl = read(origTable + i * 8); const ko = read(origTable + i * 8 + 4);
    const vl = read(transTable + i * 8); const vo = read(transTable + i * 8 + 4);
    if (ko + kl > buf.length || vo + vl > buf.length || buf[ko + kl] !== 0 || buf[vo + vl] !== 0) throw new Error(`entry ${i} is out of bounds`);
    map.set(buf.toString('utf8', ko, ko + kl), buf.toString('utf8', vo, vo + vl));
  }
  return map;
}

// --- Main --------------------------------------------------------------------

const template = parsePo(readFileSync(join(langDir, `${DOMAIN}.pot`), 'utf8'), `${DOMAIN}.pot`);
const templateKeys = new Map(template.entries.map((e) => [keyOf(e), e]));
const files = readdirSync(langDir).filter((f) => f.startsWith(`${DOMAIN}-`) && f.endsWith('.po')).sort();
let failed = false;
for (const file of files) {
  const locale = file.slice(DOMAIN.length + 1, -3);
  const path = join(langDir, file);
  const errors = [];
  let po;
  try {
    po = parsePo(readFileSync(path, 'utf8'), file);
  } catch (error) {
    console.log(`FAIL ${file}: ${error.message}`);
    failed = true;
    continue;
  }
  if (sync) {
    const current = new Map(po.entries.map((e) => [keyOf(e), e]));
    po.entries = template.entries.map((t) => {
      const kept = current.get(keyOf(t));
      const forms = t.msgid_plural !== undefined ? nplurals(po.headers) : 1;
      const msgstr = kept && (kept.msgid_plural !== undefined) === (t.msgid_plural !== undefined) ? kept.msgstr.slice(0, forms) : [];
      while (msgstr.length < forms) msgstr.push('');
      return { ...t, flags: kept ? kept.flags.filter((f) => f !== 'fuzzy') : [], msgstr };
    });
    writeFileSync(path, writePo(po));
  }
  if (po.headers.Language !== locale) errors.push(`header Language is "${po.headers.Language || ''}", expected "${locale}"`);
  if (!/charset=UTF-8/i.test(po.headers['Content-Type'] || '')) errors.push('Content-Type must declare charset=UTF-8');
  const forms = nplurals(po.headers);
  let untranslated = 0;
  let stale = 0;
  for (const e of po.entries) {
    if (!templateKeys.has(keyOf(e))) { stale += 1; continue; }
    const translations = e.msgid_plural !== undefined ? e.msgstr : [e.msgstr[0]];
    if (translations.some((t) => !t)) { untranslated += 1; continue; }
    if (e.msgid_plural !== undefined && e.msgstr.length !== forms) errors.push(`"${e.msgid.slice(0, 50)}": ${e.msgstr.length} plural forms, header says ${forms}`);
    const expected = placeholders(e.msgid);
    const expectedPlural = e.msgid_plural !== undefined ? placeholders(e.msgid_plural) : expected;
    translations.forEach((t, i) => {
      const got = placeholders(t);
      const ok = got === expected || got === expectedPlural || (forms === 1 && e.msgid_plural !== undefined && got === expectedPlural);
      if (!ok) errors.push(`"${e.msgid.slice(0, 50)}" form ${i}: placeholders [${got}] instead of [${expected}]`);
    });
  }
  const missing = [...templateKeys.keys()].filter((k) => !po.entries.some((e) => keyOf(e) === k)).length;
  const mo = compileMo(po);
  const back = readMo(mo);
  for (const e of po.entries) {
    const translations = e.msgid_plural !== undefined ? e.msgstr : [e.msgstr[0]];
    if (translations.some((t) => !t) || e.flags.includes('fuzzy')) continue;
    const key = (e.msgctxt !== undefined ? `${e.msgctxt}\u0004` : '') + e.msgid + (e.msgid_plural !== undefined ? `\u0000${e.msgid_plural}` : '');
    if (back.get(key) !== translations.join('\u0000')) errors.push(`"${e.msgid.slice(0, 50)}" does not read back from the .mo`);
  }
  if (!errors.length) writeFileSync(join(langDir, `${DOMAIN}-${locale}.mo`), mo);
  const total = templateKeys.size;
  const done = total - untranslated - missing;
  console.log(`${errors.length ? 'FAIL' : 'ok  '} ${locale.padEnd(12)} ${done}/${total} translated${stale ? `, ${stale} not in the template` : ''}, ${errors.length ? '.mo not written' : `${back.size - 1} messages in ${DOMAIN}-${locale}.mo (${mo.length} bytes)`}`);
  for (const error of errors) console.log(`     ${error}`);
  if (errors.length) failed = true;
}
if (!files.length) console.log('No .po files in languages/.');
process.exit(failed ? 1 : 0);
