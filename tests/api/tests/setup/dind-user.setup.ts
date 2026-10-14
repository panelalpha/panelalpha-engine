import { expect, test } from '@/fixtures/test-options';
import {
  clearSetupDindState,
  readSetupDindState,
  refreshLease,
  runIdentity,
  writeLease,
  writeSetupDindState,
} from '@/fixtures/helper/setup-state';
import { expectStatus } from '@/helpers/expect-status';
import { recordMatchesUsername } from '@/helpers/test-user-cleanup';
import { configuredGitRepo, waitForDeploy } from '@/helpers/deploy-helpers';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { waitForSiteHttpReady } from '@/helpers/webserver-helpers';
import { Timeouts } from '@/config/timeouts';

/**
 * Provisions one shared DinD project (public git fixture) for the `deploy`
 * Playwright project. Reused across runs while the account still exists.
 */
test('provision shared DinD git-deployed user', async ({
  api,
  userFactory,
  anonymousRequest,
  settings,
}) => {
  test.setTimeout(Timeouts.deploy);
  const gitRepo = configuredGitRepo();

  const reused = await test.step('reuse DinD user from a previous run', async () => {
    const state = readSetupDindState();
    if (!state) {
      return undefined;
    }
    const raw = await api.getUserRaw(state.username);
    if (raw.status === 404) {
      clearSetupDindState();
      return undefined;
    }
    await expectStatus(raw, 200, `Cached DinD user "${state.username}" cannot be reused`);
    expect(
      recordMatchesUsername(raw.body, state.username),
      `GET projects/${state.username} answered 200 with a different record`
    ).toBe(true);
    writeLease([state.username]);
    writeSetupDindState({ ...state, ...runIdentity() });
    return state;
  });

  if (reused) {
    refreshLease();
    return;
  }

  const created = await userFactory.createDeployedUser({
    autoCleanup: false,
    git_repo: gitRepo,
  });
  skipUnless(created, 'Could not create a git-deployed DinD user on this engine.');

  test.skip(
    !(await isDindUser(api, created.username)),
    'Container management is not available for the created user.'
  );

  writeLease([created.username]);

  const log = await waitForDeploy(api, created.username, {
    timeout: settings.timing.deployTimeout,
  });
  expect(
    ['success', 'partial'],
    `Deploy of ${created.username} ended ${log.status} (stage ${log.stage ?? 'none'})`
  ).toContain(log.status);

  const url = `https://${created.domain}/`;
  await waitForSiteHttpReady(anonymousRequest, url, {
    timeout: Math.min(settings.timing.deployTimeout, 90_000),
  });

  writeSetupDindState({
    username: created.username,
    domain: created.domain,
    url,
    git_repo: gitRepo,
    createdAt: Date.now(),
    ...runIdentity(),
  });
  refreshLease();
});
