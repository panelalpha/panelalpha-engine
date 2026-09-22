import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectFileContentEquals, getDomainBasePath } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { McpSession } from '@/helpers/mcp-helpers';
import { fileExistsResponseSchema, fileStatSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

/**
 * The file manager the API actually exposes: write, read, stat, copy, move,
 * zip, unzip, upload, delete. There is no directory listing — that is still
 * missing — so this covers the operations that exist rather than the product
 * name on the board.
 */
test.describe('file manager API', () => {
  test('a file can be written, copied, moved, zipped, uploaded and deleted', async ({
    api,
    setupUser,
  }) => {
    const root = `${getDomainBasePath(setupUser.domain)}/${uniqueId('fm-')}`;
    const original = `${root}/original.txt`;
    const copied = `${root}/copied.txt`;
    const moved = `${root}/moved.txt`;
    const archive = `${root}/bundle.zip`;
    const extracted = `${root}/extracted`;
    const marker = `file-manager-${Date.now()}`;

    try {
      expect(
        validateParsedApiResponse(
          await api.fileExists(setupUser.username, original),
          fileExistsResponseSchema
        ).exists
      ).toBe(false);

      await api.createDirectory(setupUser.username, root, true);
      await api.putFileContents(setupUser.username, original, marker);

      expect((await api.fileExists(setupUser.username, original)).exists).toBe(true);
      expectFileContentEquals(await api.getFileContent(setupUser.username, original), marker);

      const stat = validateParsedApiResponse(
        await api.getFileStat(setupUser.username, original),
        fileStatSchema
      );
      expect(Number(stat.size), 'stat size should be the written payload').toBe(
        Buffer.byteLength(marker)
      );

      await api.copyFile(setupUser.username, original, copied);
      expectFileContentEquals(await api.getFileContent(setupUser.username, copied), marker);

      await api.moveFile(setupUser.username, copied, moved);
      expect((await api.fileExists(setupUser.username, copied)).exists).toBe(false);
      expectFileContentEquals(await api.getFileContent(setupUser.username, moved), marker);

      await api.zipFiles(setupUser.username, archive, original);
      expect((await api.fileExists(setupUser.username, archive)).exists).toBe(true);

      await api.createDirectory(setupUser.username, extracted, true);
      await api.unzipFile(setupUser.username, archive, extracted);
      expectFileContentEquals(
        await api.getFileContent(setupUser.username, `${extracted}/original.txt`),
        marker
      );

      const uploadedName = 'uploaded.txt';
      const uploadedBody = `uploaded-${marker}`;
      const upload = await api.uploadFileFromBuffer(
        setupUser.username,
        root,
        uploadedName,
        Buffer.from(uploadedBody)
      );
      expect(upload.status, 'multipart upload should succeed').toBeGreaterThanOrEqual(200);
      expect(upload.status).toBeLessThan(300);
      expectFileContentEquals(
        await api.getFileContent(setupUser.username, `${root}/${uploadedName}`),
        uploadedBody
      );
    } finally {
      await api.removeFile(setupUser.username, root, true).catch(() => undefined);
    }
  });

  test('a missing file is 404 on stat and exists=false', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const missing = `${getDomainBasePath(setupUser.domain)}/${uniqueId('missing-')}.txt`;

    expect(
      validateParsedApiResponse(
        await api.fileExists(setupUser.username, missing),
        fileExistsResponseSchema
      ).exists
    ).toBe(false);

    const stat = await authedRequest.get(
      `projects/${setupUser.username}/files/stat?path=${encodeURIComponent(missing)}`
    );
    expect(stat.status(), 'stat of a missing file should be 404').toBe(404);
  });

  test('a path that climbs out of the home directory is refused', async ({
    authedRequest,
    setupUser,
  }) => {
    const response = await authedRequest.put(`projects/${setupUser.username}/files/put-contents`, {
      data: { path: '../../etc/hostname', contents: 'should-not-write' },
    });

    expectOneOf(
      response.status(),
      [400, 403, 422],
      'the file API accepted a path that leaves the account home'
    );
  });

  test('file_write and file_stat work over MCP', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const created = await api.createMcpToken(uniqueId('mcp-files-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    const path = `${getDomainBasePath(setupUser.domain)}/${uniqueId('mcp-file-')}.txt`;
    const marker = `mcp-${Date.now()}`;

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const tools = new Set((await session.listTools()).map((tool) => tool.name));
      skipUnless(
        tools.has('file_write') && tools.has('file_stat'),
        'this engine does not expose the file MCP tools.'
      );

      await session.callOk('file_write', {
        name: setupUser.username,
        path,
        contents: marker,
      });
      await session.callOk('file_stat', { name: setupUser.username, path });
      expectFileContentEquals(await api.getFileContent(setupUser.username, path), marker);
    } finally {
      await api.removeFile(setupUser.username, path).catch(() => undefined);
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
