import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { crc32, deflateRawSync, inflateRawSync } from 'node:zlib';

import { redactStructuredText } from './redact';
import { REDACTED, usableSecrets } from './secret-values';

// A retained trace records `Authorization: Bearer <API_TOKEN>` verbatim and Playwright has
// no hook to leave a header out, so the archives are rewritten after the run instead.

export { REDACTED, usableSecrets };

const EOCD_SIGNATURE = 0x06054b50;
const CENTRAL_SIGNATURE = 0x02014b50;
const LOCAL_SIGNATURE = 0x04034b50;
const STORED = 0;
const DEFLATED = 8;

interface ZipEntry {
  name: Buffer;
  data: Buffer;
}

/** Archive entries and loose files with these extensions are rewritten as text. */
const TEXT_EXTENSIONS = new Set([
  '.json',
  '.trace',
  '.network',
  '.txt',
  '.html',
  '.md',
  '.log',
  '.xml',
]);

/** Loose files under a results directory that are rewritten; `.html` is left to the report step. */
const LOOSE_TEXT_EXTENSIONS = new Set(['.json', '.txt', '.md', '.log']);

const REPORT_DATA =
  /(<template id="playwrightReportBase64">data:application\/zip;base64,)([A-Za-z0-9+/=\s]*)(<\/template>)/;

/**
 * Every archive, the report's embedded data and every plain-text attachment under `dir`
 * with credentials replaced; returns how many files changed.
 */
export async function redactArchivesIn(dir: string, secrets: readonly string[]): Promise<number> {
  let changed = 0;
  for (const file of await filesUnder(dir)) {
    try {
      const extension = path.extname(file).toLowerCase();
      const name = path.basename(file);
      if (extension === '.zip') {
        const original = await readFile(file);
        const result = redactZipDetailed(original, secrets);
        if (result.status === 'unsupported') {
          console.warn(`  Not redacted (unsupported archive): ${file}`);
        } else if (result.zip !== null) {
          await writeFile(file, result.zip);
          changed += 1;
        }
      } else if (name === 'index.html') {
        const original = await readFile(file, 'utf8');
        const redacted = redactReportHtml(original, secrets);
        if (redacted !== original) {
          await writeFile(file, redacted);
          changed += 1;
        }
      } else if (LOOSE_TEXT_EXTENSIONS.has(extension) || name === 'error-context.md') {
        const original = await readFile(file, 'utf8');
        const redacted = redactStructuredText(original, secrets);
        if (redacted !== original) {
          await writeFile(file, redacted);
          changed += 1;
        }
      }
    } catch (error) {
      console.warn(`  Not redacted (${(error as Error).message}): ${file}`);
    }
  }
  return changed;
}

/** The report page with the zip it embeds redacted; the page itself is returned as is otherwise. */
export function redactReportHtml(html: string, secrets: readonly string[]): string {
  const match = REPORT_DATA.exec(html);
  if (!match) {
    return html;
  }
  const result = redactZipDetailed(Buffer.from(match[2].replace(/\s/g, ''), 'base64'), secrets);
  if (result.status === 'unsupported') {
    console.warn('  Not redacted (unsupported archive): embedded report data in index.html');
    return html;
  }
  if (result.zip === null) {
    return html;
  }
  const encoded = result.zip.toString('base64');
  return (
    html.slice(0, match.index) +
    match[1] +
    encoded +
    match[3] +
    html.slice(match.index + match[0].length)
  );
}

/** The archive with credentials replaced, or null when nothing changed or it cannot be read. */
export function redactZip(zip: Buffer, secrets: readonly string[]): Buffer | null {
  return redactZipDetailed(zip, secrets).zip;
}

function redactZipDetailed(
  zip: Buffer,
  secrets: readonly string[]
): { status: 'changed' | 'unchanged' | 'unsupported'; zip: Buffer | null } {
  let entries: ZipEntry[] | null;
  try {
    entries = readZip(zip);
  } catch {
    entries = null;
  }
  if (entries === null) {
    return { status: 'unsupported', zip: null };
  }
  let touched = false;
  const rewritten = entries.map((entry) => {
    const data = redactEntry(entry, secrets);
    touched ||= data !== entry.data;
    return { name: entry.name, data };
  });
  return touched
    ? { status: 'changed', zip: writeZip(rewritten) }
    : { status: 'unchanged', zip: null };
}

function redactEntry(entry: ZipEntry, secrets: readonly string[]): Buffer {
  const name = entry.name.toString('utf8');
  const text = decodeText(entry.data, TEXT_EXTENSIONS.has(path.extname(name).toLowerCase()));
  if (text === null) {
    return entry.data;
  }
  const redacted = redactStructuredText(text.value, secrets);
  return redacted === text.value ? entry.data : Buffer.from(redacted, text.encoding);
}

/** The data as text, or null for a binary entry. Known text extensions fall back to latin1. */
function decodeText(
  data: Buffer,
  knownText: boolean
): { value: string; encoding: 'utf8' | 'latin1' } | null {
  if (data.includes(0)) {
    return null;
  }
  try {
    return { value: new TextDecoder('utf-8', { fatal: true }).decode(data), encoding: 'utf8' };
  } catch {
    return knownText ? { value: data.toString('latin1'), encoding: 'latin1' } : null;
  }
}

async function filesUnder(dir: string): Promise<string[]> {
  try {
    return (await readdir(dir, { recursive: true })).map((name) => path.join(dir, name));
  } catch {
    return [];
  }
}

/** Entries from the central directory; null for anything this reader does not handle (zip64). */
function readZip(zip: Buffer): ZipEntry[] | null {
  const eocd = zip.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  if (eocd < 0 || zip.readUInt32LE(eocd) !== EOCD_SIGNATURE) {
    return null;
  }
  const count = zip.readUInt16LE(eocd + 10);
  let offset = zip.readUInt32LE(eocd + 16);
  if (count === 0xffff || offset === 0xffffffff) {
    return null;
  }

  const entries: ZipEntry[] = [];
  for (let i = 0; i < count; i++) {
    if (zip.readUInt32LE(offset) !== CENTRAL_SIGNATURE) {
      return null;
    }
    const method = zip.readUInt16LE(offset + 10);
    const compressedSize = zip.readUInt32LE(offset + 20);
    const nameLength = zip.readUInt16LE(offset + 28);
    const extraLength = zip.readUInt16LE(offset + 30);
    const commentLength = zip.readUInt16LE(offset + 32);
    const localOffset = zip.readUInt32LE(offset + 42);
    const name = zip.subarray(offset + 46, offset + 46 + nameLength);
    offset += 46 + nameLength + extraLength + commentLength;

    if (zip.readUInt32LE(localOffset) !== LOCAL_SIGNATURE) {
      return null;
    }
    const dataStart =
      localOffset + 30 + zip.readUInt16LE(localOffset + 26) + zip.readUInt16LE(localOffset + 28);
    const raw = zip.subarray(dataStart, dataStart + compressedSize);
    if (method === STORED) {
      entries.push({ name, data: Buffer.from(raw) });
    } else if (method === DEFLATED) {
      entries.push({ name, data: inflateRawSync(raw) });
    } else {
      return null;
    }
  }
  return entries;
}

function writeZip(entries: readonly ZipEntry[]): Buffer {
  const locals: Buffer[] = [];
  const centrals: Buffer[] = [];
  let offset = 0;
  for (const entry of entries) {
    const compressed = deflateRawSync(entry.data);
    const crc = crc32(entry.data);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(LOCAL_SIGNATURE, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0x0800, 6); // names are UTF-8
    local.writeUInt16LE(DEFLATED, 8);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(compressed.length, 18);
    local.writeUInt32LE(entry.data.length, 22);
    local.writeUInt16LE(entry.name.length, 26);
    locals.push(local, entry.name, compressed);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(CENTRAL_SIGNATURE, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0x0800, 8);
    central.writeUInt16LE(DEFLATED, 10);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(compressed.length, 20);
    central.writeUInt32LE(entry.data.length, 24);
    central.writeUInt16LE(entry.name.length, 28);
    central.writeUInt32LE(offset, 42);
    centrals.push(central, entry.name);

    offset += local.length + entry.name.length + compressed.length;
  }

  const centralSize = centrals.reduce((sum, part) => sum + part.length, 0);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(EOCD_SIGNATURE, 0);
  eocd.writeUInt16LE(entries.length, 8);
  eocd.writeUInt16LE(entries.length, 10);
  eocd.writeUInt32LE(centralSize, 12);
  eocd.writeUInt32LE(offset, 16);
  return Buffer.concat([...locals, ...centrals, eocd]);
}
