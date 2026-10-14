import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { UserFactory } from '@/test-data/factories/user.factory';

const TOKEN = 'tok_PLANTEDFACTORYTOKEN1';

interface Raw {
  status: number;
  body: unknown;
}

/** An Engine whose create is accepted as task 23 and whose task then ends as given. */
function factoryFor(task: Raw): UserFactory {
  const api = {
    createUserRaw: () => Promise.resolve<Raw>({ status: 202, body: { data: { id: 23 } } }),
    getTaskRaw: () => Promise.resolve(task),
    listTaskLogsRaw: () => Promise.resolve<Raw>({ status: 200, body: { data: [] } }),
    getDeployLogRaw: () => Promise.resolve<Raw>({ status: 404, body: {} }),
  };
  return new UserFactory(api as unknown as EngineApi);
}

const failed: Raw = {
  status: 200,
  body: {
    data: {
      id: 23,
      status: 'failed',
      details: { error: `Deploy failed; token ${TOKEN}` },
      logs: [
        {
          id: 1,
          log: JSON.stringify({ msg: 'Last 1 lines of the deploy log', deploy_log_tail: true }),
        },
        { id: 2, log: JSON.stringify({ msg: 'composer install: exit 2', level: 'error' }) },
      ],
      next_after_id: 2,
    },
  },
};

test.describe('UserFactory create diagnostics', () => {
  test.beforeEach(() => {
    process.env.API_TOKEN = TOKEN;
    process.env.API_BASE_URL ??= 'https://engine.test/api';
  });
  test.afterEach(() => {
    delete process.env.API_TOKEN;
  });

  test('a failed create reports the error and the kept log tail', async () => {
    const factory = factoryFor(failed);
    const error = await factory.createDindUser().then(
      () => new Error('did not throw'),
      (e: unknown) => e as Error
    );

    expect(error.message.startsWith('POST /projects task 23 ended failed\n')).toBe(true);
    expect(error.message).toContain('error: Deploy failed; token [REDACTED]');
    expect(error.message).toContain('[error] composer install: exit 2');
    expect(error.message).not.toContain(TOKEN);
    expect(factory.createTaskOf(factory.pendingCleanup[0])?.taskId).toBe(23);
  });

  test('a failed task without a tail says so', async () => {
    const bare: Raw = {
      status: 200,
      body: { data: { id: 23, status: 'cancelled', details: { error: 'cancelled' } } },
    };
    const error = await factoryFor(bare)
      .createDindUser()
      .catch((e: unknown) => e as Error);

    expect((error as Error).message).toContain('POST /projects task 23 ended cancelled');
    expect((error as Error).message).toContain('(no deploy log tail kept on the task)');
  });
});
