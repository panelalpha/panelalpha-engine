import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { expect, test } from '@/fixtures/test-options';
import { REDACTED, redactArchivesIn, redactZip, usableSecrets } from '@/helpers/redact-archives';

const TOKEN = 'vaLEAKMARKER0123456789';

interface YazlZipFile {
  outputStream: NodeJS.ReadableStream;
  addBuffer(data: Buffer, name: string, options?: { compress?: boolean }): void;
  end(): void;
}

/** A zip written the way Playwright writes traces: yazl, with data descriptors. */
async function traceZip(files: Record<string, string>, compress = true): Promise<Buffer> {
  const { yazl } = createRequire(import.meta.url)('playwright-core/lib/utilsBundle') as {
    yazl: { ZipFile: new () => YazlZipFile };
  };
  const zip = new yazl.ZipFile();
  for (const [name, contents] of Object.entries(files)) {
    zip.addBuffer(Buffer.from(contents), name, { compress });
  }
  zip.end();
  const chunks: Buffer[] = [];
  for await (const chunk of zip.outputStream) {
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks);
}

/** Every entry of a zip, read back through yauzl so the rewrite is checked by another reader. */
async function entries(zip: Buffer): Promise<Record<string, string>> {
  const { yauzl } = createRequire(import.meta.url)('playwright-core/lib/utilsBundle') as {
    yauzl: {
      fromBuffer(
        buffer: Buffer,
        options: { lazyEntries: boolean },
        callback: (error: Error | null, zipfile: YauzlZipFile) => void
      ): void;
    };
  };
  return new Promise((resolve, reject) => {
    yauzl.fromBuffer(zip, { lazyEntries: true }, (error, zipfile) => {
      if (error) {
        reject(error);
        return;
      }
      const out: Record<string, string> = {};
      zipfile.on('entry', (entry: { fileName: string }) => {
        zipfile.openReadStream(entry, (streamError, stream) => {
          if (streamError) {
            reject(streamError);
            return;
          }
          const chunks: Buffer[] = [];
          stream.on('data', (chunk: Buffer) => chunks.push(chunk));
          stream.on('end', () => {
            out[entry.fileName] = Buffer.concat(chunks).toString();
            zipfile.readEntry();
          });
        });
      });
      zipfile.on('end', () => resolve(out));
      zipfile.readEntry();
    });
  });
}

interface YauzlZipFile {
  on(event: string, listener: (...args: never[]) => void): void;
  readEntry(): void;
  openReadStream(
    entry: unknown,
    callback: (error: Error | null, stream: NodeJS.ReadableStream) => void
  ): void;
}

const NETWORK =
  `{"type":"resource-snapshot","snapshot":{"request":{"headers":[` +
  `{"name":"Authorization","value":"Bearer ${TOKEN}"}]}}}\n`;

test.describe('redact-archives', () => {
  test('replaces the token in every entry and leaves the rest as it was', async () => {
    const original = await traceZip({
      'trace.network': NETWORK,
      'trace.trace': `"message":"  Authorization: Bearer ${TOKEN}"\n`,
      'resources/abc.json': '{"ok":true}',
    });

    const redacted = redactZip(original, [TOKEN]);

    expect(redacted).not.toBeNull();
    expect(redacted?.includes(TOKEN)).toBe(false);
    const files = await entries(redacted!);
    expect(files['trace.network']).toContain(`"Bearer ${REDACTED}"`);
    expect(files['trace.trace']).toBe(`"message":"  Authorization: Bearer ${REDACTED}"\n`);
    expect(files['resources/abc.json']).toBe('{"ok":true}');
  });

  test('reads stored entries too, and leaves an archive without the token alone', async () => {
    const stored = await traceZip({ 'trace.network': NETWORK }, false);
    expect((await entries(redactZip(stored, [TOKEN])!))['trace.network']).not.toContain(TOKEN);
    expect(redactZip(await traceZip({ 'trace.trace': 'nothing secret' }), [TOKEN])).toBeNull();
  });

  test('rewrites the traces and the report copies under each directory', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'redact-'));
    try {
      const trace = path.join(root, 'test-results', 'vault-a', 'trace.zip');
      const copy = path.join(root, 'report', 'data', '0123abcd.zip');
      await mkdir(path.dirname(trace), { recursive: true });
      await mkdir(path.dirname(copy), { recursive: true });
      const zip = await traceZip({ 'trace.network': NETWORK });
      await writeFile(trace, zip);
      await writeFile(copy, zip);

      const changed =
        (await redactArchivesIn(path.join(root, 'test-results'), [TOKEN])) +
        (await redactArchivesIn(path.join(root, 'report'), [TOKEN]));

      expect(changed).toBe(2);
      expect((await readFile(trace)).includes(TOKEN)).toBe(false);
      expect((await readFile(copy)).includes(TOKEN)).toBe(false);
      expect(await redactArchivesIn(path.join(root, 'missing'), [TOKEN])).toBe(0);
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('ignores unset and too-short secrets', () => {
    expect(usableSecrets([undefined, '', 'short', ` ${TOKEN} `, TOKEN])).toEqual([TOKEN]);
  });
});
