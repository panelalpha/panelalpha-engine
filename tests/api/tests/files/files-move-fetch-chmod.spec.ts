import { expect, test } from '@/fixtures/test-options';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';

test.describe('move contents, fetch and chmod', () => {
  test('move-contents moves children and leaves the source directory', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const root = `${getDomainBasePath(user.domain)}/${uniqueId('mv-')}`;
    const source = `${root}/source`;
    const dest = `${root}/dest`;

    try {
      await api.createDirectory(user.username, source, true);
      await api.createDirectory(user.username, dest, true);
      await api.putFileContents(user.username, `${source}/a.txt`, 'new');
      await api.putFileContents(user.username, `${source}/b.txt`, 'b');
      await api.putFileContents(user.username, `${dest}/a.txt`, 'old');

      const kept = await api.moveDirectoryContentsRaw(user.username, {
        source_path: source,
        dest_path: dest,
        override: false,
      });
      expect(kept.status).toBe(200);
      expect(await api.getFileContent(user.username, `${dest}/a.txt`)).toBe('old');
      expect(await api.getFileContent(user.username, `${source}/a.txt`)).toBe('new');
      expect(await api.getFileContent(user.username, `${dest}/b.txt`)).toBe('b');

      await api.moveDirectoryContents(user.username, source, dest, true);
      expect(await api.getFileContent(user.username, `${dest}/a.txt`)).toBe('new');
      expect((await api.fileExists(user.username, `${source}/a.txt`)).exists).toBe(false);
      expect((await api.fileExists(user.username, source)).exists).toBe(true);

      const missing = await api.moveDirectoryContentsRaw(user.username, {
        source_path: source,
        dest_path: `${root}/missing`,
      });
      expect(missing.status).toBe(400);
    } finally {
      await api.removeFile(user.username, root, true).catch(() => undefined);
    }
  });

  test('chmod accepts an octal mode and fetch refuses a non-http URL', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const path = `${getDomainBasePath(user.domain)}/${uniqueId('mode')}.sh`;

    try {
      await api.putFileContents(user.username, path, '#!/bin/sh\n');
      await api.chmodFile(user.username, path, '755');
      expect(await api.getFileContent(user.username, path)).toContain('#!/bin/sh');

      const badMode = await api.chmodFileRaw(user.username, { path, mode: '999' });
      expect(badMode.status).toBe(422);

      const fetched = await api.fetchFileRaw(user.username, {
        url: 'file:///etc/passwd',
        path: getDomainBasePath(user.domain),
        filename: 'stolen.txt',
      });
      expect(fetched.status).toBe(422);
      expect(
        (await api.fileExists(user.username, `${getDomainBasePath(user.domain)}/stolen.txt`)).exists
      ).toBe(false);
    } finally {
      await api.removeFile(user.username, path).catch(() => undefined);
    }
  });
});
