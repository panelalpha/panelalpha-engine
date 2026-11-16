import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { Timeouts } from '@/config/timeouts';
import { deployArchive, startArchiveDeploy } from '@/helpers/archive-deploy';
import { SiteWatch, describeWatch, type WatchReport } from '@/helpers/site-watch';
import { waitForPage } from '@/helpers/site-page';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { skipUnless, skipUnlessResolvableSite } from '@/helpers/test-helpers';
import { exitingApp, versionOf, versionedApp } from '@/test-data/static/versioned-app';

const BESIDE = 'Zero-downtime redeploy:';
const IN_PLACE = 'Replacing the running app in place';

async function deployLogText(api: EngineApi, username: string): Promise<string> {
  const log = await api.getDeployLog(username);
  return (log.data.lines as { msg?: string }[]).map((line) => line.msg ?? '').join('\n');
}

/** Runs `action` while the site is watched, and records what the watch saw on the test. */
async function watched(
  site: string,
  label: string,
  action: () => Promise<unknown>
): Promise<WatchReport> {
  const watch = new SiteWatch(site, versionOf);
  let report: WatchReport | undefined;
  try {
    await action();
  } finally {
    report = await watch.stop();
    test.info().annotations.push({ type: label, description: describeWatch(report) });
  }
  return report;
}

/** During a redeploy every probe answers the app: no error, no gap, old version until the new. */
test.describe('redeploying a running app', () => {
  test('a new version takes over without the site ever failing to answer', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 3);
    const user = await userFactory.createDindUser({ tunnel: 'none' });
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessResolvableSite(user.domain);
    const site = `https://${user.domain}/`;

    await test.step('deploy v1', async () => {
      await deployArchive(api, user.username, versionedApp('v1'));
      await waitForPage(
        anonymousRequest,
        site,
        (page) => versionOf(page.body) === 'v1',
        'v1 never served'
      );
    });

    const toV2 = await test.step('deploy v2 while the site is watched', () =>
      watched(site, 'v1 to v2', async () => {
        await deployArchive(api, user.username, versionedApp('v2'));
        await waitForPage(
          anonymousRequest,
          site,
          (page) => versionOf(page.body) === 'v2',
          'v2 never served'
        );
      }));

    await test.step('the redeploy ran beside the old version', async () => {
      const log = await deployLogText(api, user.username);
      expect(log, 'the deploy chose the zero-downtime path').toContain(BESIDE);
      expect(log).not.toContain(IN_PLACE);
    });

    await test.step('every probe during v1 to v2 was the app', () => {
      expect(toV2.failures, describeWatch(toV2)).toEqual([]);
      expect(toV2.versions, 'v1 served until v2 took over, and never back').toEqual(['v1', 'v2']);
    });

    const rebuild = await test.step('rebuild v2 while the site is watched', () =>
      watched(site, 'rebuild', () => api.rebuildUser(user.username)));

    await test.step('every probe during the rebuild was v2', () => {
      expect(rebuild.failures, describeWatch(rebuild)).toEqual([]);
      expect(rebuild.versions).toEqual(['v2']);
    });
  });

  test('a version that never answers leaves the previous one serving', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 3);
    const user = await userFactory.createDindUser({ tunnel: 'none' });
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessResolvableSite(user.domain);
    const site = `https://${user.domain}/`;

    await test.step('deploy v1', async () => {
      await deployArchive(api, user.username, versionedApp('v1'));
      await waitForPage(
        anonymousRequest,
        site,
        (page) => versionOf(page.body) === 'v1',
        'v1 never served'
      );
    });

    const broken = await test.step('deploy a version that exits, while the site is watched', () =>
      watched(site, 'broken deploy', async () => {
        const body = await startArchiveDeploy(api, user.username, exitingApp('v2-broken'));
        const taskId = taskIdFromBody(body);
        expect(taskId, 'the deploy answered a task').toBeDefined();
        const task = await waitForTask(api, taskId!, { timeout: Timeouts.deploy });
        expect(task.status, 'a deploy whose app never answers is not reported completed').not.toBe(
          'completed'
        );
      }));

    await test.step('v1 answered on every probe, before and after the failure', () => {
      expect(broken.failures, describeWatch(broken)).toEqual([]);
      expect(broken.versions).toEqual(['v1']);
    });

    await test.step('the deploy log says the running version was kept', async () => {
      const log = await api.getDeployLog(user.username);
      expect(log.data.status).toBe('failed');
      expect(await deployLogText(api, user.username)).toMatch(/kept|still serv/i);
      const health = await api.getAppHealthRaw(user.username);
      expect((health.body as { data?: { serving?: string } }).data?.serving).toBe('ok');
    });

    await test.step('the next good version switches normally', async () => {
      await deployArchive(api, user.username, versionedApp('v3'));
      await waitForPage(
        anonymousRequest,
        site,
        (page) => versionOf(page.body) === 'v3',
        'v3 never served'
      );
    });
  });
});
