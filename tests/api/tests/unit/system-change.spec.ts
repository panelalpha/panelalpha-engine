import { expect, test } from '@/fixtures/test-options';
import { describeFinishedChange, hasExitCode } from '@/helpers/system-change';
import type { SystemChangeStatus } from '@/types';

// latest_update as a host reported it after an update that failed (2026-10-05).
const failedUpdate: SystemChangeStatus = {
  started_at: 1791242643,
  finished_at: 1791242712,
  pid: 1273416,
  exit_code: 1,
  tail_stdout:
    'docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml up -d command failed with exit code 1\n[INFO] Worker exited with code 1\n',
  tail_stderr:
    ' Container shared-hosting-core-1 Started \nError response from daemon: failed to bind host port 127.0.0.1:5000/tcp: address already in use\n',
  from_version: null,
  to_version: null,
  logs_path: '/opt/panelalpha/log/engine-updates/latest',
};

test.describe('hasExitCode', () => {
  test('a run that wrote its exit code has finished, whatever the code', () => {
    expect(hasExitCode(failedUpdate)).toBe(true);
    expect(hasExitCode({ ...failedUpdate, exit_code: 0 })).toBe(true);
  });

  test('a run without a readable exit code has not', () => {
    expect(hasExitCode({ ...failedUpdate, finished_at: null, exit_code: null })).toBe(false);
    // finished_at is the exit_code file's mtime; it can show before the code does.
    expect(hasExitCode({ ...failedUpdate, exit_code: null })).toBe(false);
    expect(hasExitCode(null)).toBe(false);
    expect(hasExitCode(undefined)).toBe(false);
  });
});

test.describe('describeFinishedChange', () => {
  test('names the exit code, the log and both output tails', () => {
    expect(describeFinishedChange('The engine update', failedUpdate)).toBe(
      [
        'The engine update exited with 1 (log: /opt/panelalpha/log/engine-updates/latest).',
        'stdout tail:',
        '  docker compose -f /opt/panelalpha/shared-hosting/docker-compose.yml up -d command failed with exit code 1',
        '  [INFO] Worker exited with code 1',
        'stderr tail:',
        '  Container shared-hosting-core-1 Started',
        '  Error response from daemon: failed to bind host port 127.0.0.1:5000/tcp: address already in use',
      ].join('\n')
    );
  });

  test('leaves out an empty stream and says when there is no run', () => {
    expect(
      describeFinishedChange('The engine update', {
        ...failedUpdate,
        exit_code: null,
        tail_stdout: '',
        tail_stderr: null,
      })
    ).toBe(
      'The engine update exited with no exit code (log: /opt/panelalpha/log/engine-updates/latest).'
    );
    expect(describeFinishedChange('The engine update', null)).toBe(
      'The engine update: the engine reports no run.'
    );
  });
});
