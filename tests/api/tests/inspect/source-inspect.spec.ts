import { expect, test } from '@/fixtures/test-options';
import { inspectReportResponseSchema } from '@/schemas';
import { Timeouts } from '@/config/timeouts';
import { configuredGitRepo, DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { inspectPlatform, inspectStrategy } from '@/helpers/inspect-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';

test.describe('source inspect', () => {
  test('inspecting without a token is refused', async ({ anonymousRequest }) => {
    const response = await anonymousRequest.post('source/inspect', {
      data: { source: 'github.com/octocat/Spoon-Knife' },
    });
    expect(response.status()).toBe(401);
  });

  test('a missing source is a 422', async ({ api }) => {
    const response = await api.inspectSourceRaw({});
    expect(response.status).toBe(422);
  });

  test('garbage and missing paths are 422', async ({ api }) => {
    expect((await api.inspectSourceRaw({ source: 'not a source' })).status).toBe(422);
    expect((await api.inspectSourceRaw({ source: '/no/such/directory' })).status).toBe(422);
  });

  test('a public git repository inspects without deploying', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);
    const report = await api.inspectSource({
      source: configuredGitRepo(),
    });
    validateParsedApiResponse({ data: report.data }, inspectReportResponseSchema);
    expect(report.data.source.type).toBe('git');
    expect(typeof report.data.application.strategy).toBe('string');
    expect(report.data.application.strategy.length).toBeGreaterThan(0);
    expect(typeof report.data.application.deployable).toBe('boolean');
    expect(Array.isArray(report.data.application.candidates)).toBe(true);
    const candidateIds = (report.data.application.candidates ?? []).map((row) => row.id);
    for (const id of candidateIds) {
      expect(id.length).toBeGreaterThan(0);
    }

    const pinnedId = candidateIds[0];
    if (pinnedId !== undefined) {
      const pinned = await api.inspectSource({
        source: configuredGitRepo(),
        recipe: pinnedId,
      });
      expect(pinned.data.application.strategy.length).toBeGreaterThan(0);
      expect(Array.isArray(pinned.data.application.candidates)).toBe(true);
    }
  });

  test('a malformed recipe id is 422', async ({ api }) => {
    const response = await api.inspectSourceRaw({
      source: configuredGitRepo(),
      recipe: 'Not A Recipe',
    });
    expect(response.status).toBe(422);
  });

  test('an unknown project is a 404 on both inspect paths', async ({ api }) => {
    expect((await api.inspectProjectRaw('nosuchprojectxx')).status).toBe(404);
    expect((await api.inspectProjectDeprecatedRaw('nosuchprojectxx')).status).toBe(404);
  });

  test('POST /source/inspect with a username matches GET /projects/{u}/inspect', async ({
    api,
    setupUser,
  }) => {
    const viaGet = await api.inspectProject(setupUser.username);
    const viaPost = await api.inspectSource({
      source: setupUser.username,
      type: 'project',
    });
    expect(viaPost.data.application.strategy).toBe(viaGet.data.application.strategy);
    expect(viaPost.data.source.type).toBe(viaGet.data.source.type);
    expect(viaPost.data.ports).toEqual(viaGet.data.ports);
  });

  test('the deprecated source-inspection alias still answers', async ({ api, setupUser }) => {
    const current = await api.inspectProjectRaw(setupUser.username);
    const deprecated = await api.inspectProjectDeprecatedRaw(setupUser.username);
    expect(current.status).toBe(200);
    expect(deprecated.status).toBe(200);
    const currentBody = current.body as { data?: { application?: { strategy?: string } } };
    const deprecatedBody = deprecated.body as { data?: { application?: { strategy?: string } } };
    expect(deprecatedBody.data?.application?.strategy).toBe(
      currentBody.data?.application?.strategy
    );
  });

  test('inspecting an empty DinD project is accepted', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');
    const response = await api.inspectProjectRaw(user.username);
    expect([200, 422]).toContain(response.status);
  });

  test('inspect reports env variable names and never their values', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const secret = `pa-secret-${uniqueId('x')}`;
    await api.putFileContents(
      user.username,
      `${getDomainBasePath(user.domain)}/.env`,
      `PA_TEST_SECRET=${secret}\n`
    );

    const report = await api.inspectProject(user.username);
    const encoded = JSON.stringify(report.data);
    expect(encoded).not.toContain(secret);
    const names = report.data.environment?.variables;
    if (Array.isArray(names)) {
      expect(names).toContain('PA_TEST_SECRET');
    }
  });
});

test.describe('source inspect of known instance kinds', () => {
  test('the default public git fixture inspects as static', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);
    test.skip(
      configuredGitRepo() !== DEFAULT_DEPLOY_GIT_REPO,
      'GIT_REPO is overridden; this assertion is for Spoon-Knife.'
    );
    const report = await api.inspectSource({ source: DEFAULT_DEPLOY_GIT_REPO, type: 'git' });
    expect(inspectPlatform(report)).toBe('static');
    expect(inspectStrategy(report)).toBe('static');
  });

  test('the shared classic WordPress account inspects as wordpress', async ({ api, setupUser }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');

    const relative = setupUser.wpPath.replace(new RegExp(`^/home/${setupUser.username}/`), '');
    skipUnless(
      relative && relative !== setupUser.wpPath,
      'WordPress path is not under the account home.'
    );

    const inspected = await api.inspectSourceRaw({
      source: setupUser.username,
      type: 'project',
      subdirectory: relative,
    });
    expect(inspected.status).toBe(200);
    expect(inspectPlatform(inspected.body)).toBe('wordpress');
    expect(inspectStrategy(inspected.body)).toBe('php');
  });

  test('WP-CLI reports the shared account as an installed WordPress', async ({
    api,
    setupUser,
  }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');

    const installed = await api.executeWpCliCommand(setupUser.username, [
      'core',
      'is-installed',
      wpPath(setupUser),
    ]);
    expect(installed.exit_code).toBe(0);
  });
});
