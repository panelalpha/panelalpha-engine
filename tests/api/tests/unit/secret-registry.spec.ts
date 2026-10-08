import { chmod, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { expect, test } from '@/fixtures/test-options';
import { REDACTED } from '@/helpers/redact-archives';
import { redactText } from '@/helpers/redact';
import {
  SECRET_REGISTRY_DIR,
  clearRegisteredSecretFiles,
  readRegisteredSecretFiles,
  registerSecret,
  registeredSecrets,
} from '@/helpers/secret-registry';

test.describe('secret-registry', () => {
  test('keeps a registered value in memory and redactText uses it', () => {
    const secret = 'Reg1steredMemoryOnly#1';
    registerSecret(secret);

    expect(registeredSecrets()).toContain(secret);
    expect(redactText(`got ${secret} back`)).toBe(`got ${REDACTED} back`);
    expect(redactText(`got ${encodeURIComponent(secret)} back`)).toBe(`got ${REDACTED} back`);
  });

  test('appends the value to this process file and reads it back', async () => {
    const secret = 'Reg1steredToFile\nwith "quotes"';
    registerSecret(secret);
    registerSecret(secret);

    const file = path.join(SECRET_REGISTRY_DIR, `${process.pid}.txt`);
    try {
      const lines = (await readFile(file, 'utf8')).trim().split('\n');
      expect(
        lines.filter((line) => Buffer.from(line, 'base64').toString() === secret)
      ).toHaveLength(1);
      expect(readRegisteredSecretFiles()).toContain(secret);
      expect((await import('node:fs/promises').then((fs) => fs.stat(file))).mode & 0o777).toBe(
        0o600
      );
    } finally {
      await rm(file, { force: true });
    }
  });

  test('ignores short, empty and missing values', () => {
    registerSecret('short');
    registerSecret('');
    registerSecret(undefined);
    registerSecret(null);

    expect(registeredSecrets()).not.toContain('short');
    expect(registeredSecrets()).not.toContain('');
  });

  test('reads every file in a directory, longest value first, and clears it', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'registry-'));
    try {
      const dir = path.join(root, 'secrets');
      await mkdir(dir);
      const b64 = (value: string) => `${Buffer.from(value).toString('base64')}\n`;
      await writeFile(path.join(dir, '1.txt'), b64('abcdefghij') + b64('tiny'));
      await writeFile(path.join(dir, '2.txt'), b64('abcdefghijklmnop'));

      expect(readRegisteredSecretFiles(dir)).toEqual(['abcdefghijklmnop', 'abcdefghij']);
      clearRegisteredSecretFiles(dir);
      expect(readRegisteredSecretFiles(dir)).toEqual([]);
      expect(() => clearRegisteredSecretFiles(dir)).not.toThrow();
      expect(readRegisteredSecretFiles(path.join(root, 'missing'))).toEqual([]);
    } finally {
      await rm(root, { recursive: true, force: true });
    }
  });

  test('does not throw when the directory cannot be written', async () => {
    const root = await mkdtemp(path.join(tmpdir(), 'registry-'));
    try {
      await chmod(root, 0o500);
      expect(() => readRegisteredSecretFiles(path.join(root, 'nope'))).not.toThrow();
      expect(() => registerSecret('Unwr1tableDirSecret')).not.toThrow();
    } finally {
      await chmod(root, 0o700);
      await rm(root, { recursive: true, force: true });
    }
  });
});
