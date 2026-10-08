import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { expectOneOf } from '@/helpers/expect-one-of';
import { cronJobListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { rand } from '@/helpers/random';
import { redactAndTruncate } from '@/helpers/redact';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { removeFileIfPresent } from '@/helpers/file-path-helpers';
import { getUserUidGid, parseUidGid } from '@/helpers/uid-helpers';

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
  test('the job list is returned', { tag: ['@smoke'] }, async ({ api, setupUser }) => {
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
   *
   * The job's output also records which UID and GID it ran as, so the same
   * wait covers the account-ownership check: cron is the classic place where a
   * job silently runs as root.
   */
  test(
    'a job is created, actually runs, is updated, then deleted',
    { tag: ['@security'] },
    async ({ api, cronFactory, settings, setupUser }) => {
      const marker = `test-hash-${Date.now()}`;
      const fileName = `${rand('cron-test-file')}.txt`;
      const absolutePath = `${setupUser.wpPath}/${fileName}`;
      const relativePath = absolutePath.replace(`/home/${setupUser.username}`, '');

      const job = await cronFactory.createMinuteCron(
        setupUser.username,
        `echo "${marker} UID=$(id -u) GID=$(id -g)" > ${absolutePath}`
      );
      expect(job.hash).toBeTruthy();
      // Update can replace the hash; finally must clean whichever hash still exists.
      let currentHash = job.hash;

      const cleanup = new CleanupSteps();
      try {
        const listed = (await api.listCronJobs(setupUser.username)).data.find(
          (candidate) => candidate.hash === job.hash
        );
        expect(listed?.command).toContain('echo');

        await test.step('the job runs and writes its file', async () => {
          await expect
            .poll(
              async () => {
                const exists = (await api.fileExists(setupUser.username, relativePath)).exists;
                const content = exists
                  ? await api.getFileContent(setupUser.username, relativePath)
                  : undefined;
                if (typeof content === 'string' && content.includes(marker)) {
                  return 'ready';
                }
                // Separates "the engine never wrote the crontab" from "the crontab
                // is there but the daemon is not running it".
                const listing = await api.listCronJobs(setupUser.username);
                const present = listing.data.some((candidate) => candidate.hash === job.hash);
                return present
                  ? `job is in the crontab but never fired (is the cron daemon running?); expected ${relativePath}`
                  : 'job is not in the crontab — the engine did not persist it';
              },
              {
                timeout: CRON_EXECUTION_WAIT_MS,
                intervals: [5_000],
                message: 'the cron job never produced its output file',
              }
            )
            .toBe('ready');
        });

        await test.step('a cron job runs as the account', async () => {
          const uid = await getUserUidGid(api, setupUser.username);
          expect
            .soft(uid.uid, 'the user has UID 0, which means it runs as root')
            .toBeGreaterThan(0);
          expect.soft(uid.gid).toBeGreaterThan(0);

          const content = await api.getFileContent(setupUser.username, relativePath);
          const reportedUid = /UID=(\d+)/.exec(content)?.[1];
          const reportedGid = /GID=(\d+)/.exec(content)?.[1];

          expect.soft(reportedUid, `the cron output has no UID line: ${content}`).toBeTruthy();
          expect
            .soft(Number.parseInt(reportedUid ?? '', 10), 'the cron job ran as another user')
            .toBe(uid.uid);
          expect.soft(Number.parseInt(reportedGid ?? '', 10)).toBe(uid.gid);

          const ownership = parseUidGid(await api.getFileStat(setupUser.username, relativePath));
          expect
            .soft(
              ownership.uid,
              `${relativePath} (created via a cron job) is owned by uid ${ownership.uid}`
            )
            .toBe(uid.uid);
          expect
            .soft(
              ownership.gid,
              `${relativePath} (created via a cron job) has gid ${ownership.gid}`
            )
            .toBe(uid.gid);
        });

        const updatedMarker = `updated-hash-${Date.now()}`;
        const updated = await api.updateCronJob(setupUser.username, job.hash, {
          ...EVERY_MINUTE,
          command: `echo "${updatedMarker}" > ${absolutePath}`,
        });
        currentHash = updated.data.hash;

        await expect
          .poll(
            async () => {
              const listed = (await api.listCronJobs(setupUser.username)).data;
              if (
                listed.some(
                  (candidate) =>
                    candidate.hash === updated.data.hash &&
                    candidate.command.includes(updatedMarker)
                )
              ) {
                return 'met';
              }
              const sameHash = listed
                .filter((candidate) => candidate.hash === updated.data.hash)
                .map((candidate) => candidate.command);
              return sameHash.length === 0
                ? `not met: job ${updated.data.hash} not listed`
                : `not met: command ${redactAndTruncate(sameHash.join(' | '), 300)}`;
            },
            { timeout: settings.timing.propagationDelay, intervals: [500] }
          )
          .toBe('met');

        await api.deleteCronJob(setupUser.username, updated.data.hash);
        currentHash = '';

        await expect
          .poll(
            async () =>
              (await api.listCronJobs(setupUser.username)).data.some(
                (candidate) => candidate.hash === updated.data.hash
              )
                ? `not met: job ${updated.data.hash} still listed`
                : 'met',
            { timeout: settings.timing.propagationDelay, intervals: [500] }
          )
          .toBe('met');
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        if (currentHash) {
          await cleanup.run('delete the cron job', () =>
            cronFactory.deleteCronJob(setupUser.username, currentHash)
          );
        }
        await cleanup.run(`remove ${relativePath}`, () =>
          removeFileIfPresent(api, setupUser.username, relativePath)
        );
        await cleanup.finish();
      }
    }
  );
});

test.describe('cron job validation', () => {
  test('updating an unknown hash returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.put(`projects/${setupUser.username}/cron-jobs/noexist`, {
      data: { ...EVERY_MINUTE, command: 'echo noexist' },
    });
    await expectStatus(response, 404);
  });

  test('deleting an unknown hash returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.delete(`projects/${setupUser.username}/cron-jobs/noexist`);
    await expectStatus(response, 404);
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
