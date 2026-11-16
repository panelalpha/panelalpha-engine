import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';
import { sharedProject } from '@/helpers/shared-project';

/** A store only a disabled schedule names can be deleted, and an enabled one still holds it. */
test.describe('a backup store a disabled schedule names', () => {
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('is deleted, and the schedule goes with it', async ({ api }) => {
    const user = await shared.get();
    const name = `pa-api-${rand('ds')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });
    const schedule = { container: String(store.data.id), cron: '0 3 * * *', keep_last: 2 };

    try {
      await api.setBackupSchedule(user.username, schedule);

      await test.step('an enabled schedule keeps its store', async () => {
        const refused = await api.deleteBackupContainer(store.data.id);
        await expectStatus(refused, 409);
        expect(JSON.stringify(refused.body), 'the refusal names the schedule').toContain(
          user.username
        );
      });

      const disabled = await api.setBackupSchedule(user.username, { ...schedule, enabled: false });
      expect(disabled.data.enabled, 'the schedule is off').toBe(false);

      const deleted = await api.deleteBackupContainer(store.data.id);
      await expectStatus(deleted, 200);
      await expectStatus(await api.getBackupContainerRaw(store.data.id), 404);
      expect(
        (await api.getBackupSchedule(user.username)).data,
        'the disabled schedule went with its store'
      ).toBeNull();
    } finally {
      await api.deleteBackupSchedule(user.username);
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });
});
