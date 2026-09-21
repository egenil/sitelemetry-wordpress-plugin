// Builds dist/sitelemetry-audit-<version>.zip with one top-level folder
// sitelemetry-audit/, excluding everything listed in .distignore.
// Pure Node.js (20+), no npm dependencies and no external archiver, so the entry
// names always use forward slashes (Windows PowerShell's Compress-Archive does not).
// Usage: node build-zip.mjs [plugin-dir]   (default: the parent of tools/)
import { readdirSync, readFileSync, statSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, resolve, dirname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateRawSync } from 'node:zlib';

const here = dirname(fileURLToPath(import.meta.url));
const pluginDir = resolve(process.argv[2] || join(here, '..'));
const slug = 'sitelemetry-audit';

const mainFile = readFileSync(join(pluginDir, `${slug}.php`), 'utf8');
const version = /^\s*\*\s*Version:\s*(\S+)/m.exec(mainFile)?.[1];
if (!version) {
  console.error(`Version header not found in ${slug}.php`);
  process.exit(2);
}

const ignore = existsSync(join(pluginDir, '.distignore'))
  ? readFileSync(join(pluginDir, '.distignore'), 'utf8').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#'))
  : [];
const ignoreNames = new Set(ignore.filter((p) => !p.includes('*')));
const ignoreGlobs = ignore.filter((p) => p.includes('*')).map((p) => new RegExp(`^${p.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*')}$`));
const excluded = (rel) => rel.split(/[\\/]/).some((segment) => ignoreNames.has(segment)) || ignoreGlobs.some((re) => re.test(basename(rel)));

const files = [];
function collect(dir) {
  for (const name of readdirSync(dir).sort()) {
    const full = join(dir, name);
    const rel = relative(pluginDir, full).replace(/\\/g, '/');
    if (excluded(rel)) continue;
    if (statSync(full).isDirectory()) collect(full);
    else files.push({ rel, full });
  }
}
collect(pluginDir);

// Minimal ZIP writer: local headers + central directory, deflate, UTF-8 names.
const CRC_TABLE = new Int32Array(256);
for (let n = 0; n < 256; n += 1) {
  let c = n;
  for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  CRC_TABLE[n] = c;
}
function crc32(buf) {
  let crc = -1;
  for (let i = 0; i < buf.length; i += 1) crc = CRC_TABLE[(crc ^ buf[i]) & 0xff] ^ (crc >>> 8);
  return (crc ^ -1) >>> 0;
}
function dosDateTime(date) {
  const time = (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2);
  const day = ((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
  return { time, day };
}
function u16(v) { const b = Buffer.alloc(2); b.writeUInt16LE(v); return b; }
function u32(v) { const b = Buffer.alloc(4); b.writeUInt32LE(v >>> 0); return b; }

const locals = [];
const centrals = [];
let offset = 0;
for (const { rel, full } of files) {
  const name = Buffer.from(`${slug}/${rel}`, 'utf8');
  const data = readFileSync(full);
  const packed = deflateRawSync(data, { level: 9 });
  const { time, day } = dosDateTime(statSync(full).mtime);
  const crc = crc32(data);
  const common = Buffer.concat([u16(20), u16(0x0800), u16(8), u16(time), u16(day), u32(crc), u32(packed.length), u32(data.length), u16(name.length)]);
  const local = Buffer.concat([u32(0x04034b50), common, u16(0), name, packed]);
  centrals.push(Buffer.concat([u32(0x02014b50), u16(20), common, u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset), name]));
  locals.push(local);
  offset += local.length;
}
const centralDir = Buffer.concat(centrals);
const end = Buffer.concat([u32(0x06054b50), u16(0), u16(0), u16(files.length), u16(files.length), u32(centralDir.length), u32(offset), u16(0)]);

const distDir = join(pluginDir, 'dist');
mkdirSync(distDir, { recursive: true });
const zipPath = join(distDir, `${slug}-${version}.zip`);
writeFileSync(zipPath, Buffer.concat([...locals, centralDir, end]));
console.log(`${zipPath}\n${files.length} files under ${slug}/:`);
for (const { rel } of files) console.log(`  ${rel}`);
