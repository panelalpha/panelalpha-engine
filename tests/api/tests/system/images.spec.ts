import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import type { EngineApi } from '@/clients/engine-api';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { waitForTask } from '@/helpers/task-helpers';
import {
  operatorImageResponseSchema,
  problemsResponseSchema,
  systemImageListResponseSchema,
  systemImageOperationResponseSchema,
  systemImageResponseSchema,
} from '@/schemas';
import type {
  ListedOperatorImage,
  SystemImage,
  SystemImageCreateRequest,
  SystemImageOperation,
} from '@/types';

const PULL_REF = 'busybox:1.37.0-musl';
const BUILD_DOCKERFILE = 'FROM busybox:1.37.0-musl\nRUN echo built > /built\n';
const REBUILD_DOCKERFILE = 'FROM busybox:1.37.0-musl\nRUN echo rebuilt > /built\n';
const WHY = 'Playwright system images spec';
const DIGEST = /^sha256:[0-9a-f]{64}$/;
const MISSING_ID = 999_999_999;

type ImageAction = 'publish' | 'refresh' | 'remove';

/** Every image the host lists, validated against the schema. */
async function listImages(api: EngineApi): Promise<SystemImage[]> {
  return systemImageListResponseSchema.parse(await api.listSystemImages()).data;
}

/** The operator entry the list shows for `id`, or undefined when it shows none. */
async function listedOperatorImage(
  api: EngineApi,
  id: number
): Promise<ListedOperatorImage | undefined> {
  return (await listImages(api)).find(
    (image): image is ListedOperatorImage => image.owner === 'operator' && image.id === id
  );
}

/** Waits for the operation's task and fails, naming the image's own error, unless it completed. */
async function expectOperationCompleted(
  api: EngineApi,
  operation: SystemImageOperation,
  action: ImageAction
): Promise<void> {
  expect(operation.data.task_id, 'the image names the task the answer names').toBe(
    operation.task_id
  );
  const task = await waitForTask(api, operation.task_id, { timeout: Timeouts.deploy });
  const after = await api.getSystemImageRaw(operation.data.id);
  const error = (after.body as { data?: { error?: string | null } }).data?.error ?? 'none';
  expect(task.status, `${action} task ${operation.task_id}; the image's error: ${error}`).toBe(
    'completed'
  );
  expect(task.details, 'the task names the operation and the image').toMatchObject({
    action: `operator_image_${action}`,
    image_id: operation.data.id,
  });
}

/** The list's entry for the image, ready and served by the cache registry; a get agrees with it. */
async function expectPublished(api: EngineApi, id: number): Promise<ListedOperatorImage> {
  const listed = await listedOperatorImage(api, id);
  expect(listed, `the list shows image ${id}`).toBeDefined();
  expect(listed!.status, `the image's error: ${listed!.error ?? 'none'}`).toBe('ready');
  expect(listed!.present, 'the cache registry holds it').toBe(true);
  expect(listed!.digest, 'with the digest it serves').toMatch(DIGEST);

  const fetched = systemImageResponseSchema.parse(await api.getSystemImage(id));
  expect(fetched.data.present, 'a get agrees the registry holds it').toBe(true);
  expect(fetched.data.digest, 'a get reports the same digest').toBe(listed!.digest);

  return listed!;
}

/** Deletes the image, waits for the removal, and checks neither a get nor the list shows it. */
async function expectRemoved(api: EngineApi, id: number): Promise<void> {
  const removal = systemImageOperationResponseSchema.parse(await api.deleteSystemImage(id));
  expect(removal.data.status, 'the image reads as being removed until its task runs').toBe(
    'removing'
  );
  await expectOperationCompleted(api, removal, 'remove');

  await expectStatus(await api.getSystemImageRaw(id), 404, 'a removed image is not found');
  expect(await listedOperatorImage(api, id), 'the list no longer shows it').toBeUndefined();
}

/** Removes the image whatever state a failed test left it in, waiting out a running operation. */
async function removeImageSafe(api: EngineApi, id: number): Promise<void> {
  const current = await api.getSystemImageRaw(id);
  if (current.status === 404) {
    return;
  }
  const running = (current.body as { data?: { task_id?: unknown } }).data?.task_id;
  if (typeof running === 'number') {
    await waitForTask(api, running, { timeout: Timeouts.deploy });
  }
  const removal = await api.deleteSystemImageRaw(id);
  await expectStatus(removal, 202, `DELETE system/images/${id} during cleanup`);
  const task = (removal.body as { task_id?: unknown }).task_id;
  if (typeof task === 'number') {
    await waitForTask(api, task, { timeout: Timeouts.deploy });
  }
}

/** Runs `body` against the image, then removes it in a cleanup step that reports a leak. */
async function withImageCleanup(
  api: EngineApi,
  id: number,
  body: () => Promise<void>
): Promise<void> {
  const cleanup = new CleanupSteps();
  try {
    await body();
  } catch (error) {
    cleanup.bodyFailed(error);
    throw error;
  } finally {
    await cleanup.run(`remove operator image ${id}`, () => removeImageSafe(api, id), {
      verify: async () => (await api.getSystemImageRaw(id)).status === 404,
      describe: () => `image ${id} is still listed`,
    });
    await cleanup.finish();
  }
}

/** Posts an image the engine must refuse; one accepted anyway is removed before the test fails. */
async function expectCreateRefused(
  api: EngineApi,
  payload: SystemImageCreateRequest | Record<string, unknown>,
  status: number,
  problem: { field: string; code: string }
): Promise<void> {
  const response = await api.createSystemImageRaw(payload);
  const accepted = (response.body as { data?: { id?: unknown } }).data?.id;
  if (response.status === 202 && typeof accepted === 'number') {
    await removeImageSafe(api, accepted);
  }

  await expectStatus(response, status);
  const { problems } = problemsResponseSchema.parse(response.body);
  expect(
    problems.map(({ field, code }) => ({ field, code })),
    'the refusal names its field and code'
  ).toEqual([problem]);
}

/** Operator images reach every project and removal garbage-collects, so this runs serially. */
test.describe('System images', () => {
  test('the list matches its schema and the engine catalogue follows the operator images', async ({
    api,
  }) => {
    const images = await listImages(api);

    const engine = images.filter((image) => image.owner === 'engine');
    expect(engine.length, 'the engine catalogue is listed').toBeGreaterThan(0);
    for (const image of images.filter((entry) => entry.id === null)) {
      expect(image.owner, `${image.ref} has no id, so it is the engine's`).toBe('engine');
    }

    const firstEngine = images.findIndex((image) => image.owner === 'engine');
    expect(
      images.slice(firstEngine).every((image) => image.owner === 'engine'),
      'no operator image is listed after the engine catalogue'
    ).toBe(true);
  });

  test('a pull image is published, listed as ready, and removed', async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 3);

    const existing = (await listImages(api)).find(
      (image) => image.owner === 'operator' && image.ref === PULL_REF
    );
    expect(
      existing,
      `${PULL_REF} is already an operator image on this engine; this test would remove it`
    ).toBeUndefined();

    const created = systemImageOperationResponseSchema.parse(
      await api.createSystemImage({ ref: PULL_REF, source: 'pull', why: WHY })
    );

    await withImageCleanup(api, created.data.id, async () => {
      expect(created.data.ref).toBe(PULL_REF);
      expect(created.data.source).toBe('pull');
      expect(created.data.status, 'the image waits for its task').toBe('pending');
      expect(created.data.dockerfile, 'a pull image has no Dockerfile').toBeNull();

      await expectOperationCompleted(api, created, 'publish');
      await expectPublished(api, created.data.id);

      await expectRemoved(api, created.data.id);
    });
  });

  test('a build image is built, changed, rebuilt, refreshed and removed', async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 5);

    const ref = `${rand('pa-test-base-')}:1`;
    const created = systemImageOperationResponseSchema.parse(
      await api.createSystemImage({
        ref,
        source: 'build',
        dockerfile: BUILD_DOCKERFILE,
        why: WHY,
      })
    );
    const id = created.data.id;

    await withImageCleanup(api, id, async () => {
      expect(created.data.ref).toBe(ref);
      expect(created.data.dockerfile, 'the Dockerfile is kept as sent').toBe(BUILD_DOCKERFILE);

      await expectOperationCompleted(api, created, 'publish');
      const built = await expectPublished(api, id);

      await test.step('changing only the note answers 200 and queues nothing', async () => {
        const noted = await api.updateSystemImageRaw(id, { why: `${WHY}, changed` });
        await expectStatus(noted, 200);
        expect(noted.body, 'no task is named').not.toHaveProperty('task_id');

        const { data } = operatorImageResponseSchema.parse(noted.body);
        expect(data.why).toBe(`${WHY}, changed`);
        expect(data.dockerfile, 'the Dockerfile is unchanged').toBe(BUILD_DOCKERFILE);
        expect(data.task_id, 'the last operation is still the first build').toBe(created.task_id);
      });

      const rebuilt = await test.step('a changed Dockerfile answers 202 and rebuilds', async () => {
        const changed = await api.updateSystemImageRaw(id, { dockerfile: REBUILD_DOCKERFILE });
        await expectStatus(changed, 202);

        const rebuild = systemImageOperationResponseSchema.parse(changed.body);
        expect(rebuild.data.dockerfile, 'the new Dockerfile is kept').toBe(REBUILD_DOCKERFILE);
        await expectOperationCompleted(api, rebuild, 'publish');

        const published = await expectPublished(api, id);
        expect(published.digest, 'the registry serves the rebuilt image').not.toBe(built.digest);
        return published;
      });

      await test.step('a refresh rebuilds it and publishes it again', async () => {
        const refresh = systemImageOperationResponseSchema.parse(await api.refreshSystemImage(id));
        await expectOperationCompleted(api, refresh, 'refresh');

        const refreshed = await expectPublished(api, id);
        expect(
          Date.parse(refreshed.refreshed_at ?? ''),
          'refreshed_at moved past the rebuild'
        ).toBeGreaterThan(Date.parse(rebuilt.refreshed_at ?? ''));
      });

      await test.step('removing it takes it out of a get and the list', async () => {
        await expectRemoved(api, id);
      });
    });
  });

  test.describe('refusals', () => {
    const refusals: {
      title: string;
      payload: SystemImageCreateRequest | Record<string, unknown>;
      problem: { field: string; code: string };
    }[] = [
      {
        title: 'a build named like an image Docker Hub publishes',
        payload: { ref: 'mysql:8', source: 'build', dockerfile: BUILD_DOCKERFILE },
        problem: { field: 'ref', code: 'operator_image_ref_public' },
      },
      {
        title: 'a name the engine uses for its own images',
        payload: { ref: 'panelalpha/x:1', source: 'build', dockerfile: BUILD_DOCKERFILE },
        problem: { field: 'ref', code: 'operator_image_ref_reserved' },
      },
      {
        title: 'a build named with a registry host',
        payload: { ref: 'ghcr.io/acme/x:1', source: 'build', dockerfile: BUILD_DOCKERFILE },
        problem: { field: 'ref', code: 'operator_image_ref_registry_host' },
      },
      {
        title: 'a name without a tag',
        payload: { ref: rand('pa-test-notag-'), source: 'build', dockerfile: BUILD_DOCKERFILE },
        problem: { field: 'ref', code: 'operator_image_ref_invalid' },
      },
      {
        title: 'a build without a Dockerfile',
        payload: { ref: `${rand('pa-test-nodockerfile-')}:1`, source: 'build' },
        problem: { field: 'dockerfile', code: 'operator_image_dockerfile_required' },
      },
      {
        title: 'a local image the host does not have',
        payload: { ref: `${rand('pa-test-absent-')}:1`, source: 'local' },
        problem: { field: 'ref', code: 'operator_image_not_on_host' },
      },
    ];

    for (const refusal of refusals) {
      test(`${refusal.title} is refused with 422 ${refusal.problem.code}`, async ({ api }) => {
        test.setTimeout(Timeouts.deploy * 2);
        await expectCreateRefused(api, refusal.payload, 422, refusal.problem);
      });
    }

    test('a ref that is already an operator image is refused with 409', async ({ api }) => {
      test.setTimeout(Timeouts.deploy * 3);

      const payload: SystemImageCreateRequest = {
        ref: `${rand('pa-test-taken-')}:1`,
        source: 'build',
        dockerfile: BUILD_DOCKERFILE,
        why: WHY,
      };
      const created = systemImageOperationResponseSchema.parse(
        await api.createSystemImage(payload)
      );

      await withImageCleanup(api, created.data.id, async () => {
        await expectCreateRefused(api, payload, 409, {
          field: 'ref',
          code: 'operator_image_ref_taken',
        });
      });
    });
  });

  test('an id no operator image has answers 404', async ({ api }) => {
    await expectStatus(await api.getSystemImageRaw(MISSING_ID), 404, 'GET');
    await expectStatus(await api.updateSystemImageRaw(MISSING_ID, { why: WHY }), 404, 'PUT');
    await expectStatus(await api.refreshSystemImageRaw(MISSING_ID), 404, 'refresh');
    await expectStatus(await api.deleteSystemImageRaw(MISSING_ID), 404, 'DELETE');
  });
});
