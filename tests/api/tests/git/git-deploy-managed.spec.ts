import { expect, test } from '@/fixtures/test-options';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { taskIdFromBody } from '@/helpers/task-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { Timeouts } from '@/config/timeouts';

/**
 * On a checkout the project was deployed from (`managed_by: deploy`), a pull,
 * a branch change and a revert are checked at once, then run with the
 * rebuild after them as a task: 202, and 409 naming that task while it runs.
 * Spoon-Knife has `main` and `test-branch`.
 */
test.describe('git on a deploy-managed checkout', () => {
  test(
    'pull, change-branch and revert answer 202 with a task and refuse a second call',
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 4);
      const user = await userFactory.createDeployedUser({
        git_repo: DEFAULT_DEPLOY_GIT_REPO,
        git_branch: 'main',
        tunnel: 'none',
      });
      skipUnless(user, 'DinD is not available on this engine.');
      const name = user.username;

      // Refused before anything is queued.
      await test.step('refuse an unknown branch and ref', async () => {
        const missing = await api.gitChangeBranchRaw(name, { branch: 'no-such-branch-739' });
        await expectStatus(missing, 422);
        expect(JSON.stringify(missing.body), 'the refusal names git_branch_not_found').toContain(
          'git_branch_not_found'
        );
        const badRef = await api.gitRevertRaw(name, { ref: 'no-such-ref-739' });
        await expectStatus(badRef, 422);
        expect(JSON.stringify(badRef.body), 'the refusal names git_ref_not_found').toContain(
          'git_ref_not_found'
        );
      });

      const { pulled, taskId } = await test.step('start a pull', async () => {
        const response = await api.gitPullRaw(name, { strategy: 'ff' });
        await expectStatus(response, 202);
        const id = taskIdFromBody(response.body);
        expect(id, 'the pull response carries a task id').toEqual(expect.any(Number));
        expect(
          (response.body as { data: { details: { action: string } } }).data.details.action,
          'the task action'
        ).toBe('git_pull');
        return { pulled: response, taskId: id };
      });

      await test.step('refuse a second change while it runs', async () => {
        // A second change while it runs names it instead of starting another.
        for (const again of [
          await api.gitPullRaw(name),
          await api.gitChangeBranchRaw(name, { branch: 'test-branch' }),
          await api.gitRevertRaw(name),
        ]) {
          await expectStatus(again, 409);
          expect(
            (again.body as { task_id?: unknown }).task_id,
            'the refusal names the running task'
          ).toBe(taskId);
        }
      });

      await test.step('wait for the pull', async () => {
        const pullTask = await api.followDeployTask(pulled.body, 'POST git/pull');
        expect((pullTask.details as { commit?: string }).commit, 'the pulled commit').toMatch(
          /^[0-9a-f]{40}$/
        );
      });

      await test.step('change the branch', async () => {
        const changed = await api.gitChangeBranchRaw(name, { branch: 'test-branch' });
        await expectStatus(changed, 202);
        await api.followDeployTask(changed.body, 'PUT git/change-branch');
        expect((await api.gitStatus(name)).data.branch, 'the checked-out branch').toBe(
          'test-branch'
        );
      });

      await test.step('revert', async () => {
        const reverted = await api.gitRevertRaw(name);
        await expectStatus(reverted, 202);
        await api.followDeployTask(reverted.body, 'POST git/revert');
      });
    }
  );
});
