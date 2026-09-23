import { expect, test } from '@/fixtures/test-options';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { domainPhpDirectivesResponseSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

const DIRECTIVES = { memory_limit: '137M', upload_max_filesize: '64M' };

test.describe('domain PHP directives', () => {
  test('directives replace the document-root file and an unknown domain is 404', async ({
    api,
    authedRequest,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const iniPath = `${getDomainBasePath(user.domain)}/.user.ini`;

    const before = validateParsedApiResponse(
      await api.getDomainPhpDirectives(user.domain),
      domainPhpDirectivesResponseSchema
    );
    expect(before.data).toEqual({});
    expect((await api.fileExists(user.username, iniPath)).exists).toBe(false);

    try {
      await api.setDomainPhpDirectives(user.domain, DIRECTIVES);

      const after = validateParsedApiResponse(
        await api.getDomainPhpDirectives(user.domain),
        domainPhpDirectivesResponseSchema
      );
      expect(after.data).toEqual(DIRECTIVES);
      expect(await api.getFileContent(user.username, iniPath)).toBe(
        'memory_limit=137M\nupload_max_filesize=64M\n'
      );

      const invalid = await api.setDomainPhpDirectivesRaw(user.domain, {
        memory_limit: '256M; dropped',
      });
      expect(invalid.status).toBe(422);
      expect(await api.getFileContent(user.username, iniPath)).toBe(
        'memory_limit=137M\nupload_max_filesize=64M\n'
      );

      const missing = await authedRequest.put(`domains/${user.domain}/php-directives`, {
        data: {},
      });
      expect(missing.status()).toBe(422);

      await api.setDomainPhpDirectives(user.domain, {});
      const cleared = validateParsedApiResponse(
        await api.getDomainPhpDirectives(user.domain),
        domainPhpDirectivesResponseSchema
      );
      expect(cleared.data).toEqual({});
      expect((await api.fileExists(user.username, iniPath)).exists).toBe(false);
    } finally {
      await api.setDomainPhpDirectives(user.domain, before.data).catch(() => undefined);
    }

    const unknown = `missing-${Date.now()}.example`;
    expect((await api.getDomainPhpDirectivesRaw(unknown)).status).toBe(404);
    expect((await api.setDomainPhpDirectivesRaw(unknown, DIRECTIVES)).status).toBe(404);
  });
});
