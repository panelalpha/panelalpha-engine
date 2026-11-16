import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import type { EngineApi } from '@/clients/engine-api';
import { expectStatus } from '@/helpers/expect-status';
import { waitForTask } from '@/helpers/task-helpers';
import {
  problemsResponseSchema,
  systemBuildCacheListResponseSchema,
  systemBuildCacheQueuedResponseSchema,
  systemBuildCacheRemovedResponseSchema,
} from '@/schemas';

/** A public Go repository that deploys from its own Dockerfile; its cache builds in about three minutes. */
const REPOSITORY = 'https://github.com/TwiN/gatus';
const PATH = 'github.com/twin/gatus';
const REFUSED = 'build_cache_repository_refused';

async function listedRefs(api: EngineApi): Promise<string[]> {
  const listed = await api.listBuildCachesRaw();
  await expectStatus(listed, 200);

  return systemBuildCacheListResponseSchema.parse(listed.body).data.map((cache) => cache.ref);
}

async function expectRefused(
  response: { status: number; body: unknown },
  code: string,
  status = 422
): Promise<void> {
  await expectStatus(response, status);
  const codes = problemsResponseSchema
    .parse(response.body)
    .problems?.map((problem) => problem.code);
  expect(codes).toContain(code);
}

test.describe('System build caches', () => {
  test('lists the caches as repository, branch and build', async ({ api }) => {
    for (const ref of await listedRefs(api)) {
      expect(ref.startsWith('buildcache/')).toBe(true);
    }
  });

  test('refuses a URL that is not plainly public, to build and to remove', async ({ api }) => {
    for (const repository of [
      'https://oauth2:token@gitlab.com/acme/app.git',
      'git@github.com:acme/app.git',
      'http://github.com/acme/app',
      'https://github.com/acme',
    ]) {
      await expectRefused(await api.buildCacheRaw({ repository }), REFUSED);
      await expectRefused(await api.removeBuildCachesRaw({ repository }), REFUSED);
    }
    await expectStatus(await api.buildCacheRaw({}), 422);
  });

  test('removing a repository with no cache removes nothing', async ({ api }) => {
    const removed = await api.removeBuildCachesRaw({
      repository: 'https://github.com/panelalpha/no-such-repository-for-build-caches',
    });
    await expectStatus(removed, 200);
    expect(systemBuildCacheRemovedResponseSchema.parse(removed.body).removed).toEqual([]);
  });

  test('builds a repository once, lists its cache, then removes it', async ({ api }) => {
    test.setTimeout(Timeouts.deploy + 60_000);
    await api.removeBuildCachesRaw({ repository: REPOSITORY });

    const queued = await api.buildCacheRaw({ repository: REPOSITORY });
    await expectStatus(queued, 202);
    const { task_id: taskId } = systemBuildCacheQueuedResponseSchema.parse(queued.body);
    await expectRefused(
      await api.buildCacheRaw({ repository: `${REPOSITORY}.git` }),
      'build_cache_busy',
      409
    );

    const task = await waitForTask(api, taskId, { timeout: Timeouts.deploy });
    expect(task.status, `build cache task ${taskId}`).toBe('completed');
    const built = (await listedRefs(api)).filter((ref) => ref.startsWith(`buildcache/${PATH}:`));
    expect(built.length, 'the repository Dockerfile build has a cache').toBeGreaterThan(0);

    const removed = await api.removeBuildCachesRaw({ repository: REPOSITORY });
    await expectStatus(removed, 200);
    expect(systemBuildCacheRemovedResponseSchema.parse(removed.body).removed.sort()).toEqual(
      built.sort()
    );
    expect((await listedRefs(api)).filter((ref) => ref.startsWith(`buildcache/${PATH}:`))).toEqual(
      []
    );
  });
});
