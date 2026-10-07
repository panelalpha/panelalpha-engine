import { expect, test } from '@/fixtures/test-options';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { taskIdFromBody } from '@/helpers/task-helpers';
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
      const missing = await api.gitChangeBranchRaw(name, { branch: 'no-such-branch-739' });
      expect(missing.status).toBe(422);
      expect(JSON.stringify(missing.body)).toContain('git_branch_not_found');
      const badRef = await api.gitRevertRaw(name, { ref: 'no-such-ref-739' });
      expect(badRef.status).toBe(422);
      expect(JSON.stringify(badRef.body)).toContain('git_ref_not_found');

      const pulled = await api.gitPullRaw(name, { strategy: 'ff' });
      expect(pulled.status).toBe(202);
      const taskId = taskIdFromBody(pulled.body);
      expect(taskId).toEqual(expect.any(Number));
      expect((pulled.body as { data: { details: { action: string } } }).data.details.action).toBe(
        'git_pull'
      );

      // A second change while it runs names it instead of starting another.
      for (const again of [
        await api.gitPullRaw(name),
        await api.gitChangeBranchRaw(name, { branch: 'test-branch' }),
        await api.gitRevertRaw(name),
      ]) {
        expect(again.status).toBe(409);
        expect((again.body as { task_id?: unknown }).task_id).toBe(taskId);
      }

      const pullTask = await api.followDeployTask(pulled.body, 'POST git/pull');
      expect((pullTask.details as { commit?: string }).commit).toMatch(/^[0-9a-f]{40}$/);

      const changed = await api.gitChangeBranchRaw(name, { branch: 'test-branch' });
      expect(changed.status).toBe(202);
      await api.followDeployTask(changed.body, 'PUT git/change-branch');
      expect((await api.gitStatus(name)).data.branch).toBe('test-branch');

      const reverted = await api.gitRevertRaw(name);
      expect(reverted.status).toBe(202);
      await api.followDeployTask(reverted.body, 'POST git/revert');
    }
  );
});
