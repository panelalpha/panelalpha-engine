import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { Timeouts } from '@/config/timeouts';
import {
  backupTaskId,
  expectBackupPhaseCompleted,
  waitForBackupPhase,
} from '@/helpers/backup-helpers';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { expectFileContentEquals, getDomainBasePath } from '@/helpers/file-path-helpers';
import { rand, uniqueId } from '@/helpers/random';
import { waitForTask } from '@/helpers/task-helpers';
import type { RestoreBackupRequest } from '@/types';

/** Restores a backup and waits until both the backup record and its task say it completed. */
async function restoreAndWait(
  api: EngineApi,
  username: string,
  backupId: number,
  data: RestoreBackupRequest
): Promise<void> {
  const accepted = await api.restoreProjectBackup(username, backupId, data);
  const finished = await waitForBackupPhase(api, username, backupId, 'restore');
  await expectBackupPhaseCompleted(api, username, finished, 'restore');

  const taskId = backupTaskId(finished) ?? backupTaskId(accepted.data);
  if (taskId !== undefined) {
    const task = await waitForTask(api, taskId);
    expect(task.status, `restore task ${taskId}: ${JSON.stringify(task.details)}`).toBe(
      'completed'
    );
  }
}

/**
 * A classic restore that keeps existing files merges the archive into the
 * live home as the account: archived files get their backed-up content back,
 * files added since stay, and what it writes belongs to the account. A
 * restore that deletes existing files swaps the home for the archive.
 */
test.describe('classic project restore', () => {
  test('keeping existing files merges the archive; deleting them replaces the home', async ({
    api,
    userFactory,
  }, testInfo) => {
    test.setTimeout(Timeouts.deploy * 2);

    const user = await userFactory.createSimpleUser();
    const dir = `${getDomainBasePath(user.domain)}/${uniqueId('restore-')}`;
    const archived = `${dir}/archived.txt`;
    const added = `${dir}/added-after-backup.txt`;
    const backedUp = `backed-up-${rand()}`;

    const name = `pa-api-${rand('cr')}`;
    const store = await api.createBackupContainer({
      name,
      driver: 'local',
      location: `/opt/panelalpha/backups/${name}`,
    });
    const cleanup = new CleanupSteps(testInfo);

    try {
      await api.createDirectory(user.username, dir, true);
      await api.putFileContents(user.username, archived, backedUp);
      const accountUid = (await api.getFileStat(user.username, archived)).uid;
      expect(accountUid, 'a file written through the API belongs to the account').toBeGreaterThan(
        0
      );

      const created = await api.createProjectBackup(user.username, {
        container: String(store.data.id),
        files: true,
        databases: false,
      });
      const backup = await waitForBackupPhase(api, user.username, created.data.id, 'backup');
      await expectBackupPhaseCompleted(api, user.username, backup, 'backup');

      await api.putFileContents(user.username, archived, `changed-after-backup-${rand()}`);
      await api.putFileContents(user.username, added, 'not in the backup');

      await test.step('a restore that keeps existing files merges the archive in', async () => {
        await restoreAndWait(api, user.username, created.data.id, {
          confirm: true,
          delete_existing_files: false,
        });

        expectFileContentEquals(await api.getFileContent(user.username, archived), backedUp);
        expect(
          (await api.fileExists(user.username, added)).exists,
          'a file added after the backup is kept'
        ).toBe(true);
        expect(
          (await api.getFileStat(user.username, archived)).uid,
          'the restored file belongs to the account'
        ).toBe(accountUid);
        expect(
          (await api.getFileStat(user.username, dir)).uid,
          'the merged directory belongs to the account'
        ).toBe(accountUid);
      });

      await test.step('a restore that deletes existing files replaces the home', async () => {
        await restoreAndWait(api, user.username, created.data.id, {
          confirm: true,
          delete_existing_files: true,
        });

        expectFileContentEquals(await api.getFileContent(user.username, archived), backedUp);
        expect(
          (await api.fileExists(user.username, added)).exists,
          'a file added after the backup is gone'
        ).toBe(false);
        expect(
          (await api.getFileStat(user.username, archived)).uid,
          'the restored file belongs to the account'
        ).toBe(accountUid);
      });
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the backup container', () =>
        api.deleteBackupContainerSafe(store.data.id)
      );
      await cleanup.finish();
    }
  });
});
