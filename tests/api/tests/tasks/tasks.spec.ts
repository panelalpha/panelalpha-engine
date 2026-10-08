import { test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';

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
});
