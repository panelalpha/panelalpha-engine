import { expect, test } from '@/fixtures/test-options';
import {
  assertDeploymentWarnings,
  requireServing,
  unacceptedDeploymentWarnings,
} from '@/helpers/app-health';
import { expectBackupPhaseCompleted } from '@/helpers/backup-helpers';
import { stagingPushError } from '@/helpers/staging-helpers';
import type { EngineApi } from '@/clients/engine-api';
import type { BackupRecord } from '@/types';

const UNREACHABLE =
  'The application is deployed but not reachable from the internet: app.test resolves on this host only.';
const CERTIFICATE =
  'https://app.test is served a certificate browsers will refuse (self_signed). Request a real one.';

test.describe('deployment warnings', () => {
  test('a partial deploy may carry only public-name and certificate warnings', () => {
    expect(() => assertDeploymentWarnings('partial', [UNREACHABLE, CERTIFICATE])).not.toThrow();
  });

  test('a partial deploy with a serving warning fails', () => {
    const notAnswering =
      'The application started but did not answer on any port it publishes. The deploy log has the per-port result.';
    expect(unacceptedDeploymentWarnings([UNREACHABLE, notAnswering])).toEqual([notAnswering]);
    expect(() => assertDeploymentWarnings('partial', [notAnswering])).toThrow();
  });

  test('a health report without a serving word is refused', () => {
    expect(() => requireServing({ data: { healthy: true, ports: [], checks: [] } })).toThrow(
      /without a serving word/
    );
  });
});

test.describe('push error', () => {
  test('is read from details.error', () => {
    expect(stagingPushError({ data: { details: { error: 'rsync failed' } } })).toBe('rsync failed');
    expect(stagingPushError({ data: { details: { error: null } } })).toBeUndefined();
    expect(stagingPushError({})).toBeUndefined();
  });
});

test.describe('expectBackupPhaseCompleted', () => {
  const record = (status: string): BackupRecord => ({
    id: 7,
    username: 'u',
    container_id: 1,
    async_status: { backup: status },
    error: status === 'failed' ? 'disk full' : null,
  });
  const api = {} as EngineApi;

  test('passes on completed', async () => {
    await expect(
      expectBackupPhaseCompleted(api, 'u', record('completed'), 'backup')
    ).resolves.toBeUndefined();
  });

  test('names the record error when the phase failed', async () => {
    await expect(expectBackupPhaseCompleted(api, 'u', record('failed'), 'backup')).rejects.toThrow(
      /did not complete its backup: backup=failed error=disk full/
    );
  });
});
