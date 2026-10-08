import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { expect, test } from '@/fixtures/test-options';
import {
  REDACTED,
  redactArchivesIn,
  redactReportHtml,
  redactZip,
  usableSecrets,
} from '@/helpers/redact-archives';
import { clearRegisteredSecretFiles, readRegisteredSecretFiles } from '@/helpers/secret-registry';

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
    expect(files['trace.trace']).toBe(`"message":"  Authorization: ${REDACTED}"\n`);
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

  test('redacts a password nobody put in the environment, read from the registry files', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'redact-'));
    try {
      const planted = 'Pl4ntedPass!word99';
      const registry = path.join(root, 'secrets');
      await mkdir(registry);
      await writeFile(
        path.join(registry, '4242.txt'),
        `${Buffer.from(planted).toString('base64')}\n`
      );
      const zip = await traceZip({
        'resources/abc.json': `{"data":{"note":"copy ${planted}"}}`,
      });

      const redacted = redactZip(zip, readRegisteredSecretFiles(registry));

      expect(redacted).not.toBeNull();
      expect((await entries(redacted!))['resources/abc.json']).toBe(
        `{"data":{"note":"copy ${REDACTED}"}}`
      );
      clearRegisteredSecretFiles(registry);
      expect(readRegisteredSecretFiles(registry)).toEqual([]);
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('applies the key and value patterns inside entries and keeps JSON valid', async () => {
    const body = JSON.stringify({
      data: { password: 'Sup3rS3cretValue', retries: 3, name: 'site' },
      log: 'DB_PASSWORD=hunter2hunter2 done',
      escaped: '{"token":"abcdefghij12345"}',
      url: 'ftp://user:swordfish99@host/x',
    });
    const trace = `${JSON.stringify({ type: 'log', password: 'Sup3rS3cretValue' })}\n${body}\n`;
    const zip = await traceZip({ 'resources/a.json': body, 'trace.trace': trace });

    const files = await entries(redactZip(zip, [])!);

    for (const text of [files['resources/a.json'], ...files['trace.trace'].trim().split('\n')]) {
      expect(text).not.toMatch(/Sup3rS3cretValue|hunter2hunter2|abcdefghij12345|swordfish99/);
      expect(() => JSON.parse(text) as unknown).not.toThrow();
    }
    const parsed = JSON.parse(files['resources/a.json']) as {
      data: { password: string; retries: number; name: string };
    };
    expect(parsed.data).toEqual({ password: REDACTED, retries: 3, name: 'site' });
  });

  test('keeps a number valid JSON when its key looks secret', async () => {
    const files = await entries(
      redactZip(await traceZip({ 'resources/n.json': '{"token":12345678901,"ok":true}' }), [])!
    );
    expect(() => JSON.parse(files['resources/n.json']) as unknown).not.toThrow();
    expect(files['resources/n.json']).not.toContain('12345678901');
  });

  test('leaves a binary entry untouched', async () => {
    const binary = Buffer.concat([
      Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x00, 0x00]),
      Buffer.from(`password=${TOKEN}`),
    ]);
    const { yazl } = createRequire(import.meta.url)('playwright-core/lib/utilsBundle') as {
      yazl: { ZipFile: new () => YazlZipFile };
    };
    const zip = new yazl.ZipFile();
    zip.addBuffer(binary, 'resources/shot.png');
    zip.addBuffer(Buffer.from(`{"password":"${TOKEN}"}`), 'resources/a.json');
    zip.end();
    const chunks: Buffer[] = [];
    for await (const chunk of zip.outputStream) {
      chunks.push(chunk as Buffer);
    }

    const redacted = redactZip(Buffer.concat(chunks), [])!;

    const files = await entries(redacted);
    expect(files['resources/shot.png']).toBe(binary.toString());
    expect(files['resources/a.json']).toBe(`{"password":"${REDACTED}"}`);
  });

  test('redacts the zip the HTML report embeds as base64', async () => {
    const zip = await traceZip({ 'report.json': `{"error":"password: ${TOKEN}"}` });
    const html =
      '<html><body><script>var password = 1;</script>' +
      `<template id="playwrightReportBase64">data:application/zip;base64,${zip.toString('base64')}</template>` +
      '</body></html>';

    const redacted = redactReportHtml(html, []);

    expect(redacted).not.toBe(html);
    expect(redacted).toContain('<script>var password = 1;</script>');
    const match = /base64,([A-Za-z0-9+/=]+)<\/template>/.exec(redacted);
    const files = await entries(Buffer.from(match![1], 'base64'));
    expect(files['report.json']).not.toContain(TOKEN);
    expect(redactReportHtml('<html></html>', [])).toBe('<html></html>');
  });

  test('rewrites loose text attachments and the report page on disk', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'redact-'));
    try {
      const note = path.join(root, 'a', 'error-context.md');
      const json = path.join(root, 'a', 'data.json');
      await mkdir(path.dirname(note), { recursive: true });
      await writeFile(note, `login with password: ${TOKEN}\n`);
      await writeFile(json, `{"api_key":"${TOKEN}"}`);
      const zip = await traceZip({ 'report.json': `{"x":"${TOKEN}"}` });
      const index = path.join(root, 'index.html');
      await writeFile(
        index,
        `<template id="playwrightReportBase64">data:application/zip;base64,${zip.toString('base64')}</template>`
      );

      expect(await redactArchivesIn(root, [TOKEN])).toBe(3);

      expect(await readFile(note, 'utf8')).not.toContain(TOKEN);
      expect(JSON.parse(await readFile(json, 'utf8'))).toEqual({ api_key: REDACTED });
      expect(await readFile(index, 'utf8')).not.toContain(zip.toString('base64'));
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });
});
