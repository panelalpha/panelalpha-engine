import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { sharedProject } from '@/helpers/shared-project';

/**
 * Automatic backups: one schedule per project, cron in UTC at most hourly.
 */
test.describe('backup schedule', () => {
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('a schedule is null, then set, then deleted idempotently', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('bs')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });

    try {
      await test.step('nothing is scheduled yet', async () => {
        const before = await api.getBackupSchedule(user.username);
        expect(before.data, 'no schedule').toBeNull();
      });

      await test.step('the schedule is set', async () => {
        const set = await api.setBackupSchedule(user.username, {
          container: String(store.data.id),
          cron: '0 3 * * *',
          keep_last: 3,
        });
        expect(set.data.cron, 'the cron').toBe('0 3 * * *');
        expect(set.data.keep_last, 'keep_last').toBe(3);
        expect(set.data.enabled, 'enabled').toBe(true);
        expect(set.data.next_run_at, 'a next run').toContain('T03:00:00');

        const readBack = await api.getBackupSchedule(user.username);
        expect(readBack.data?.container_id, 'the container').toBe(store.data.id);
      });

      await test.step('a schedule more frequent than hourly is refused', async () => {
        const response = await api.setBackupScheduleRaw(user.username, {
          container: String(store.data.id),
          cron: '* * * * *',
        });
        await expectStatus(response, 422);
        expect(JSON.stringify(response.body), 'the code').toContain('cron_too_frequent');
      });

      await test.step('an unknown container is refused', async () => {
        const response = await api.setBackupScheduleRaw(user.username, {
          container: 'no-such-store',
          cron: '0 3 * * *',
        });
        await expectStatus(response, 422);
        expect(JSON.stringify(response.body), 'the code').toContain('container_not_found');
      });

      await test.step('the store cannot be deleted while a schedule uses it', async () => {
        const response = await api.deleteBackupContainer(store.data.id);
        await expectStatus(response, 409);
        expect(JSON.stringify(response.body), 'the code').toContain('container_in_use');
      });

      await test.step('the schedule is deleted, twice', async () => {
        await expectStatus(await api.deleteBackupSchedule(user.username), 204);
        await expectStatus(await api.deleteBackupSchedule(user.username), 204);
        const after = await api.getBackupSchedule(user.username);
        expect(after.data, 'the schedule is gone').toBeNull();
      });
    } finally {
      await api.deleteBackupSchedule(user.username);
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });
});
