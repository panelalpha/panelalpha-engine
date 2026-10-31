import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { getUserUidGid, parseUidGid } from '@/helpers/uid-helpers';

/** A `* * * * *` job fires at the next minute; this allows about four cycles. */
const CRON_EXECUTION_WAIT_MS = 240_000;

/** Where the runner writes each run, relative to the project's home. */
const CRON_LOG = '/.panelalpha/cron/cron.log';

const EVERY_MINUTE = {
  minute: '*',
  hour: '*',
  day_of_month: '*',
  month: '*',
  day_of_week: '*',
} as const;

/** The account's cron runs the job in the named service's container and logs it in the home. */
test.describe('cron jobs in a container project', () => {
  test('a job without a service is refused under the service field', async ({
    api,
    authedRequest,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');
    test.skip(
      !(await isDindUser(api, user.username)),
      'The shared project is not a container project.'
    );

    const response = await authedRequest.post(`projects/${user.username}/cron-jobs`, {
      data: { ...EVERY_MINUTE, command: 'true' },
    });
    await expectStatus(response, 422);
    const body = (await response.json()) as { errors?: Record<string, unknown> };
    expect(Object.keys(body.errors ?? {})).toContain('service');
  });

  test(
    'a job runs in its service, as the account, and is logged',
    { tag: ['@security'] },
    async ({ api, sharedEmptyDind }) => {
      const user = await sharedEmptyDind.get();
      skipUnless(user, 'DinD is not available on this engine.');
      test.skip(
        !(await isDindUser(api, user.username)),
        'The shared project is not a container project.'
      );
      await api.runProjectContainerAction(user.username, 'up');

      const marker = `cron-in-service-${Date.now()}`;
      const job = (
        await api.createCronJob(user.username, {
          ...EVERY_MINUTE,
          service: 'app',
          command: `echo "${marker} $(date +%s) 100%"`,
        })
      ).data;

      try {
        expect(job.service).toBe('app');
        const listed = (await api.listCronJobs(user.username)).data.find(
          (c) => c.hash === job.hash
        );
        expect(listed?.command, 'the command lists back as written').toBe(job.command);

        await expect
          .poll(
            async () => {
              const exists = (await api.fileExists(user.username, CRON_LOG)).exists;
              const log = exists ? await api.getFileContent(user.username, CRON_LOG) : '';
              return /\d+ 100%\n/.test(log) && log.includes(marker) && log.includes('[app] exit 0')
                ? 'ran'
                : `no finished run in ${CRON_LOG} yet`;
            },
            {
              timeout: CRON_EXECUTION_WAIT_MS,
              intervals: [5_000],
              message: 'the job never ran in its service',
            }
          )
          .toBe('ran');

        const account = await getUserUidGid(api, user.username);
        const owner = parseUidGid(await api.getFileStat(user.username, CRON_LOG));
        expect(owner.uid, 'the runner ran as the account user, not root').toBe(account.uid);
      } finally {
        await api.deleteCronJob(user.username, job.hash);
      }
    }
  );
});
