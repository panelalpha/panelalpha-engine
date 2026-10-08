import type { EngineApi } from '@/clients/engine-api';
import type { BackupRecord } from '@/types';
import { Timeouts } from '@/config/timeouts';
import { collectTaskDiagnostics, formatTaskDiagnostics } from './task-diagnostics';
import { redactAndTruncate } from './redact';
import { waitForCondition } from './retry';

export const TERMINAL_BACKUP_STATUSES = ['completed', 'failed'] as const;

export type BackupAsyncKey = 'backup' | 'restore' | 'delete';

export function backupAsyncStatus(
  record: BackupRecord | undefined,
  key: BackupAsyncKey
): string | undefined {
  const status = record?.async_status;
  if (status === null || typeof status !== 'object') {
    return undefined;
  }
  const value = status[key];
  return typeof value === 'string' ? value : undefined;
}

export function backupTaskId(record: BackupRecord | undefined): number | undefined {
  const status = record?.async_status;
  if (status === null || typeof status !== 'object') {
    return undefined;
  }
  const value = status.task_id;
  return typeof value === 'number' ? value : undefined;
}

export async function waitForBackupPhase(
  api: EngineApi,
  username: string,
  id: number,
  key: BackupAsyncKey,
  options: { timeout?: number; interval?: number } = {}
): Promise<BackupRecord> {
  const timeout = options.timeout ?? Timeouts.deploy;
  const interval = options.interval ?? 2_000;
  let latest: BackupRecord | undefined;

  await waitForCondition(
    async () => {
      const response = await api.getProjectBackupRaw(username, id);
      if (response.status !== 200) {
        return false;
      }
      const body = response.body as { data?: BackupRecord };
      latest = body.data;
      const phase = backupAsyncStatus(latest, key);
      return phase !== undefined && (TERMINAL_BACKUP_STATUSES as readonly string[]).includes(phase);
    },
    {
      timeout,
      interval,
      message: `Backup ${id} for ${username} did not finish ${key}`,
      describeLast: () =>
        latest
          ? `${key}=${backupAsyncStatus(latest, key) ?? 'missing'} error=${latest.error ?? 'none'}`
          : 'no backup yet',
    }
  );

  if (!latest) {
    throw new Error(`Backup ${id} for ${username} produced no record`);
  }
  return latest;
}

/**
 * Waits for a backup delete to finish. A finished delete removes the backup row, so the record
 * answers 404 from then on; `waitForBackupPhase` treats that as "not yet" and would poll until
 * the test times out.
 */
export async function waitForBackupDeleted(
  api: EngineApi,
  username: string,
  id: number,
  options: { timeout?: number; interval?: number } = {}
): Promise<void> {
  let last = 'no response yet';

  await waitForCondition(
    async () => {
      const response = await api.getProjectBackupRaw(username, id);
      if (response.status === 404) {
        return true;
      }
      if (response.status !== 200) {
        last = `HTTP ${response.status}`;
        return false;
      }
      const body = response.body as { data?: BackupRecord };
      const phase = backupAsyncStatus(body.data, 'delete');
      last = `delete=${phase ?? 'missing'} error=${body.data?.error ?? 'none'}`;
      return phase === 'failed';
    },
    {
      timeout: options.timeout ?? Timeouts.default,
      interval: options.interval ?? 2_000,
      message: `Backup ${id} for ${username} was not deleted`,
      describeLast: () => last,
    }
  );
}

/** What a finished backup phase says went wrong: the record's error and, when known, the task's. */
export function describeBackupFailure(record: BackupRecord, key: BackupAsyncKey): string {
  return `${key}=${backupAsyncStatus(record, key) ?? 'missing'} error=${redactAndTruncate(record.error ?? 'none', 600)}`;
}

/**
 * Requires a backup phase to have `completed`. A `failed` phase (or one that
 * never reached a terminal word) throws with the record's error and the task's
 * log tail, so the failure names the cause rather than the status word.
 */
export async function expectBackupPhaseCompleted(
  api: EngineApi,
  username: string,
  record: BackupRecord,
  key: BackupAsyncKey,
  taskId?: number
): Promise<void> {
  if (backupAsyncStatus(record, key) === 'completed') {
    return;
  }
  const id = taskId ?? backupTaskId(record);
  const diagnostics =
    id === undefined
      ? '(no task id on the backup record)'
      : formatTaskDiagnostics(await collectTaskDiagnostics(api, id, { username }));
  throw new Error(
    `Backup ${record.id} of ${username} did not complete its ${key}: ${describeBackupFailure(record, key)}\n${diagnostics}`
  );
}
