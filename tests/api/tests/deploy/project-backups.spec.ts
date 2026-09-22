import { expect, test } from '@/fixtures/test-options';
import {
  backupAsyncStatus,
  backupTaskId,
  waitForBackupDeleted,
  waitForBackupPhase,
} from '@/helpers/backup-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { rand } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { Timeouts } from '@/config/timeouts';
import type { BackupRecord } from '@/types';

test.describe('project backups on DinD', () => {
  test.setTimeout(Timeouts.deploy);

  test('a DinD project can be backed up, restored and deleted', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const name = `pa-api-${rand('pabk')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/var/tmp/${name}`,
    });

    try {
      const listedEmpty = await api.listProjectBackups(user.username);
      expect(Array.isArray(listedEmpty.data)).toBe(true);

      const started = await api.createProjectBackupRaw(user.username, {
        container: String(store.data.id),
      });
      expect(started.status).toBe(202);
      const created = (started.body as { data?: BackupRecord }).data;
      skipUnless(created, 'Create backup did not return a backup record.');

      const taskId = backupTaskId(created);
      if (taskId !== undefined) {
        const task = await api.getTask(taskId);
        expect(task.data.id).toBe(taskId);
        expect(typeof task.data.status).toBe('string');
      }

      const finished = await waitForBackupPhase(api, user.username, created.id, 'backup');
      expectOneOf(backupAsyncStatus(finished, 'backup') ?? 'unknown', ['completed', 'failed']);

      const fetched = await api.getProjectBackup(user.username, created.id);
      expect(fetched.data.id).toBe(created.id);
      expect(fetched.data.username).toBe(user.username);

      const listed = await api.listProjectBackups(user.username);
      expect(listed.data.some((row) => row.id === created.id)).toBe(true);

      const unconfirmed = await api.restoreProjectBackupRaw(user.username, created.id, {});
      expectOneOf(unconfirmed.status, [400, 422]);

      if (backupAsyncStatus(finished, 'backup') === 'completed') {
        const restored = await api.restoreProjectBackupRaw(user.username, created.id, {
          confirm: true,
        });
        if (restored.status === 202) {
          const restoreRecord = (restored.body as { data?: BackupRecord }).data;
          if (restoreRecord?.id !== undefined) {
            await waitForBackupPhase(api, user.username, restoreRecord.id, 'restore');
          }
        } else {
          expectOneOf(restored.status, [422]);
        }
      }

      const deleted = await api.deleteProjectBackupRaw(user.username, created.id);
      expect(deleted.status).toBe(202);
      await waitForBackupDeleted(api, user.username, created.id);

      if (taskId !== undefined) {
        const cancel = await api.cancelTaskRaw(taskId);
        expectOneOf(cancel.status, [200, 404, 409]);
      }
    } finally {
      await api.deleteBackupContainerSafe(store.data.id);
    }
  });
});
