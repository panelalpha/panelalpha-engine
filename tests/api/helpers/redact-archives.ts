import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { crc32, deflateRawSync, inflateRawSync } from 'node:zlib';

// A retained trace records `Authorization: Bearer <API_TOKEN>` verbatim and Playwright has
// no hook to leave a header out, so the archives are rewritten after the run instead.

export const REDACTED = '[REDACTED]';

const EOCD_SIGNATURE = 0x06054b50;
const CENTRAL_SIGNATURE = 0x02014b50;
const LOCAL_SIGNATURE = 0x04034b50;
const STORED = 0;
const DEFLATED = 8;

interface ZipEntry {
  name: Buffer;
  data: Buffer;
}

/** Secrets shorter than this are not worth a rewrite and could match by chance. */
const MIN_SECRET_LENGTH = 8;

export function usableSecrets(values: readonly (string | undefined)[]): string[] {
  return [
    ...new Set(values.map((v) => v?.trim() ?? '').filter((v) => v.length >= MIN_SECRET_LENGTH)),
  ];
}

/** Every `.zip` under `dir` with the secrets replaced; returns how many files changed. */
export async function redactArchivesIn(dir: string, secrets: readonly string[]): Promise<number> {
  if (secrets.length === 0) {
    return 0;
  }
  let changed = 0;
  for (const file of await zipFilesUnder(dir)) {
    const original = await readFile(file);
    const redacted = redactZip(original, secrets);
    if (redacted !== null) {
      await writeFile(file, redacted);
      changed += 1;
    }
  }
  return changed;
}

/** The archive with the secrets replaced, or null when none of them is in it. */
export function redactZip(zip: Buffer, secrets: readonly string[]): Buffer | null {
  const entries = readZip(zip);
  if (entries === null) {
    return null;
  }
  let touched = false;
  const rewritten = entries.map((entry) => {
    const data = redactBuffer(entry.data, secrets);
    touched ||= data !== entry.data;
    return { name: entry.name, data };
  });
  return touched ? writeZip(rewritten) : null;
}

function redactBuffer(data: Buffer, secrets: readonly string[]): Buffer {
  if (!secrets.some((secret) => data.includes(secret))) {
    return data;
  }
  let text = data.toString('latin1');
  for (const secret of secrets) {
    text = text.split(secret).join(REDACTED);
  }
  return Buffer.from(text, 'latin1');
}

async function zipFilesUnder(dir: string): Promise<string[]> {
  let names: string[];
  try {
    names = await readdir(dir, { recursive: true });
  } catch {
    return [];
  }
  return names.filter((name) => name.endsWith('.zip')).map((name) => path.join(dir, name));
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
