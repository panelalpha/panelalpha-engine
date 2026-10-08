import { expect, test, type APIRequestContext } from '@/fixtures/test-options';
import { redactAndTruncate } from '@/helpers/redact';
import { getUserUidGid } from '@/helpers/uid-helpers';
import { wpCliUnavailableReason, wpPath, type WpCliResult } from '@/helpers/wpcli-helpers';
import { expectStatus } from '@/helpers/expect-status';

/**
 * The WP-CLI endpoint runs any wp subcommand as the project's own UID/GID, so the
 * tests pin that identity and the absence of a shell rather than an allowlist.
 */
test.describe('WP-CLI argument validation', () => {
  test.beforeEach(async ({ api, setupUser }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');
  });

  test('an empty argument list is refused', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.post(`projects/${setupUser.username}/wp-cli/command`, {
      data: { args: [] },
    });
    await expectStatus(response, 422);
  });

  // The contract is "any wp subcommand, as the project's UID/GID, through argv and no shell":
  // the project owner can already run any PHP, so isolation is the project UID, not an
  // argument allowlist (PhpRuntime.php:68-95, HostProcess.php:145-153). The 200 wraps the
  // exit_code even for a failing command (WpCliController.php:87).
  const run = async (
    authedRequest: APIRequestContext,
    username: string,
    args: string[]
  ): Promise<WpCliResult> => {
    const response = await authedRequest.post(`projects/${username}/wp-cli/command`, {
      data: { args },
    });
    await expectStatus(response, 200);
    return (await response.json()) as WpCliResult;
  };

  test(
    'a PHP eval runs as the project user',
    { tag: ['@security'] },
    async ({ api, authedRequest, setupUser }) => {
      const project = await getUserUidGid(api, setupUser.username);
      expect(project.uid, 'the project runs as root').toBeGreaterThan(0);

      // /proc/self/status instead of posix_*: the extension is not guaranteed in the runtime image.
      const code =
        'preg_match("/^Uid:\\s+\\d+\\s+(\\d+)/m", file_get_contents("/proc/self/status"), $u);' +
        'preg_match("/^Gid:\\s+\\d+\\s+(\\d+)/m", file_get_contents("/proc/self/status"), $g);' +
        'echo $u[1].":".$g[1];';
      const result = await run(authedRequest, setupUser.username, [
        'eval',
        code,
        wpPath(setupUser),
      ]);

      expect(result.exit_code, redactAndTruncate(result.stderr, 500)).toBe(0);
      expect(result.stdout.trim()).toBe(`${project.uid}:${project.gid}`);
    }
  );

  test(
    'the shell subcommand gets no host shell',
    { tag: ['@security'] },
    async ({ authedRequest, setupUser }) => {
      const result = await run(authedRequest, setupUser.username, [
        'shell',
        'whoami',
        wpPath(setupUser),
      ]);

      // Without a TTY or stdin, `wp shell` has nothing to read, so whoami is never executed.
      expect(typeof result.exit_code).toBe('number');
      expect(result.stdout, 'a host shell answered').not.toMatch(/root/);
    }
  );

  test(
    'a shell metacharacter is passed as a literal argument',
    { tag: ['@security'] },
    async ({ authedRequest, setupUser }) => {
      const result = await run(authedRequest, setupUser.username, [
        '; rm -rf /',
        wpPath(setupUser),
      ]);

      expect(result.exit_code, 'the literal was not run as a wp command').not.toBe(0);
      expect(result.stderr).toMatch(/is not a registered wp command/);
    }
  );
});
