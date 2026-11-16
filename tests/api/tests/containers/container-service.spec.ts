import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { deployArchive } from '@/helpers/archive-deploy';
import { expectStatus } from '@/helpers/expect-status';
import { readNdjson } from '@/helpers/ndjson-stream';
import { uniqueId } from '@/helpers/random';
import { readPage, waitForPage } from '@/helpers/site-page';
import { skipUnless, skipUnlessResolvableSite } from '@/helpers/test-helpers';
import { SERVICE, serviceCompose, startsIn } from '@/test-data/static/service-compose';

interface ContainerSample {
  service: string | null;
  memory_bytes: number | null;
  sampled_at: string;
}

interface MetricsWindow<T> {
  data: T[];
  meta: { range: string; bucket_seconds: number };
}

/** One compose service's log stream, stop/start/restart and metrics, checked on the site or log. */
test.describe('a compose service', () => {
  test.describe.configure({ mode: 'serial' });
  let running: string | undefined;

  test('logs stream, the service stops, starts and restarts, and metrics follow it', async ({
    api,
    anonymousRequest,
    settings,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 2);
    skipUnless(settings.apiToken, 'API_TOKEN is not set.');
    const user = await userFactory.createDindUser({ tunnel: 'none', autoCleanup: false });
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessResolvableSite(user.domain);
    const name = user.username;
    running = name;
    const marker = uniqueId('svc-');
    const site = `https://${user.domain}/`;
    const streamUrl = (lines: number) =>
      new URL(
        `projects/${name}/containers/${SERVICE}/logs/stream?lines=${lines}`,
        settings.apiBaseUrl
      ).href;
    const startsSoFar = async (): Promise<number> => {
      const read = await readNdjson(streamUrl(200), settings.apiToken!, () => false, 8_000);
      await expectStatus(read, 200, 'the log stream answered');
      return startsIn(
        read.frames.map((frame) => (typeof frame.line === 'string' ? frame.line : ''))
      );
    };

    await test.step('deploy the service', async () => {
      await deployArchive(api, name, { 'docker-compose.yml': serviceCompose(marker) });
      await waitForPage(
        anonymousRequest,
        site,
        (answer) => answer.status === 200 && answer.body.includes(marker),
        `${site} never served the service`
      );
    });

    await test.step('the log stream sends the start line as NDJSON', async () => {
      const read = await readNdjson(
        streamUrl(50),
        settings.apiToken!,
        (frame) => typeof frame.line === 'string' && startsIn([frame.line]) > 0,
        30_000
      );
      await expectStatus(read, 200);
      expect(read.contentType).toContain('ndjson');
      expect(read.matched, `no start line in ${JSON.stringify(read.frames.slice(-5))}`).toBe(true);
    });

    const before = await startsSoFar();

    await test.step('stop takes the site down', async () => {
      const stopped = await api.runServiceContainerActionRaw(name, SERVICE, 'stop');
      await expectStatus(stopped, 200, JSON.stringify(stopped.body));
      await expect
        .poll(
          async () => {
            try {
              const answer = await readPage(anonymousRequest, site);
              return answer.body.includes(marker) ? 'still served' : 'down';
            } catch {
              return 'down';
            }
          },
          { timeout: 30_000, intervals: [1_000], message: 'the site still answered after stop' }
        )
        .toBe('down');
    });

    await test.step('start brings it back', async () => {
      await expectStatus(
        await api.runServiceContainerActionRaw(name, SERVICE, 'start'),
        200,
        'start'
      );
      await waitForPage(
        anonymousRequest,
        site,
        (answer) => answer.status === 200 && answer.body.includes(marker),
        'the site did not come back after start'
      );
    });

    await test.step('restart runs the container again', async () => {
      await expectStatus(
        await api.runServiceContainerActionRaw(name, SERVICE, 'restart'),
        200,
        'restart'
      );
      await expect
        .poll(startsSoFar, { timeout: 30_000, message: 'restart printed no new start line' })
        .toBeGreaterThanOrEqual(before + 2);
      await waitForPage(
        anonymousRequest,
        site,
        (answer) => answer.status === 200 && answer.body.includes(marker),
        'the site did not come back after restart'
      );
    });

    await test.step('the sampler records the service in the latest container metrics', async () => {
      let latest: ContainerSample[] = [];
      await expect
        .poll(
          async () => {
            const response = await api.getContainerMetricsRaw(name);
            await expectStatus(response, 200, 'GET containers/metrics');
            latest = (response.body as { data: ContainerSample[] }).data;
            return latest.some((sample) => sample.service === SERVICE);
          },
          { timeout: 150_000, intervals: [10_000], message: 'no sample of the service' }
        )
        .toBe(true);
      const sample = latest.find((entry) => entry.service === SERVICE)!;
      expect(sample.memory_bytes ?? 0, 'a running nginx uses memory').toBeGreaterThan(0);
      expect(Date.parse(sample.sampled_at)).toBeGreaterThan(Date.now() - 6 * 60_000);
    });

    await test.step('the hour of container history holds that service', async () => {
      let window: MetricsWindow<{ service: string | null; history: unknown[] }> | undefined;
      await expect
        .poll(
          async () => {
            const response = await api.getContainerMetricsRaw(name, '1h');
            await expectStatus(response, 200, 'GET containers/metrics?range=1h');
            window = response.body as typeof window;
            return window!.data.some((entry) => entry.service === SERVICE);
          },
          {
            timeout: 180_000,
            intervals: [15_000],
            message: 'the service never reached the hour of container history',
          }
        )
        .toBe(true);
      expect(window!.meta.range).toBe('1h');
      const series = window!.data.find((entry) => entry.service === SERVICE)!;
      expect(series.history.length * window!.meta.bucket_seconds).toBe(3600);
    });

    await test.step('the project history has memory in the last hour', async () => {
      let window: MetricsWindow<{ memory_bytes: number | null }> | undefined;
      await expect
        .poll(
          async () => {
            const response = await api.getProjectMetricsHistoryRaw(name, '1h');
            await expectStatus(response, 200, 'GET metrics/history?range=1h');
            window = response.body as typeof window;
            return window!.data.some((bucket) => (bucket.memory_bytes ?? 0) > 0);
          },
          {
            timeout: 180_000,
            intervals: [15_000],
            message: 'the project history never showed memory',
          }
        )
        .toBe(true);
      expect(window!.meta.range).toBe('1h');
      expect(window!.data.length * window!.meta.bucket_seconds).toBe(3600);
    });
  });

  test('the log stream reaches an HTTP/1.1 client', async ({ settings, userFactory }) => {
    skipUnless(running, 'The service test did not leave a running service.');
    skipUnless(settings.apiToken, 'API_TOKEN is not set.');
    try {
      const url = new URL(
        `projects/${running}/containers/${SERVICE}/logs/stream?lines=5`,
        settings.apiBaseUrl
      );
      const read = await readNdjson(url.href, settings.apiToken, () => true, 20_000, 'http/1.1');
      await expectStatus(read, 200, 'an HTTP/1.1 client got an answer within 20 s');
      expect(read.frames.length).toBeGreaterThan(0);
    } finally {
      await userFactory.deleteUser(running);
    }
  });
});

test.describe('per-service endpoints on a project without that service', () => {
  test('service actions, logs and metrics refuse what they cannot do', async ({
    api,
    sharedEmptyDind,
  }) => {
    const project = await sharedEmptyDind.get();
    skipUnless(project, 'DinD is not available on this engine.');
    const name = project.username;

    await expectStatus(
      await api.runServiceContainerActionRaw(name, SERVICE, 'explode' as never),
      422,
      'unknown action'
    );
    await expectStatus(
      await api.runServiceContainerActionRaw(name, 'bad..name', 'restart'),
      422,
      'invalid service name'
    );
    await expectStatus(await api.getContainerMetricsRaw(name, '12h'), 422, 'unknown range');
    await expectStatus(await api.getProjectMetricsHistoryRaw(name, '12h'), 422, 'unknown range');
    await expectStatus(await api.getProjectMetricsHistoryRaw('nosuchproject999'), 404, 'unknown');
  });
});
