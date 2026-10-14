import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

test.describe('backup stores', () => {
  test('a local store is created, tested, updated and deleted', async ({ api }) => {
    const name = `pa-api-${rand('bk')}`;
    const location = `/opt/panelalpha/backups/${name}`;
    const created = await api.createBackupContainer({
      name,
      driver: 'local',
      location,
    });

    try {
      await test.step('check the created store', () => {
        expect(created.data.name, 'store name').toBe(name);
        expect(created.data.driver, 'store driver').toBe('local');
        expect(created.data.location, 'store location').toBe(location);
        expect(created.data.has_credentials, 'a local store has no credentials').toBe(false);
      });

      await test.step('read the store back and list it', async () => {
        const fetched = await api.getBackupContainer(created.data.id);
        expect(fetched.data.id, 'fetched store id').toBe(created.data.id);

        const listed = await api.listBackupContainers();
        expect(
          listed.data.some((row) => row.id === created.data.id),
          'the new store is listed'
        ).toBe(true);
      });

      await test.step('test the store', async () => {
        // A writable /var/tmp location has no reason to be refused (Local::test throws only for a
        // missing or unwritable root, BackupContainerController.php:221-228).
        const probed = await api.testBackupContainerRaw(created.data.id);
        await expectStatus(probed, 200);
        expect(
          (probed.body as { data?: { ok?: boolean } }).data?.ok,
          'the store test reports ok'
        ).toBe(true);
      });

      await test.step('rename the store', async () => {
        const renamed = `${name}-ren`;
        const updated = await api.updateBackupContainer(created.data.id, { name: renamed });
        expect(updated.data.name, 'updated store name').toBe(renamed);
      });
    } finally {
      await api.deleteBackupContainerSafe(created.data.id);
    }

    await test.step('check the store is gone', async () => {
      await expectStatus(await api.getBackupContainerRaw(created.data.id), 404);
    });
  });

  test('a store whose location cannot be written fails its test', async ({ api }) => {
    // Allowed (under /opt/panelalpha, engine#809) but not creatable: its parent is a
    // regular file, so the probe cannot create the root (Local.php ensureWritableDirectory).
    const created = await api.createBackupContainer({
      name: `pa-api-${rand('ro')}`,
      driver: 'local',
      location: `/opt/panelalpha/shared-hosting/docker-compose.yml/pa-api-${rand('ro')}`,
    });
    try {
      const probed = await api.testBackupContainerRaw(created.data.id);
      await expectStatus(probed, 422);
      expect(
        String((probed.body as { message?: unknown }).message),
        'the failure names the backup storage directory'
      ).toMatch(/backup storage (directory|root)/i);
    } finally {
      await api.deleteBackupContainerSafe(created.data.id);
    }
  });

  test('a missing store is 404', async ({ api }) => {
    await expectStatus(await api.getBackupContainerRaw(999_999_999), 404);
  });

  test('an invalid local location is refused', async ({ api }) => {
    const response = await api.createBackupContainerRaw({
      name: `pa-api-${rand('bad')}`,
      driver: 'local',
      location: '/',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('s3 without credentials is refused', async ({ api }) => {
    const response = await api.createBackupContainerRaw({
      name: `pa-api-${rand('s3')}`,
      driver: 's3',
      location: 'bucket/prefix',
    });
    expectOneOf(response.status, [400, 422]);
  });
});
