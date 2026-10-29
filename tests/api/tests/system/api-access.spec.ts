import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import { waitForCondition } from '@/helpers/retry';
import {
  appEnv,
  engineApiStatus,
  execInAccount,
  expectAppCarriesApiAccess,
} from '@/helpers/api-access-helpers';
import type { HostExec } from '@/helpers/host-exec';
import { apiAccessListResponseSchema } from '@/schemas';

/** The engine's own API base URL, as it reports it, without a trailing slash. */
async function engineApiUrl(api: {
  getSystemInfo: () => Promise<{ data: { api_url?: string | null } }>;
}): Promise<string> {
  const info = await api.getSystemInfo();
  const url = info.data.api_url?.replace(/\/+$/, '');
  expect(url, 'the engine reports its API URL').toBeTruthy();

  return url!;
}

/** The image the application's container runs, so a rotation can be shown to rebuild nothing. */
async function appImage(hostExec: HostExec, account: string): Promise<string> {
  const result = await execInAccount(
    hostExec,
    account,
    'docker inspect --format "{{.Image}}" project-app-1'
  );
  expect(result.exitCode, result.stderr || result.stdout).toBe(0);

  return result.stdout.trim();
}

/** Asks the engine port from the account's own position and fails when the firewall refuses it. */
async function expectPortRefused(hostExec: HostExec, account: string, url: string): Promise<void> {
  const refused = await execInAccount(
    hostExec,
    account,
    `curl -sS -o /dev/null --max-time 8 -w "%{http_code}" ${url}/system/info`
  );

  expect(refused.exitCode, `curl answered ${refused.stdout}${refused.stderr}`).not.toBe(0);
  expect(refused.stderr, 'the tenant firewall refuses the connection').toMatch(
    /refused|route to host|timed out/i
  );
}

/** Waits until the application holds (or no longer holds) an API token. */
async function waitForAppToken(
  hostExec: HostExec,
  account: string,
  present: boolean,
  timeout: number,
  differentFrom?: string
): Promise<string | null> {
  let last: string | null = null;
  await waitForCondition(
    async () => {
      last = await appEnv(hostExec, account, 'PANELALPHA_ENGINE_TOKEN');
      if (!present) {
        return last === null;
      }
      return last !== null && (differentFrom === undefined || last !== differentFrom);
    },
    {
      timeout,
      interval: 2_000,
      message: `the application never ${present ? 'took' : 'lost'} its API token`,
      describeLast: () => `PANELALPHA_ENGINE_TOKEN=${last === null ? '(unset)' : 'set'}`,
    }
  );

  return last;
}

test.describe('engine API access', () => {
  test(
    'an allowed project reaches the API and another one does not',
    { tag: ['@slow'] },
    async ({ api, hostExec, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 3);
      skipUnless(hostExec, 'the account containers are not reachable from this runner.');

      const app = await userFactory.createDeployedUser({
        git_repo: DEFAULT_DEPLOY_GIT_REPO,
        git_branch: 'main',
        tunnel: 'none',
      });
      skipUnless(app, 'DinD is not available on this engine.');
      const other = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(other, 'DinD is not available on this engine.');

      const url = await engineApiUrl(api);
      const image = await appImage(hostExec, app.username);

      await test.step('nothing is handed over, and the port is out of reach', async () => {
        expect(
          await appEnv(hostExec, app.username, 'PANELALPHA_ENGINE_URL'),
          'an unallowed project is handed no address'
        ).toBeNull();
        await expectPortRefused(hostExec, other.username, url);
      });

      await test.step('allowing the project hands its application the address and a token', async () => {
        const allowed = await api.allowApiAccess(app.username, { scope: 'self' });
        expect(allowed.data.project, 'the entry names the project').toBe(app.username);
        expect(allowed.data.scope, 'the scope is what was asked for').toBe('self');
        expect(Object.keys(allowed.data), 'the token itself is never answered').not.toContain(
          'token'
        );

        const listed = await api.listApiAccess();
        expect(
          apiAccessListResponseSchema.safeParse(listed).success,
          'the listing matches its schema'
        ).toBe(true);

        await waitForAppToken(hostExec, app.username, true, Timeouts.deploy);
        await expectAppCarriesApiAccess(hostExec, app.username);
      });

      await test.step('the application reaches the API and answers its own project', async () => {
        expect(
          await engineApiStatus(hostExec, app.username, '/system/info'),
          'GET /system/info'
        ).toBe('200');
        expect(
          await engineApiStatus(hostExec, app.username, `/projects/${app.username}`),
          'GET its own project'
        ).toBe('200');
      });

      await test.step('its token is limited to itself, and another project stays out', async () => {
        expect(
          await engineApiStatus(hostExec, app.username, `/projects/${other.username}`),
          'a self-scoped token is refused another project'
        ).toBe('403');
        await expectPortRefused(hostExec, other.username, url);
      });

      await test.step('a rotation replaces the token in the running application, without a build', async () => {
        const before = await appEnv(hostExec, app.username, 'PANELALPHA_ENGINE_TOKEN');
        const rotated = await api.rotateApiAccess(app.username);
        expect(rotated.task_id, 'the apply task is named').toBeGreaterThan(0);
        expect(
          rotated.data.pending_revoke_token_id,
          'the token being replaced is tracked until the apply succeeds'
        ).toBeTruthy();

        const after = await waitForAppToken(
          hostExec,
          app.username,
          true,
          Timeouts.deploy,
          before ?? undefined
        );
        expect(after, 'the application runs on a new token').not.toBe(before);
        expect(await appImage(hostExec, app.username), 'the image was not rebuilt').toBe(image);
        expect(
          await engineApiStatus(hostExec, app.username, '/system/info'),
          'the new token works'
        ).toBe('200');

        await waitForCondition(
          async () => (await api.listApiAccess()).data[0]?.pending_revoke_token_id === null,
          {
            timeout: Timeouts.deploy,
            interval: 2_000,
            message: 'the replaced token was never retired',
            describeLast: () => 'pending_revoke_token_id is still set',
          }
        );
      });

      await test.step('deny takes the values back out and closes the port', async () => {
        const denied = await api.denyApiAccess(app.username);
        expect(denied.data.removed, 'the allowance was there to remove').toBe(true);

        await waitForAppToken(hostExec, app.username, false, Timeouts.deploy);
        expect(await appEnv(hostExec, app.username, 'PANELALPHA_ENGINE_URL')).toBeNull();
        expect(await appEnv(hostExec, app.username, 'PANELALPHA_ENGINE_CA')).toBeNull();
        await expectPortRefused(hostExec, app.username, url);
      });
    }
  );

  test(
    'a scope naming a project that does not exist is refused',
    { tag: ['@slow'] },
    async ({ api, hostExec, userFactory }) => {
      skipUnless(hostExec, 'the account containers are not reachable from this runner.');
      const user = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(user, 'DinD is not available on this engine.');

      const response = await api.allowApiAccessRaw(user.username, { scope: ['nosuchproject999'] });

      await expectStatus(response, 422);
      const body = response.body as { problems?: { field?: string; code?: string }[] };
      expect(body.problems?.[0]?.field, 'the refusal is about the scope').toBe('scope');
      expect(body.problems?.[0]?.code, 'and carries its code').toBe('api_access_project_unknown');
    }
  );

  /**
   * A project created later under a deleted project's name is someone else's, so the
   * name leaves every allowance that listed it, and a rotation does not grant it again.
   */
  test(
    "a deleted project leaves every other project's scope",
    { tag: ['@slow'] },
    async ({ api, userFactory }) => {
      const keeper = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(keeper, 'DinD is not available on this engine.');
      const gone = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(gone, 'DinD is not available on this engine.');

      await api.allowApiAccess(keeper.username, { scope: [keeper.username, gone.username] });
      await userFactory.deleteUser(gone.username);

      const listed = (await api.listApiAccess()).data.find(
        (entry) => entry.project === keeper.username
      );
      expect(listed?.scope, 'the deleted project left the scope').toEqual([keeper.username]);

      const rotated = await api.rotateApiAccess(keeper.username);
      expect(rotated.data.scope, 'a rotation does not grant it again').toEqual([keeper.username]);
    }
  );

  /**
   * A classic project has no container to receive the address, the certificate or the
   * token, and no address on the tenant network to admit, so allowing it would read as
   * done and change nothing.
   */
  test('a classic project cannot be allowed', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser({ tunnel: 'none' });

    const allowed = await api.allowApiAccessRaw(user.username, { scope: 'self' });
    await expectStatus(allowed, 422);
    const body = allowed.body as { problems?: { field?: string; code?: string }[] };
    expect(body.problems?.[0]?.field, 'the refusal is about the project').toBe('project');
    expect(body.problems?.[0]?.code, 'and carries its code').toBe('api_access_container_only');

    await expectStatus(await api.rotateApiAccessRaw(user.username), 422);
  });
});
