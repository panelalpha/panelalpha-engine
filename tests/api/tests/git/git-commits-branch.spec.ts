import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';

/** The commit list reads a branch the checkout never fetched, and refuses one the remote lacks. */
test.describe('git commits of a branch', () => {
  test('an unfetched branch is listed and a branch the remote lacks is refused', async ({
    api,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);

    const user = await userFactory.createSimpleUser();
    const connected = await api.gitConnectRaw(user.username, {
      repo_url: DEFAULT_DEPLOY_GIT_REPO,
      branch: 'main',
    });
    await expectStatus(connected, 200);
    expect((await api.gitStatus(user.username)).data.managed_by, 'a classic checkout').toBe(
      'site_git'
    );

    const commits = await api.gitCommitsRaw(user.username, { branch: 'test-branch', limit: 5 });
    await expectStatus(commits, 200);
    const listed = (commits.body as { data: { hash: string }[] }).data;
    expect(Array.isArray(listed), 'the commits are a list').toBe(true);
    expect(listed.length, 'the unfetched branch has commits').toBeGreaterThan(0);
    expect(listed[0].hash, 'a commit hash').toMatch(/^[0-9a-f]{40}$/);

    const missing = await api.gitCommitsRaw(user.username, { branch: 'no-such-branch-2411' });
    await expectStatus(missing, 422);
    const problems = (missing.body as { problems?: { field?: string; code?: string }[] }).problems;
    expect(problems?.[0]?.field, 'the field the refusal names').toBe('git');
    expect(problems?.[0]?.code, 'the code the refusal carries').toBe('git_branch_not_found');
    expect(JSON.stringify(missing.body), "never git's own words").not.toContain(
      'ambiguous argument'
    );
  });
});
