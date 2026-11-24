import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { sharedProject } from '@/helpers/shared-project';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
import {
  expectBackupPhaseCompleted,
  waitForBackupDeleted,
  waitForBackupPhase,
} from '@/helpers/backup-helpers';
import type { BackupRecord } from '@/types';

test.describe('project backups validation', () => {
  // The two PHP-account tests only receive refusals and share one project.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('a PHP account is no longer refused: a missing store is 404', async ({ api }) => {
    const user = await shared.get();
    const response = await api.createProjectBackupRaw(user.username, { container: 'missing' });
    await expectStatus(response, 404);
    expect(JSON.stringify(response.body), 'the refusal says the store is unknown').toMatch(
      /container not found/i
    );
  });

  test('listing backups for an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.listProjectBackupsRaw('nosuchuser999'), 404);
  });

  test('a missing backup is 404', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(await api.getProjectBackupRaw(user.username, 999_999_999), 404);
  });

  test('a backup that names no store goes to the default one', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const stores = await api.listBackupContainers();
    const fallback = stores.data.find((store) => store.default === true);
    const created = await api.createProjectBackupRaw(user.username, {});
    if (!fallback) {
      await expectStatus(created, 422);
      const problems = (created.body as { problems?: { code?: string }[] }).problems;
      expect(problems?.[0]?.code, 'a host without a default store').toBe('no_default_container');
      return;
    }

    await expectStatus(created, 202);
    const record = (created.body as { data: BackupRecord }).data;
    const finished = await waitForBackupPhase(api, user.username, record.id, 'backup');
    try {
      expect(record.container_id, `the default store ${fallback.name}`).toBe(fallback.id);
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup');
    } finally {
      await api.deleteProjectBackupRaw(user.username, record.id);
      await waitForBackupDeleted(api, user.username, record.id);
    }
  });

  test('creating a backup in an unknown store is refused', async ({ api, sharedEmptyDind }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');
    const unknownStore = await api.createProjectBackupRaw(user.username, {
      container: 'no-such-store',
    });
    expectOneOf(unknownStore.status, [404, 422]);
  });
});
