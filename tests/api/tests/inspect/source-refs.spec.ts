import { expect, test } from '@/fixtures/test-options';
import { sourceRefsResponseSchema } from '@/schemas';
import { Timeouts } from '@/config/timeouts';
import { configuredGitRepo } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

test.describe('source refs', () => {
  test('listing a remote without a token is refused', async ({ anonymousRequest }) => {
    const response = await anonymousRequest.post('source/refs', {
      data: { repository: 'https://github.com/octocat/Spoon-Knife.git' },
    });
    await expectStatus(response, 401);
  });

  test('a missing repository is a 422', async ({ api }) => {
    await expectStatus(await api.refsSourceRaw({}), 422);
  });

  test('a bare owner/repo is a 422', async ({ api }) => {
    await expectStatus(await api.refsSourceRaw({ repository: 'octocat/Spoon-Knife' }), 422);
  });

  test('a limit outside the range is a 422', async ({ api }) => {
    await expectStatus(await api.refsSourceRaw({ repository: configuredGitRepo(), limit: 0 }), 422);
    await expectStatus(
      await api.refsSourceRaw({ repository: configuredGitRepo(), limit: 1001 }),
      422
    );
  });

  test('a repository on an internal address is refused', async ({ api }) => {
    for (const repository of [
      'http://127.0.0.1/x.git',
      'http://169.254.169.254/x.git',
      'http://[::1]/x.git',
      'http://10.0.0.5/x.git',
    ]) {
      const response = await api.refsSourceRaw({ repository });
      await expectStatus(response, 422);
      const body = response.body as { problems?: { field?: string; code?: string }[] };
      expect(body.problems?.[0]?.field, repository).toBe('repository');
      expect(body.problems?.[0]?.code, repository).toBe('host_refused');
    }
  });

  test('a public repository lists its branches and tags', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);
    const response = await api.refsSource({ repository: configuredGitRepo() });
    validateParsedApiResponse(response, sourceRefsResponseSchema);

    const { data } = response;
    expect(data.repository).toContain('github.com');
    expect(Array.isArray(data.branches)).toBe(true);
    expect(Array.isArray(data.tags)).toBe(true);
    if (data.default_branch !== null) {
      expect(data.branches[0]).toBe(data.default_branch);
      expect(data.branches).toContain(data.default_branch);
    }
  });

  test('limit=1 returns one branch and flags the rest', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);
    const response = await api.refsSource({ repository: configuredGitRepo(), limit: 1 });
    validateParsedApiResponse(response, sourceRefsResponseSchema);

    const { data } = response;
    expect(data.branches.length).toBeLessThanOrEqual(1);
    expect(data.tags.length).toBeLessThanOrEqual(1);
    expect(typeof data.truncated).toBe('boolean');
  });

  /**
   * A forge hides a repository it will not show anonymously, so "does not
   * exist" and "is private" are one answer until a token is sent.
   */
  test('a repository that is not there is a 422', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);
    const response = await api.refsSourceRaw({
      repository: 'https://github.com/octocat/definitely-nonexistent-xyz.git',
    });
    await expectStatus(response, 422);
    const body = response.body as { problems?: { code?: string }[] };
    expect(['repository_not_found', 'authentication_failed']).toContain(body.problems?.[0]?.code);
  });
});
