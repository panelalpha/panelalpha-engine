import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { cronJobListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { rand } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';

/**
 * How long to wait for a `* * * * *` job to actually run.
 *
 * A minute schedule should fire within ~60s, but the engine writes the crontab
 * asynchronously and the daemon has its own pickup latency, so this allows about
 * four cycles rather than failing on legitimate lag.
 */
const CRON_EXECUTION_WAIT_MS = 240_000;

const EVERY_MINUTE = {
  minute: '*',
  hour: '*',
  day_of_month: '*',
  month: '*',
  day_of_week: '*',
} as const;

test.describe('cron jobs', () => {
  test('the job list is returned', async ({ api, setupUser }) => {
    const listing = await api.listCronJobs(setupUser.username);
    validateParsedApiResponse(listing, cronJobListSchema);
  });

  test('a new user has no cron jobs', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    expect((await api.listCronJobs(user.username)).data).toHaveLength(0);
  });

  /**
   * The whole point of a cron job is that it runs, so this waits for the job's
   * side effect on disk rather than settling for the API listing it.
   */
  test('a job is created, actually runs, is updated, then deleted', async ({
    api,
    cronFactory,
    settings,
    setupUser,
  }) => {
    const marker = `test-hash-${Date.now()}`;
    const fileName = `${rand('cron-test-file')}.txt`;
    const absolutePath = `${setupUser.wpPath}/${fileName}`;
    const relativePath = absolutePath.replace(`/home/${setupUser.username}`, '');

    const job = await cronFactory.createMinuteCron(
      setupUser.username,
      `echo "${marker}" > ${absolutePath}`
    );
    expect(job.hash).toBeTruthy();
    // Update can replace the hash; finally must clean whichever hash still exists.
    let currentHash = job.hash;

    try {
      const listed = (await api.listCronJobs(setupUser.username)).data.find(
        (candidate) => candidate.hash === job.hash
      );
      expect(listed?.command).toContain('echo');

      await test.step('the job runs and writes its file', async () => {
        await waitForCondition(
          async () => {
            if (!(await api.fileExists(setupUser.username, relativePath)).exists) {
              return false;
            }
            const content = await api.getFileContent(setupUser.username, relativePath);
            return typeof content === 'string' && content.includes(marker);
          },
          {
            timeout: CRON_EXECUTION_WAIT_MS,
            interval: 5_000,
            message: 'the cron job never produced its output file',
            // Separates "the engine never wrote the crontab" from "the crontab
            // is there but the daemon is not running it".
            describeLast: async () => {
              const listing = await api.listCronJobs(setupUser.username);
              const present = listing.data.some((candidate) => candidate.hash === job.hash);
              return present
                ? `job is in the crontab but never fired (is the cron daemon running?); expected ${relativePath}`
                : `job is not in the crontab — the engine did not persist it`;
            },
          }
        );
      });

      const updatedMarker = `updated-hash-${Date.now()}`;
      const updated = await api.updateCronJob(setupUser.username, job.hash, {
        ...EVERY_MINUTE,
        command: `echo "${updatedMarker}" > ${absolutePath}`,
      });
      currentHash = updated.data.hash;

      await waitForCondition(
        async () =>
          (await api.listCronJobs(setupUser.username)).data.some(
            (candidate) =>
              candidate.hash === updated.data.hash && candidate.command.includes(updatedMarker)
          ),
        { timeout: settings.timing.propagationDelay, interval: 500 }
      );

      await api.deleteCronJob(setupUser.username, updated.data.hash);
      currentHash = '';

      await waitForCondition(
        async () =>
          !(await api.listCronJobs(setupUser.username)).data.some(
            (candidate) => candidate.hash === updated.data.hash
          ),
        { timeout: settings.timing.propagationDelay, interval: 500 }
      );
    } finally {
      if (currentHash) {
        await cronFactory.deleteCronJob(setupUser.username, currentHash);
      }
    }
  });
});

test.describe('cron job validation', () => {
  test('updating an unknown hash returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.put(`projects/${setupUser.username}/cron-jobs/noexist`, {
      data: { ...EVERY_MINUTE, command: 'echo noexist' },
    });
    expect(response.status()).toBe(404);
  });

  test('deleting an unknown hash returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.delete(`projects/${setupUser.username}/cron-jobs/noexist`);
    expect(response.status()).toBe(404);
  });

  const invalidUpdates = [
    ['an empty command', { command: '' }],
    ['a minute out of range', { command: 'echo invalid-minute', minute: '61' }],
    ['an hour out of range', { command: 'echo invalid-hour', hour: '99' }],
    ['a day-of-week out of range', { command: 'echo invalid-dow', day_of_week: '8' }],
  ] as const;

  for (const [label, override] of invalidUpdates) {
    test(`refuses an update with ${label}`, async ({
      api,
      authedRequest,
      cronFactory,
      setupUser,
    }) => {
      const job = await cronFactory.createMinuteCron(
        setupUser.username,
        `echo "cron-validation" > ${setupUser.wpPath}/${rand('cron-validation')}.txt`
      );

      try {
        const response = await authedRequest.put(
          `projects/${setupUser.username}/cron-jobs/${job.hash}`,
          { data: { ...EVERY_MINUTE, ...override } }
        );
        expectOneOf(response.status(), [400, 422]);
      } finally {
        await api.deleteCronJob(setupUser.username, job.hash);
      }
    });
  }
});
