import { expect, test } from '@/fixtures/test-options';
import { isDindUser } from '@/helpers/sss-feature-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { deployHistoryListResponseSchema, deployHistoryLogResponseSchema } from '@/schemas';

test.describe('deploy log', () => {
  test('a non-DinD user cannot read the deploy log', async ({ api, setupUser }) => {
    const response = await api.getDeployLogRaw(setupUser.username);
    expect([403, 404], 'the deploy log is refused').toContain(response.status);
  });

  test('cancelling when nothing is running returns 409 for a DinD user', async ({
    api,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');

    test.skip(!(await isDindUser(api, user.username)), 'DinD is not available on this engine.');

    const deploy = await api.getDeployLog(user.username);
    expect(
      ['none', 'running', 'success', 'failed', 'cancelled'],
      'the deploy status is a known one'
    ).toContain(deploy.data.status);

    if (deploy.data.status !== 'running') {
      const cancel = await api.cancelDeployRaw(user.username);
      await expectStatus(cancel, 409);
    }
  });

  test.describe('deploy history', () => {
    const UNKNOWN_DEPLOY = '20000101-000000-000000';

    test('the shared DinD project lists its deploys newest first', async ({
      api,
      setupDindUser,
    }) => {
      const listedRaw = await api.listDeploysRaw(setupDindUser.username);
      await expectStatus(listedRaw, 200);
      const listed = validateParsedApiResponse(listedRaw.body, deployHistoryListResponseSchema);

      expect(listed.data.length, 'the setup deploy is kept in the history').toBeGreaterThan(0);

      await test.step('every item has an id and a start time', () => {
        for (const item of listed.data) {
          expect(item.id, 'a deploy id starts with the time it started').toMatch(
            /^\d{8}-\d{6}-\w+$/
          );
          expect(item.started_at, `start time of ${item.id}`).toEqual(expect.any(Number));
        }
      });

      await test.step('the ids are sorted newest first', () => {
        const ids = listed.data.map((item) => item.id);
        expect(ids, 'the list is newest first').toEqual([...ids].sort().reverse());
      });

      await test.step('the latest deploy is the first item', async () => {
        const latest = await api.getDeployLog(setupDindUser.username);
        expect(listed.data[0].id, 'the first item is the latest deploy').toBe(latest.data.id);
      });
    });

    test('the newest deploy of the history reads by id in the shape of deploy-log', async ({
      api,
      setupDindUser,
    }) => {
      const listedRaw = await api.listDeploysRaw(setupDindUser.username);
      await expectStatus(listedRaw, 200);
      const listed = validateParsedApiResponse(listedRaw.body, deployHistoryListResponseSchema);
      expect(listed.data.length, 'the setup deploy is kept in the history').toBeGreaterThan(0);
      const first = listed.data[0];

      const pageRaw = await api.readDeployLogRaw(setupDindUser.username, first.id);
      await expectStatus(pageRaw, 200);
      const page = validateParsedApiResponse(pageRaw.body, deployHistoryLogResponseSchema);
      expect(page.data.id, 'the page is the deploy that was asked for').toBe(first.id);
      expect(page.data, 'the page carries the same item as the list').toMatchObject({
        status: first.status,
      });
      expect(page.data.lines.length, 'a kept deploy has log lines').toBeGreaterThan(0);

      await test.step('the lines match what deploy-log reads for the latest deploy', async () => {
        const latest = await api.getDeployLog(setupDindUser.username);
        if (latest.data.id === first.id && first.status !== 'running') {
          expect(page.data.next_offset, 'both read to the same offset').toBe(
            latest.data.next_offset
          );
          expect(page.data.lines, 'both read the same lines').toEqual(latest.data.lines);
        }
      });
    });

    test('an unknown deploy id answers 404', async ({ api, setupDindUser }) => {
      await expectStatus(await api.readDeployLogRaw(setupDindUser.username, UNKNOWN_DEPLOY), 404);
    });

    test('a non-DinD project answers its history without a DinD check', async ({
      api,
      setupUser,
    }) => {
      // Unlike deploy-log, the history endpoints do not require DinD: they read the log files.
      const listedRaw = await api.listDeploysRaw(setupUser.username);
      await expectStatus(listedRaw, 200);
      validateParsedApiResponse(listedRaw.body, deployHistoryListResponseSchema);

      await expectStatus(await api.readDeployLogRaw(setupUser.username, UNKNOWN_DEPLOY), 404);
    });
  });
});
