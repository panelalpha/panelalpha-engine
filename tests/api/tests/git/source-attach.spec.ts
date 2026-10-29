import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { assertDeploymentWarnings, requireServing } from '@/helpers/app-health';
import { DEFAULT_DEPLOY_GIT_REPO, waitForDeploy } from '@/helpers/deploy-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { taskIdFromBody } from '@/helpers/task-helpers';
import { testNetwork } from '@/helpers/test-network';

/**
 * The code the shared create/attach repository check reports for a refused
 * address. The egress guard renames it, so both are accepted rather than
 * pinning this spec to one engine's normalisation.
 */

/**
 * POST /projects/{username}/source gives a project created before its source
 * was known the repository it deploys from. The onboarding domain step creates
 * a dind account with no git_repo, and this is the git step that attaches one:
 * the account is not recreated, only its source and its deploy.
 */
test.describe('attach a git source to an existing project', () => {
  test(
    'a dind project created without a source deploys the attached repository',
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const user = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(user, 'DinD is not available on this engine.');
      const name = user.username;

      const task = await test.step('attach the repository', async () => {
        const attached = await api.attachSourceRaw(name, {
          git_repo: DEFAULT_DEPLOY_GIT_REPO,
          git_branch: 'main',
        });
        await expectStatus(attached, 202, JSON.stringify(attached.body));
        const details = (attached.body as { data: { details: Record<string, unknown> } }).data
          .details;
        expect(details.action, 'the task action').toBe('attach_source');
        expect(details.git_repo, 'the task names the repository').toBe(DEFAULT_DEPLOY_GIT_REPO);
        expect(JSON.stringify(details), 'the token never reaches the task').not.toContain(
          'git_token'
        );

        return api.followDeployTask(attached.body, 'POST /projects/{username}/source');
      });
      expect(task.status, 'the attach task completed').toBe('completed');

      await test.step('the source is stored on the project', async () => {
        const shown = await api.getUser(name);
        expect(shown.data.details.git_repo, 'git_repo').toBe(DEFAULT_DEPLOY_GIT_REPO);
        expect(shown.data.details.git_branch, 'git_branch').toBe('main');
        expect(shown.data.details.template, 'the project is still dind').toBe('dind');
      });

      await test.step('the deploy finished and the app serves', async () => {
        const deploy = await waitForDeploy(api, name);
        expect(['success', 'partial'], `deploy ended ${deploy.status}`).toContain(deploy.status);
        const shown = await api.getUser(name);
        assertDeploymentWarnings(
          shown.data.details.deployment_status ?? deploy.status,
          shown.data.details.deployment_warnings
        );

        const health = await api.getAppHealthRaw(name);
        await expectStatus(health, 200, JSON.stringify(health.body));
        expect(requireServing(health.body).serving, 'the app answered').toBe('ok');
      });
    }
  );

  test(
    'a project that already has a source refuses a second one',
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const user = await userFactory.createDeployedUser({
        git_repo: DEFAULT_DEPLOY_GIT_REPO,
        git_branch: 'main',
        tunnel: 'none',
      });
      skipUnless(user, 'DinD is not available on this engine.');

      const refused = await api.attachSourceRaw(user.username, {
        git_repo: DEFAULT_DEPLOY_GIT_REPO,
      });

      await expectStatus(refused, 409);
      expect(JSON.stringify(refused.body), 'the refusal names the problem').toContain(
        'source_already_attached'
      );
    }
  );

  test('a classic project cannot take a source', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const refused = await api.attachSourceRaw(user.username, {
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
    });

    await expectStatus(refused, 409);
    expect(JSON.stringify(refused.body), 'the refusal names the problem').toContain(
      'project_not_eligible'
    );
  });

  test(
    'a second attach while the deploy runs names the running task',
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const user = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(user, 'DinD is not available on this engine.');

      const first = await api.attachSourceRaw(user.username, {
        git_repo: DEFAULT_DEPLOY_GIT_REPO,
        git_branch: 'main',
      });
      await expectStatus(first, 202, JSON.stringify(first.body));
      const taskId = taskIdFromBody(first.body);

      const again = await api.attachSourceRaw(user.username, { git_repo: DEFAULT_DEPLOY_GIT_REPO });
      await expectStatus(again, 409);
      expect((again.body as { task_id?: unknown }).task_id, 'the refusal names the task').toBe(
        taskId
      );

      await api.followDeployTask(first.body, 'POST /projects/{username}/source');
    }
  );

  test(
    'a missing or refused repository is a 422',
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      const user = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(user, 'DinD is not available on this engine.');

      const missing = await api.attachSourceRaw(user.username, {});
      await expectStatus(missing, 422);
      expect(JSON.stringify(missing.body), 'git_repo is required').toContain('git_repo_required');

      const privateAddress = await api.attachSourceRaw(user.username, {
        git_repo: `http://${testNetwork.PRIVATE_10}:18660/r7.git`,
      });
      await expectStatus(privateAddress, 422);
      expect(JSON.stringify(privateAddress.body), 'the refused address is named').toContain(
        'host_refused'
      );
    }
  );
});
