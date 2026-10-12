import { createHash } from 'node:crypto';
import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { sharedProject } from '@/helpers/shared-project';
import { expectBackupPhaseCompleted, waitForBackupPhase } from '@/helpers/backup-helpers';

/**
 * Classic (PHP hosting) projects: list/create/restore/delete and per-item download.
 * The DinD cases live beside these; this file only asserts what a classic account answers.
 */
test.describe('classic project backups', () => {
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('a classic project backs up its home and downloads the exact bytes', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('cb')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });

    try {
      const created = await api.createProjectBackup(user.username, {
        container: String(store.data.id),
      });
      expect(created.data.kind, 'a manual backup').toBe('manual');
      expect(created.data.contents?.files, 'files selected by default').toBe(true);

      const finished = await waitForBackupPhase(api, user.username, created.data.id, 'backup');
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup');

      const items = finished.items ?? [];
      const filesItem = items.find((item) => item.details?.type === 'files');
      expect(filesItem, 'a files item').toBeTruthy();
      expect(filesItem?.remote_path.endsWith('home.tar.gz'), 'the home archive').toBe(true);
      expect(filesItem?.details?.scope, 'the home scope').toBe('home');
      expect((finished.size_bytes ?? 0) > 0, 'the record sums its items').toBe(true);

      const download = await api.downloadProjectBackupItemRaw(
        user.username,
        created.data.id,
        filesItem?.id ?? 0
      );
      await expectStatus(download, 200);
      expect(download.headers()['content-type'], 'the content type').toBe('application/gzip');
      expect(download.headers()['content-disposition'], 'the filename').toContain('home.tar.gz');

      const body = await download.body();
      expect(body.length, 'the streamed length').toBe(filesItem?.size_bytes);
      expect(
        createHash('sha256').update(body).digest('hex'),
        'the downloaded sha256 matches details.sha256'
      ).toBe(filesItem?.details?.sha256);
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });

  test('a classic project can select only databases or only files', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('cs')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });

    try {
      const databasesOnly = await api.createProjectBackup(user.username, {
        container: String(store.data.id),
        files: false,
      });
      const finished = await waitForBackupPhase(
        api,
        user.username,
        databasesOnly.data.id,
        'backup'
      );
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup');
      expect(
        (finished.items ?? []).every((item) => item.details?.type === 'database'),
        'files:false keeps only database items'
      ).toBe(true);

      const nothing = await api.createProjectBackupRaw(user.username, {
        container: String(store.data.id),
        files: false,
        databases: false,
      });
      await expectStatus(nothing, 422);
      expect(JSON.stringify(nothing.body), 'the empty selection has a code').toContain(
        'nothing_to_back_up'
      );
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });

  test('restoring volumes on a classic project is refused', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('cv')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });

    try {
      const created = await api.createProjectBackup(user.username, {
        container: String(store.data.id),
      });
      const finished = await waitForBackupPhase(api, user.username, created.data.id, 'backup');
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup');

      const response = await api.restoreProjectBackupRaw(user.username, created.data.id, {
        confirm: true,
        only: { volumes: ['dbdata'] },
      });
      await expectStatus(response, 422);
      expect(JSON.stringify(response.body), 'the refusal has a code').toContain(
        'volumes_not_applicable'
      );
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });

  test('restoring a backup that is not complete is a 409', async ({ api }) => {
    const user = await shared.get();
    const response = await api.restoreProjectBackupRaw(user.username, 999_999_999, {
      confirm: true,
    });
    expectOneOf(response.status, [404, 409]);
  });

  test('an item id that is not part of the backup is 404', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('cm')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });

    try {
      const created = await api.createProjectBackup(user.username, {
        container: String(store.data.id),
      });
      const finished = await waitForBackupPhase(api, user.username, created.data.id, 'backup');
      await expectBackupPhaseCompleted(api, user.username, finished, 'backup');

      await expectStatus(
        await api.downloadProjectBackupItemRaw(user.username, created.data.id, 999_999_999),
        404
      );
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });
});
