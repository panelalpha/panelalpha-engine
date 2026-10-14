import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { domainPhpDirectivesResponseSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { expectStatus } from '@/helpers/expect-status';

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

    const cleanup = new CleanupSteps();
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
      await expectStatus(invalid, 422);
      expect(await api.getFileContent(user.username, iniPath)).toBe(
        'memory_limit=137M\nupload_max_filesize=64M\n'
      );

      const missing = await authedRequest.put(`domains/${user.domain}/php-directives`, {
        data: {},
      });
      await expectStatus(missing, 422);

      await api.setDomainPhpDirectives(user.domain, {});
      const cleared = validateParsedApiResponse(
        await api.getDomainPhpDirectives(user.domain),
        domainPhpDirectivesResponseSchema
      );
      expect(cleared.data).toEqual({});
      expect((await api.fileExists(user.username, iniPath)).exists).toBe(false);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run(
        'restore the domain PHP directives',
        () => api.setDomainPhpDirectives(user.domain, before.data),
        {
          verify: async () => {
            const current = (await api.getDomainPhpDirectives(user.domain)).data;
            return Object.keys({ ...before.data, ...current }).every(
              (key) => before.data[key] === current[key]
            );
          },
          describe: () => 'the PHP directives differ from the ones found before the test',
        }
      );
      await cleanup.finish();
    }

    const unknown = `missing-${Date.now()}.example`;
    await expectStatus(await api.getDomainPhpDirectivesRaw(unknown), 404);
    await expectStatus(await api.setDomainPhpDirectivesRaw(unknown, DIRECTIVES), 404);
  });
});
