import { expect, test } from '@/fixtures/test-options';
import { sshCommandResultSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { sharedProject } from '@/helpers/shared-project';

test.describe('SSH command', () => {
  // Read-only tests share one DinD project; the isolation test needs two accounts of its own.
  const shared = sharedProject('dind');
  test.afterAll(() => shared.dispose());

  test('a classic user is refused', async ({ api, setupUser }) => {
    const response = await api.runSshCommandRaw(setupUser.username, { command: 'echo ok' });
    await expectStatus(response, 422);
  });

  test('a missing user is a 404', async ({ api }) => {
    const response = await api.runSshCommandRaw('nosuchprojectxx', { command: 'echo ok' });
    await expectStatus(response, 404);
  });

  test('an empty command is a 422', async ({ api }) => {
    const user = await shared.get();
    skipUnless(user, 'DinD is not available on this engine.');
    const response = await api.runSshCommandRaw(user.username, { command: '' });
    await expectStatus(response, 422);
  });

  test('a DinD user can run a command and a failing exit stays HTTP 200', async ({ api }) => {
    const user = await shared.get();
    skipUnless(user, 'DinD is not available on this engine.');

    const ok = await api.runSshCommand(user.username, { command: 'echo ok' });
    validateParsedApiResponse(ok, sshCommandResultSchema);
    expect(ok.exit_code).toBe(0);
    expect(ok.stdout).toContain('ok');

    const failed = await api.runSshCommand(user.username, { command: 'false' });
    expect(failed.exit_code).not.toBe(0);
  });

  test('cwd is honoured and a short timeout kills a long sleep', async ({ api }) => {
    const user = await shared.get();
    skipUnless(user, 'DinD is not available on this engine.');

    const cwd = await api.runSshCommand(user.username, { command: 'pwd', cwd: '/tmp' });
    expect(cwd.exit_code).toBe(0);
    expect(cwd.stdout).toContain('/tmp');

    // The endpoint documents 200 (exit_code in the body), 404 and 422 only
    // (SshController::run OpenAPI responses); a killed command is reported as a
    // non-zero exit_code, not an HTTP error (Dind::runSshCommand returns 124 for a
    // process without an exit code).
    const timedOut = await api.runSshCommandRaw(user.username, {
      command: 'sleep 30',
      timeout: 1,
    });
    test.info().annotations.push({
      type: 'issue',
      description:
        'Engine: a timed-out SSH command throws ProcessTimedOutException (core/app/System/HostProcess.php:95-103) that the SSH endpoint does not map, so it answers 500 instead of 200 with a non-zero exit_code',
    });
    await expectStatus(timedOut, 200);
    const body = timedOut.body as { exit_code?: number };
    expect(body.exit_code).not.toBe(0);
  });

  test('pipes reach bash intact', async ({ api }) => {
    const user = await shared.get();
    skipUnless(user, 'DinD is not available on this engine.');
    const result = await api.runSshCommand(user.username, { command: 'printf "a\\nb\\n" | wc -l' });
    expect(result.exit_code).toBe(0);
    expect(result.stdout.trim()).toMatch(/2/);
  });

  test('one project cannot run SSH as another', async ({ api, userFactory }) => {
    const a = await userFactory.createDindUser();
    const b = await userFactory.createDindUser();
    skipUnless(a, 'DinD is not available on this engine.');
    skipUnless(b, 'DinD is not available on this engine.');

    const response = await api.runSshCommandRaw(b.username, {
      command: `touch /tmp/from-${a.username}`,
    });
    await expectStatus(response, 200);

    const leaked = await api.runSshCommand(a.username, {
      command: `test -f /tmp/from-${a.username}`,
    });
    expect(leaked.exit_code).not.toBe(0);
  });
});
