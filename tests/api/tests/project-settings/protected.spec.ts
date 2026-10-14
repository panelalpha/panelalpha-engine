import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

const PROTECTED_KEY = 'protected';

/**
 * The `protected` project setting is the operator's: it is set from the host,
 * read-only over the API, and while it is on an API token may not delete, stop
 * or redeploy the project.
 */
test.describe('protected projects', () => {
  test.beforeEach(({ hostExec }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
  });

  test('the API may not change the setting nor delete the project', async ({
    api,
    userFactory,
    hostExec,
  }) => {
    const exec = hostExec!;
    const user = await userFactory.createSimpleUser();

    const set = await exec.pae([
      'project:settings:set',
      PROTECTED_KEY,
      'on',
      `--project=${user.username}`,
      '--force',
    ]);
    expect(set.exitCode, `pae project:settings:set failed: ${set.stderr}`).toBe(0);

    try {
      const read = await api.getUser(user.username);
      expect(read.data.protected).toBe(true);

      const refused = await api.deleteUserRaw(user.username);
      await expectStatus(refused, 409);
      expect((refused.body as { code?: string }).code).toBe('project_protected');

      const stillThere = await api.getUserRaw(user.username);
      await expectStatus(stillThere, 200);

      const write = await api.setProjectSettingRaw(user.username, PROTECTED_KEY, { value: 'on' });
      await expectStatus(write, 403);

      const clear = await api.deleteProjectSettingRaw(user.username, PROTECTED_KEY);
      await expectStatus(clear, 403);
    } finally {
      await exec.pae([
        'project:settings:unset',
        PROTECTED_KEY,
        `--project=${user.username}`,
        '--force',
      ]);
      await api.deleteUserSafe(user.username);
    }
  });
});
