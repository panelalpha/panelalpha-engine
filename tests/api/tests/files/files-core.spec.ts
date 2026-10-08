import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectOneOf } from '@/helpers/expect-one-of';
import {
  expectFileContentEquals,
  getDomainBasePath,
  randomFileContent,
  removeFileIfPresent,
  randomFileName,
} from '@/helpers/file-path-helpers';
import { expectStatus } from '@/helpers/expect-status';

test.describe('file operations', () => {
  test(
    'the document root exists and can be stat-ed',
    { tag: ['@smoke'] },
    async ({ api, setupUser }) => {
      const basePath = getDomainBasePath(setupUser.domain);

      expect((await api.fileExists(setupUser.username, basePath)).exists).toBe(true);
      expect((await api.getFileStat(setupUser.username, basePath)).file_name).toBeTruthy();
    }
  );

  test('WordPress left an index.php in the document root', async ({ api, setupUser }) => {
    const indexPath = `${getDomainBasePath(setupUser.domain)}/index.php`;
    expect((await api.fileExists(setupUser.username, indexPath)).exists).toBe(true);
  });

  test('a file that was never created is reported missing', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/non_existent_${Date.now()}.txt`;
    expect((await api.fileExists(setupUser.username, path)).exists).toBe(false);
  });

  /** Write, read, copy, rename, delete — the whole path a file takes. */
  test(
    'a file can be written, copied, renamed and deleted',
    { tag: ['@smoke'] },
    async ({ api, setupUser }) => {
      const basePath = getDomainBasePath(setupUser.domain);
      const content = randomFileContent();
      const path = `${basePath}/${randomFileName('txt')}`;
      const copyPath = path.replace('.txt', '_copy.txt');
      const renamedPath = path.replace('.txt', '_renamed.txt');

      const cleanup = new CleanupSteps();
      try {
        await api.putFileContents(setupUser.username, path, content);
        expect((await api.fileExists(setupUser.username, path)).exists).toBe(true);
        expectFileContentEquals(await api.getFileContent(setupUser.username, path), content);

        await api.copyFile(setupUser.username, path, copyPath);
        expect((await api.fileExists(setupUser.username, copyPath)).exists).toBe(true);

        await api.moveFile(setupUser.username, path, renamedPath);
        expect((await api.fileExists(setupUser.username, path)).exists).toBe(false);
        expect((await api.fileExists(setupUser.username, renamedPath)).exists).toBe(true);

        await api.removeFile(setupUser.username, renamedPath);
        expect((await api.fileExists(setupUser.username, renamedPath)).exists).toBe(false);
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        for (const leftover of [path, copyPath, renamedPath]) {
          await cleanup.run(`remove ${leftover}`, () =>
            removeFileIfPresent(api, setupUser.username, leftover)
          );
        }
        await cleanup.finish();
      }
    }
  );

  test('put-contents overwrites an existing file', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/overwrite_${Date.now()}.txt`;

    const cleanup = new CleanupSteps();
    try {
      await api.putFileContents(setupUser.username, path, 'initial-content');

      const replacement = `overwritten-${Date.now()}`;
      await api.putFileContents(setupUser.username, path, replacement);

      expectFileContentEquals(await api.getFileContent(setupUser.username, path), replacement);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('stat reports the file name with its extension', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/mime_test_${Date.now()}.css`;

    const cleanup = new CleanupSteps();
    try {
      await api.putFileContents(setupUser.username, path, 'body { margin: 0; }');
      expect((await api.getFileStat(setupUser.username, path)).file_name).toMatch(/\.css$/);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a megabyte-sized file survives a write and read back', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/large_${Date.now()}.bin`;
    const content = 'A'.repeat(1024 * 1024);

    const cleanup = new CleanupSteps();
    try {
      await api.putFileContents(setupUser.username, path, content);

      expect(Number((await api.getFileStat(setupUser.username, path)).size)).toBeGreaterThanOrEqual(
        content.length
      );
      expect(await api.getFileContent(setupUser.username, path)).toHaveLength(content.length);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a dotfile such as .htaccess can be written', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/.htaccess`;

    const cleanup = new CleanupSteps();
    try {
      await api.putFileContents(setupUser.username, path, 'RewriteEngine On');
      expect((await api.fileExists(setupUser.username, path)).exists).toBe(true);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });
});

test.describe('directories', () => {
  test('a directory can be created and removed', async ({ api, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/testdir_${Date.now()}`;

    await api.createDirectory(setupUser.username, path);
    expect((await api.fileExists(setupUser.username, path)).exists).toBe(true);

    await api.removeFile(setupUser.username, path, true);
    expect((await api.fileExists(setupUser.username, path)).exists).toBe(false);
  });

  test('a directory is copied with everything inside it', async ({ api, setupUser }) => {
    const basePath = getDomainBasePath(setupUser.domain);
    const sourceDir = `${basePath}/copy_src_${Date.now()}`;
    const destinationDir = sourceDir.replace('copy_src_', 'copy_dest_');
    const nestedFile = `${sourceDir}/nested/file.txt`;

    const cleanup = new CleanupSteps();
    try {
      await api.createDirectory(setupUser.username, `${sourceDir}/nested`, true);
      await api.putFileContents(setupUser.username, nestedFile, 'nested content');

      await api.copyFile(setupUser.username, sourceDir, destinationDir);

      const copied = nestedFile.replace(sourceDir, destinationDir);
      expect((await api.fileExists(setupUser.username, copied)).exists).toBe(true);
      expectFileContentEquals(
        await api.getFileContent(setupUser.username, copied),
        'nested content'
      );
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      for (const dir of [destinationDir, sourceDir]) {
        await cleanup.run(`remove ${dir}`, () =>
          removeFileIfPresent(api, setupUser.username, dir, true)
        );
      }
      await cleanup.finish();
    }
  });
});

test.describe('archives', () => {
  test('a directory is zipped and unzipped back', async ({ api, setupUser }) => {
    const basePath = getDomainBasePath(setupUser.domain);
    const sourceDir = `${basePath}/zip_src_${Date.now()}`;
    const sourceFile = `${sourceDir}/sample.txt`;
    const zipPath = `${basePath}/archive_${Date.now()}.zip`;
    const destinationDir = `${basePath}/unzipped_${Date.now()}`;

    const cleanup = new CleanupSteps();
    try {
      await api.createDirectory(setupUser.username, sourceDir, true);
      await api.putFileContents(setupUser.username, sourceFile, 'zip source content');

      await api.zipFiles(setupUser.username, zipPath, sourceDir, true);
      expect((await api.fileExists(setupUser.username, zipPath)).exists).toBe(true);

      await api.unzipFile(setupUser.username, zipPath, destinationDir);

      // Whether the archive keeps the source directory as a top-level entry
      // depends on skip_parents, so both layouts count as extracted.
      const baseName = sourceFile.split('/').pop();
      const candidates = [
        `${destinationDir}/${baseName}`,
        `${destinationDir}/${sourceDir.split('/').pop()}/${baseName}`,
      ];

      const found = await Promise.all(
        candidates.map(async (candidate) =>
          (await api.fileExists(setupUser.username, candidate)).exists ? candidate : undefined
        )
      );

      expect(
        found.find(Boolean),
        `the archive extracted to none of ${candidates.join(' or ')}`
      ).toBeTruthy();
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      for (const path of [destinationDir, zipPath, sourceDir]) {
        await cleanup.run(`remove ${path}`, () =>
          removeFileIfPresent(api, setupUser.username, path, true)
        );
      }
      await cleanup.finish();
    }
  });
});

test.describe('HTTP access to files', () => {
  test('a file written through the API is served over HTTP', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const fileName = randomFileName('txt');
    const content = randomFileContent();
    const path = `${getDomainBasePath(setupUser.domain)}/${fileName}`;

    const cleanup = new CleanupSteps();
    try {
      await api.putFileContents(setupUser.username, path, content);

      const response = await anonymousRequest.get(`https://${setupUser.domain}/${fileName}`, {
        ignoreHTTPSErrors: true,
      });

      await expectStatus(response, 200);
      expect((await response.text()).trim()).toBe(content);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('a file that does not exist is not served', async ({ anonymousRequest, setupUser }) => {
    const response = await anonymousRequest.get(
      `https://${setupUser.domain}/missing_${Date.now()}.txt`,
      { ignoreHTTPSErrors: true }
    );
    const body = await response.text();

    const refused =
      response.status() === 404 ||
      response.status() === 403 ||
      (response.status() === 200 &&
        /not found|404|forbidden|access denied|misdirected request/i.test(body));

    expect(refused, `an unknown path answered ${response.status()} with a real body`).toBe(true);
  });
});

test.describe('download endpoint', () => {
  test('a Range request returns just that slice', async ({ authedRequest, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/range_test_${Date.now()}.txt`;
    const endpoint = `projects/${setupUser.username}/files/download?path=${encodeURIComponent(path)}`;

    try {
      await authedRequest.put(`projects/${setupUser.username}/files/put-contents`, {
        data: { path, contents: 'Hello World' },
      });

      const full = await authedRequest.get(endpoint);
      await expectStatus(full, 200);
      expect(await full.text()).toBe('Hello World');

      const ranged = await authedRequest.get(endpoint, { headers: { Range: 'bytes=0-4' } });
      await expectStatus(ranged, 206);
      expect(ranged.headers()['content-range']).toMatch(/^bytes\s+0-4\//);
      expect(await ranged.text()).toBe('Hello');
    } finally {
      await authedRequest.delete(
        `projects/${setupUser.username}/files/remove?path=${encodeURIComponent(path)}`
      );
    }
  });

  test('downloading a missing file returns 404', async ({ authedRequest, setupUser }) => {
    const path = `${getDomainBasePath(setupUser.domain)}/nonexistent_${Date.now()}.txt`;
    const response = await authedRequest.get(
      `projects/${setupUser.username}/files/download?path=${encodeURIComponent(path)}`
    );
    await expectStatus(response, 404);
  });
});

test.describe('multipart uploads', () => {
  const uploads = [
    {
      label: 'a text file',
      name: () => `multipart_upload_${Date.now()}.txt`,
      body: () => Buffer.from(`Multipart upload test content - ${Date.now()}`, 'utf-8'),
      mimeType: 'text/plain',
    },
    {
      label: 'a binary file',
      name: () => `binary_upload_${Date.now()}.bin`,
      body: () => Buffer.from([0x00, 0x01, 0x02, 0xff, 0xfe, 0xfd, 0x48, 0x65, 0x6c, 0x6c, 0x6f]),
      mimeType: 'application/octet-stream',
    },
    {
      label: 'a name containing a dash and an underscore',
      name: () => `upload_test-file_${Date.now()}.txt`,
      body: () => Buffer.from('Content with safe filename', 'utf-8'),
      mimeType: 'text/plain',
    },
    {
      label: 'a five megabyte file',
      name: () => `large_multipart_${Date.now()}.bin`,
      body: () => Buffer.alloc(5 * 1024 * 1024, 'X'),
      mimeType: 'application/octet-stream',
    },
  ] as const;

  for (const upload of uploads) {
    test(`${upload.label} uploads with its bytes intact`, async ({ api, setupUser }) => {
      const basePath = getDomainBasePath(setupUser.domain);
      const fileName = upload.name();
      const body = upload.body();
      const path = `${basePath}/${fileName}`;

      const cleanup = new CleanupSteps();
      try {
        const result = await api.uploadFileFromBuffer(
          setupUser.username,
          basePath,
          fileName,
          body,
          upload.mimeType
        );
        expectOneOf(result.status, [200, 201]);

        expect((await api.fileExists(setupUser.username, path)).exists).toBe(true);
        expect(Number((await api.getFileStat(setupUser.username, path)).size)).toBe(body.length);
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run(`remove ${path}`, () =>
          removeFileIfPresent(api, setupUser.username, path)
        );
        await cleanup.finish();
      }
    });
  }

  test('an empty file either uploads as zero bytes or is refused', async ({ api, setupUser }) => {
    const basePath = getDomainBasePath(setupUser.domain);
    const fileName = `empty_${Date.now()}.txt`;
    const path = `${basePath}/${fileName}`;

    const result = await api.uploadFileFromBuffer(
      setupUser.username,
      basePath,
      fileName,
      Buffer.alloc(0),
      'text/plain'
    );
    // UploadRequest only requires `file` to be present (File::default() has no
    // minimum size) and FileManager::moveUploadedFile copies it with cat, so an
    // empty upload is stored as a zero-byte file; the controller answers 200.
    const cleanup = new CleanupSteps();
    try {
      await expectStatus(result, 200);
      expect(Number((await api.getFileStat(setupUser.username, path)).size)).toBe(0);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${path}`, () => removeFileIfPresent(api, setupUser.username, path));
      await cleanup.finish();
    }
  });

  test('an upload into a directory that does not exist is handled', async ({ api, setupUser }) => {
    const directory = `${getDomainBasePath(setupUser.domain)}/non_existent_${Date.now()}`;
    const fileName = `test_${Date.now()}.txt`;

    const result = await api.uploadFileFromBuffer(
      setupUser.username,
      directory,
      fileName,
      Buffer.from('test content', 'utf-8')
    );
    // FileManager::writeAsUser runs `mkdir -p` on the target's parent before
    // writing, so FileController::upload creates the missing directory and
    // answers 200. A refusal or a 5xx is a regression.
    const cleanup = new CleanupSteps();
    try {
      await expectStatus(result, 200);
      expect((await api.fileExists(setupUser.username, `${directory}/${fileName}`)).exists).toBe(
        true
      );
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(`remove ${directory}`, () =>
        removeFileIfPresent(api, setupUser.username, directory, true)
      );
      await cleanup.finish();
    }
  });
});
