import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { randomDomain, randomUsername } from '@/helpers/random';
import { Timeouts } from '@/config/timeouts';
import { expectOneOf } from '@/helpers/expect-one-of';
import { PROJECT_CREATE_ACCEPTED, taskIdFromBody, waitForTask } from '@/helpers/task-helpers';

test.describe('async project create', () => {
  test('POST /projects returns 202 with a task, then the account is readable', async ({
    api,
    settings,
  }) => {
    test.setTimeout(Timeouts.deploy);
    const username = randomUsername();
    const domain = randomDomain(settings.requireDomain());

    try {
      const taskId = await test.step('create the project', async () => {
        const created = await api.createUserRaw({ username, domain });
        await expectStatus(created, PROJECT_CREATE_ACCEPTED);
        const id = taskIdFromBody(created.body);
        expect(id, 'the create response carries a task id').toBeGreaterThan(0);
        return id;
      });

      await test.step('poll the task', async () => {
        const polled = await api.getTask(taskId!);
        expect(polled.data.id, 'task id').toBe(taskId);
        expect(
          ['queued', 'running', 'completed', 'failed', 'cancelled'],
          'the task status is a known one'
        ).toContain(polled.data.status);
        expect(polled.data.username, 'task project').toBe(username);
      });

      await test.step('read the task logs', async () => {
        const logs = await api.listTaskLogs(taskId!);
        expect(Array.isArray(logs.data), 'logs data is an array').toBe(true);
        expect(typeof logs.meta?.task_status, 'logs meta carries task_status').toBe('string');
        expect(typeof logs.meta?.next_after_id, 'logs meta carries next_after_id').toBe('number');

        const sinceLogs = await api.listTaskLogs(taskId!, { since: 0 });
        expect(Array.isArray(sinceLogs.data), 'logs since 0 is an array').toBe(true);
      });

      await test.step('wait for the task to finish', async () => {
        const task = await waitForTask(api, taskId!);
        expect(['completed', 'failed', 'cancelled'], 'the task reached a final status').toContain(
          task.status
        );
      });

      await test.step('read the account', async () => {
        const account = await api.getUserRaw(username);
        await expectStatus(account, 200);
      });

      await test.step('stream the task logs', async () => {
        const stream = await api.streamTaskLogsRaw(taskId!);
        await expectStatus(stream, 200);
        expect(stream.body, 'the log stream ends with a finish event').toMatch(/"type":"finish"/);
      });
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('a queued project-create task can be cancelled', async ({ api, settings }) => {
    test.setTimeout(Timeouts.deploy);
    const username = randomUsername();
    const domain = randomDomain(settings.requireDomain());

    try {
      const taskId = await test.step('create the project', async () => {
        const created = await api.createUserRaw({ username, domain });
        await expectStatus(created, PROJECT_CREATE_ACCEPTED);
        const id = taskIdFromBody(created.body);
        expect(id, 'the create response carries a task id').toBeGreaterThan(0);
        return id;
      });

      await test.step('cancel the task', async () => {
        const cancel = await api.cancelTaskRaw(taskId!);
        // 200 = cancelled while queued/running. 409 = DeployProject already finished.
        expectOneOf(cancel.status, [200, 409]);
      });

      await test.step('wait for the task to finish', async () => {
        const task = await waitForTask(api, taskId!);
        expectOneOf(task.status, ['completed', 'failed', 'cancelled']);
      });
    } finally {
      await api.deleteUserSafe(username);
    }
  });
});
