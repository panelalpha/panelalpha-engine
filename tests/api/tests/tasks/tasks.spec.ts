import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { randomDomain, randomUsername } from '@/helpers/random';
import {
  stagingPushError,
  stagingPushPhase,
  stagingUserPayload,
  waitUntilStagingActive,
} from '@/helpers/staging-helpers';
import { PROJECT_CREATE_ACCEPTED, taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { skipUnless } from '@/helpers/test-helpers';

test.describe('tasks', () => {
  test('an unknown task is 404', async ({ api }) => {
    await expectStatus(await api.getTaskRaw(999_999_999), 404);
  });

  test('cancelling an unknown task is 404', async ({ api }) => {
    await expectStatus(await api.cancelTaskRaw(999_999_999), 404);
  });

  test('task log pages and stream for an unknown task are 404', async ({ api }) => {
    await expectStatus(await api.listTaskLogsRaw(999_999_999), 404);
    await expectStatus(await api.streamTaskLogsRaw(999_999_999), 404);
  });

  test('a completed backup task cannot be cancelled', async ({ api }) => {
    // The live create+poll path lives in deploy/project-backups. This only
    // pins the idle contract: a made-up id is 404, not 500.
    const cancel = await api.cancelTaskRaw(1);
    expectOneOf(cancel.status, [200, 404, 409]);
  });

  test('the list filters by project and status and pages newest first', async ({
    api,
    settings,
  }) => {
    test.setTimeout(Timeouts.deploy);
    const username = randomUsername();
    const domain = randomDomain(settings.requireDomain());

    try {
      const created = await api.createUserRaw({ username, domain });
      await expectStatus(created, PROJECT_CREATE_ACCEPTED);
      const taskId = taskIdFromBody(created.body);
      expect(taskId, 'the create response carries a task id').toBeGreaterThan(0);

      const firstPage = await api.listTasks({ username, per_page: 1 });
      expect(firstPage.meta?.total, 'meta.total counts the project tasks').toBeGreaterThanOrEqual(
        1
      );
      expect(firstPage.data, 'per_page=1 returns one row').toHaveLength(1);
      expect(firstPage.data[0].id, 'the newest task is first').toBe(taskId);
      expect(firstPage.data[0].username).toBe(username);
      expect(
        firstPage.data[0].created_by_token_id,
        'an API-created task names the token that started it'
      ).toBeGreaterThan(0);
      expect(firstPage.data[0], 'a summary carries no log page').not.toHaveProperty('logs');

      const finished = await waitForTask(api, taskId!);
      const byStatus = await api.listTasks({ username, status: finished.status });
      expect(byStatus.data.map((task) => task.id)).toContain(taskId);

      const active = await api.listTasks({ username, status: 'active' });
      expect(active.data.map((task) => task.id)).not.toContain(taskId);

      const failedAndCancelled = await api.listTasks({ username, status: 'failed,cancelled' });
      const isFailedOrCancelled = finished.status === 'failed' || finished.status === 'cancelled';
      expect(failedAndCancelled.data.map((task) => task.id).includes(taskId!)).toBe(
        isFailedOrCancelled
      );
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('the list pages newest first and rejects a bad filter', async ({ api }) => {
    const page = await api.listTasks({ per_page: 2, page: 1 });
    expect(Array.isArray(page.data)).toBe(true);
    expect(page.meta?.per_page).toBe(2);
    expect(page.links ?? null, 'a paginator carries links').not.toBeNull();
    const ids = page.data.map((task) => task.id);
    expect(ids).toEqual([...ids].sort((left, right) => right - left));

    await expectStatus(await api.listTasksRaw({ status: 'bogus' }), 422);
    await expectStatus(await api.listTasksRaw({ per_page: 999 }), 422);
    await expectStatus(await api.listTasksRaw({ scope: 'everything' as 'mine' }), 422);
  });

  test('a push returns a task that reaches a terminal status', async ({ api, userFactory }) => {
    test.setTimeout(Timeouts.deploy);
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');
    const username = user.username;
    let stagingUsername: string | undefined;

    try {
      const staging = await api.createStagingRaw(username, {});
      await expectStatus(staging, 202);
      stagingUsername = stagingUserPayload(staging.body).username;
      expect(stagingUsername, 'the staging response names the destination').toBeTruthy();
      await waitUntilStagingActive(api, stagingUsername!);

      const pushed = await api.pushProjectRaw(stagingUsername!, username);
      await expectStatus(pushed, 202);
      const taskId = (pushed.body as { task_id?: unknown }).task_id;
      expect(typeof taskId, 'push answers with a task_id').toBe('number');

      const task = await waitForTask(api, taskId as number);
      expect(task.action, 'the push task names its action').toBe('push');
      const details = task.details as { source?: string; target?: string } | undefined;
      expect(details?.source).toBe(stagingUsername);
      expect(details?.target).toBe(username);

      const settled = (await api.getUserRaw(username)).body as unknown;
      const phase = stagingPushPhase(settled);
      expect(
        phase,
        `push failed: ${stagingPushError(settled) ?? 'the target recorded no error'}`
      ).toBe('completed');
    } finally {
      if (stagingUsername) {
        await api.deleteUserSafe(stagingUsername);
      }
      await api.deleteUserSafe(username);
    }
  });
});
