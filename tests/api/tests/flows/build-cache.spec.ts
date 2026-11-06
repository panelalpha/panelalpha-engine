import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { Timeouts } from '@/config/timeouts';
import { deployArchive } from '@/helpers/archive-deploy';
import { skipUnless } from '@/helpers/test-helpers';
import type { DeployTimings } from '@/types';
import { versionedApp } from '@/test-data/static/versioned-app';

/** The warm-rebuild floor `scripts/tools/benchmark-deploys.sh` holds a rebuild to. */
const MIN_WARM_RATIO = Number(process.env.MIN_WARM_RATIO ?? '0.5');

interface BuildStep {
  command: string;
  cached: boolean;
}

async function timingsOf(api: EngineApi, username: string): Promise<DeployTimings> {
  const log = await api.getDeployLog(username, { buildTimings: true });
  expect(log.data.timings, 'a finished deploy records timings').toBeTruthy();
  return log.data.timings!;
}

function layerSteps(timings: DeployTimings): BuildStep[] {
  return ((timings.build?.slowest ?? []) as BuildStep[]).filter((step) =>
    /^(RUN|COPY|ADD) /.test(step.command)
  );
}

function runStep(timings: DeployTimings): BuildStep | undefined {
  return layerSteps(timings).find((step) => step.command.startsWith('RUN '));
}

function record(label: string, timings: DeployTimings): void {
  const phases = timings.phases.map((phase) => `${phase.name} ${phase.seconds}s`).join(', ');
  test.info().annotations.push({
    type: label,
    description: `${timings.total_seconds}s total (${phases}); build ${timings.build?.step_count} steps, ${timings.build?.cached_steps} cached, ratio ${timings.build?.cache_hit_ratio}`,
  });
}

/** A rebuild must come out of the BuildKit cache, judged by the deploy log's layer timings. */
test.describe('the build cache', () => {
  test.describe.configure({ mode: 'serial' });
  let rebuilt: DeployTimings | undefined;

  test('a rebuild and a one-layer change reuse the cached layers', async ({ api, userFactory }) => {
    test.setTimeout(Timeouts.deploy * 3);
    const user = await userFactory.createDindUser({ tunnel: 'none' });
    skipUnless(user, 'DinD is not available on this engine.');

    const cold = await test.step('the first build runs every step', async () => {
      await deployArchive(api, user.username, versionedApp('v1'));
      const timings = await timingsOf(api, user.username);
      record('cold', timings);
      expect(timings.build?.step_count ?? 0, 'the Dockerfile was built').toBeGreaterThan(0);
      expect(runStep(timings)?.cached, 'the RUN step ran').toBe(false);
      return timings;
    });

    await test.step('a rebuild of the same source is served from the cache', async () => {
      await api.rebuildUser(user.username);
      const timings = await timingsOf(api, user.username);
      record('rebuild', timings);
      expect(timings.build?.step_count ?? 0).toBeGreaterThan(0);
      rebuilt = timings;
      const layers = layerSteps(timings);
      expect(layers.length, 'the rebuild reports its RUN and COPY layers').toBe(2);
      expect(
        layers.filter((step) => !step.cached).map((step) => step.command),
        'every layer of an unchanged source came from the cache'
      ).toEqual([]);
      expect(timings.build?.cache_hit_ratio ?? 0).toBeGreaterThan(cold.build?.cache_hit_ratio ?? 0);
      expect(
        timings.phases.map((phase) => phase.name),
        'a rebuild does not provision the account again'
      ).not.toContain('preparing');
    });

    await test.step('a change to the last layer keeps the RUN step cached', async () => {
      await deployArchive(api, user.username, versionedApp('v2'));
      const timings = await timingsOf(api, user.username);
      record('one-layer change', timings);
      expect(runStep(timings)?.cached, 'the RUN step before the change came from the cache').toBe(
        true
      );
    });
  });

  test('a fully cached rebuild reports a ratio above the warm floor', () => {
    skipUnless(rebuilt, 'The cache test did not record a rebuild.');
    expect(rebuilt.build?.cache_hit_ratio ?? 0).toBeGreaterThanOrEqual(MIN_WARM_RATIO);
  });
});
