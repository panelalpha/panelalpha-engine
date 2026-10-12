import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import {
  formatSftpAuthState,
  normalizeSftpAuth,
  type SftpCredentials,
  type SftpSession,
  waitForOpenSftpSession,
  waitForSftpLogin,
  waitForSftpSessionClosed,
} from '@/helpers/sftp-helpers';
import { requireEngineConnectHost } from '@/helpers/engine-host';
import type { HostExec } from '@/helpers/host-exec';
import { rand } from '@/helpers/random';
import { waitForCondition } from '@/helpers/retry';
import { expectStatus } from '@/helpers/expect-status';

/** Poll budget: the old fixed delay, but never less than 15 s. */
const pollTimeout = (settings: { timing: { propagationDelay: number } }) =>
  Math.max(settings.timing.propagationDelay, 15_000);

type Api = Parameters<typeof requireEngineConnectHost>[0];

/** Polls the account listing until the account is (or stops being) present. */
async function waitForSftpAccountListed(
  api: Api,
  username: string,
  account: string,
  listed: boolean,
  timeout: number
): Promise<void> {
  let last = 'no listing read yet';
  await waitForCondition(
    async () => {
      const names = (await api.listSftpAccounts(username)).data.map((entry) => entry.username);
      last = `listing: ${names.join(', ') || '(empty)'}`;
      return names.includes(account) === listed;
    },
    {
      timeout,
      interval: 500,
      message: `${account} was ${listed ? 'never listed' : 'still listed'}`,
      describeLast: () => last,
    }
  );
}

/** Polls the account listing until the account reports the given auth mode. */
async function waitForSftpAuth(
  api: Api,
  username: string,
  account: string,
  auth: 'password' | 'key',
  timeout: number
): Promise<void> {
  let last = 'no listing read yet';
  await waitForCondition(
    async () => {
      const entry = (await api.listSftpAccounts(username)).data.find(
        (candidate) => candidate.username === account
      );
      last = formatSftpAuthState(entry);
      return normalizeSftpAuth(entry) === auth;
    },
    {
      timeout,
      interval: 500,
      message: `${account} never reported ${auth} auth`,
      describeLast: () => last,
    }
  );
}

test.describe('SFTP accounts', () => {
  test('the account list is returned', { tag: ['@smoke'] }, async ({ api, setupUser }) => {
    expect(Array.isArray((await api.listSftpAccounts(setupUser.username)).data)).toBe(true);
  });

  test('a new user has no SFTP accounts', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    expect((await api.listSftpAccounts(user.username)).data).toHaveLength(0);
  });

  test('an account is created, its password changed, then deleted', async ({
    api,
    ftpFactory,
    settings,
    setupUser,
  }) => {
    const created = await ftpFactory.createSftpAccountWithPassword(
      setupUser.username,
      `${setupUser.username}_${rand('sftp')}`
    );
    let pendingCleanup = true;

    try {
      await waitForSftpAccountListed(
        api,
        setupUser.username,
        created.username,
        true,
        pollTimeout(settings)
      );
      expect(
        (await api.listSftpAccounts(setupUser.username)).data.map((entry) => entry.username)
      ).toContain(created.username);

      const newPassword = 'NewSecureSftpPass456!';
      await api.updateSftpAccount(setupUser.username, created.username, {
        auth_method: 'password',
        password: newPassword,
      });

      // The login with the new password is what the test asserts; poll for it.
      const listing = await waitForSftpLogin(
        {
          host: await requireEngineConnectHost(api),
          port: settings.ports.sftp,
          username: created.username,
          password: newPassword,
        },
        { timeout: pollTimeout(settings) }
      );
      expect(Array.isArray(listing)).toBe(true);

      await api.deleteSftpAccount(setupUser.username, created.username);
      pendingCleanup = false;
      await waitForSftpAccountListed(
        api,
        setupUser.username,
        created.username,
        false,
        pollTimeout(settings)
      );

      expect(
        (await api.listSftpAccounts(setupUser.username)).data.map((entry) => entry.username)
      ).not.toContain(created.username);
    } finally {
      if (pendingCleanup) {
        await ftpFactory.deleteSftpAccount(setupUser.username, created.username);
      }
    }
  });
});

test.describe('SFTP public key auth', () => {
  test('a key-auth account is reported as key auth', async ({
    api,
    ftpFactory,
    settings,
    setupUser,
  }) => {
    const created = await ftpFactory.createSftpAccountWithKey(setupUser.username);

    try {
      await waitForSftpAccountListed(
        api,
        setupUser.username,
        created.username,
        true,
        pollTimeout(settings)
      );
      const account = (await api.listSftpAccounts(setupUser.username)).data.find(
        (entry) => entry.username === created.username
      );

      expect(account, 'the key-auth account is missing from the listing').toBeTruthy();
      expect(normalizeSftpAuth(account), formatSftpAuthState(account)).toBe('key');
    } finally {
      await ftpFactory.deleteSftpAccount(setupUser.username, created.username);
    }
  });

  test('an account can be switched from key auth to a password', async ({
    api,
    ftpFactory,
    settings,
    setupUser,
  }) => {
    const created = await ftpFactory.createSftpAccountWithKey(setupUser.username);
    const password = 'UpdatedSftp789!';

    try {
      await waitForSftpAccountListed(
        api,
        setupUser.username,
        created.username,
        true,
        pollTimeout(settings)
      );

      const updated = await api.updateSftpAccount(setupUser.username, created.username, {
        auth_method: 'password',
        password,
      });
      expect(normalizeSftpAuth(updated.data), formatSftpAuthState(updated.data)).toBe('password');

      await waitForSftpAuth(
        api,
        setupUser.username,
        created.username,
        'password',
        pollTimeout(settings)
      );
      const account = (await api.listSftpAccounts(setupUser.username)).data.find(
        (entry) => entry.username === created.username
      );
      expect(normalizeSftpAuth(account), formatSftpAuthState(account)).toBe('password');

      const listing = await waitForSftpLogin(
        {
          host: await requireEngineConnectHost(api),
          port: settings.ports.sftp,
          username: created.username,
          password,
        },
        { timeout: pollTimeout(settings) }
      );
      expect(Array.isArray(listing)).toBe(true);
    } finally {
      await ftpFactory.deleteSftpAccount(setupUser.username, created.username);
    }
  });
});

test.describe('SFTP validation', () => {
  test('an invalid username is refused', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.post(`projects/${setupUser.username}/sftp-accounts`, {
      data: {
        username: 'invalid@user!name',
        auth_method: 'password',
        password: 'TestPassword123!',
      },
    });
    expectOneOf(response.status(), [400, 405, 422]);
  });

  test('public_key auth without a key is refused', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.post(`projects/${setupUser.username}/sftp-accounts`, {
      data: { username: `${setupUser.username}_${rand('sftp')}`, auth_method: 'public_key' },
    });
    expectOneOf(response.status(), [400, 422]);
  });

  const malformedKeys = [
    ['plain text', 'not-a-key'],
    ['a private key', '-----BEGIN RSA PRIVATE KEY-----\nnot-valid'],
    ['digits', '12345'],
  ] as const;

  for (const [label, key] of malformedKeys) {
    // 201 is tolerated here because current engines do not validate the key
    // format on create. Tighten this to [400, 422] once they do — the wide
    // range is a known gap, not the intended contract.
    test(`a public key that is ${label} does not break the endpoint`, async ({
      api,
      authedRequest,
      setupUser,
    }) => {
      const username = `${setupUser.username}_${rand('sftp')}`;

      const response = await authedRequest.post(`projects/${setupUser.username}/sftp-accounts`, {
        data: { username, auth_method: 'public_key', public_key: key },
      });

      try {
        expectOneOf(response.status(), [201, 400, 422]);
      } finally {
        if (response.status() === 201) {
          await api.deleteSftpAccount(setupUser.username, username);
        }
      }
    });
  }

  test('updating an unknown account returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.put(
      `projects/${setupUser.username}/sftp-accounts/nonexistent_xyz`,
      { data: { auth_method: 'password', password: 'TestPass123!' } }
    );
    await expectStatus(response, 404);
  });

  test('deleting an unknown account returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.delete(
      `projects/${setupUser.username}/sftp-accounts/nonexistent_xyz`
    );
    await expectStatus(response, 404);
  });

  test('creating an account past the limit is refused', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const current = (await api.listSftpAccounts(setupUser.username)).data.length;
    await api.updateUser(setupUser.username, { sftp_accounts_limit: current });

    try {
      const response = await authedRequest.post(`projects/${setupUser.username}/sftp-accounts`, {
        data: {
          username: `${setupUser.username}_limittest${Date.now()}`,
          auth_method: 'password',
          password: 'LimitTest123!',
        },
      });
      expectOneOf(response.status(), [400, 422]);
    } finally {
      await api.updateUser(setupUser.username, { sftp_accounts_limit: null });
    }
  });
});

/** The credentials the SFTP daemon accepts for one account. */
function sftpCredentials(
  host: string,
  port: number,
  account: { username: string; password: string }
): SftpCredentials {
  return { host, port, username: account.username, password: account.password };
}

/** The running sftp container, as the host's compose labels name it. */
async function sftpContainer(hostExec: HostExec): Promise<string | null> {
  const { exitCode, stdout } = await hostExec.run('docker', [
    'ps',
    '--filter',
    'label=com.docker.compose.service=sftp',
    '--format',
    '{{.Names}}',
  ]);
  const name = stdout.trim().split('\n')[0]?.trim();
  return exitCode === 0 && name ? name : null;
}

test.describe('SFTP session kill', () => {
  test('kill-sessions ends only the named account sessions', async ({
    api,
    ftpFactory,
    settings,
    userFactory,
  }) => {
    const timeout = pollTimeout(settings);
    const user = await userFactory.createSimpleUser();
    const host = await requireEngineConnectHost(api);
    const port = settings.ports.sftp;
    const accountA = await ftpFactory.createSftpAccountWithPassword(user.username);
    const accountB = await ftpFactory.createSftpAccountWithPassword(user.username);
    const sessions: SftpSession[] = [];

    try {
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountA), { timeout })
      );
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountA), { timeout })
      );
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountB), { timeout })
      );

      const ended = await api.killSftpSessions(user.username, accountA.username);
      expect(ended.data.killed, 'both sessions of the named account were open').toBe(2);

      await waitForSftpSessionClosed(sessions[0], { timeout });
      await waitForSftpSessionClosed(sessions[1], { timeout });
      expect(
        Array.isArray(await sessions[2].list()),
        "the other account's session is still open"
      ).toBe(true);

      const repeated = await api.killSftpSessions(user.username, accountA.username);
      expect(repeated.data.killed, 'a session already ended is not counted again').toBe(0);
    } finally {
      await Promise.all(sessions.map((session) => session.close()));
    }
  });

  test('the project-wide call ends every session of the project', async ({
    api,
    ftpFactory,
    settings,
    userFactory,
  }) => {
    const timeout = pollTimeout(settings);
    const user = await userFactory.createSimpleUser();
    const host = await requireEngineConnectHost(api);
    const port = settings.ports.sftp;
    const accountA = await ftpFactory.createSftpAccountWithPassword(user.username);
    const accountB = await ftpFactory.createSftpAccountWithPassword(user.username);
    const sessions: SftpSession[] = [];

    try {
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountA), { timeout })
      );
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountA), { timeout })
      );
      sessions.push(
        await waitForOpenSftpSession(sftpCredentials(host, port, accountB), { timeout })
      );

      const ended = await api.killAllSftpSessions(user.username);
      expect(ended.data.killed, 'every open session of the project is counted').toBe(3);

      await Promise.all(sessions.map((session) => waitForSftpSessionClosed(session, { timeout })));
    } finally {
      await Promise.all(sessions.map((session) => session.close()));
    }
  });

  test('deleting an account ends its session and removes the login', async ({
    api,
    ftpFactory,
    settings,
    userFactory,
    hostExec,
  }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
    const exec = hostExec!;
    const container = await sftpContainer(exec);
    test.skip(!container, 'the sftp container was not found on the engine host.');
    const containerName = container!;

    const timeout = pollTimeout(settings);
    const user = await userFactory.createSimpleUser();
    const host = await requireEngineConnectHost(api);
    const account = await ftpFactory.createSftpAccountWithPassword(user.username);
    const session = await waitForOpenSftpSession(
      sftpCredentials(host, settings.ports.sftp, account),
      { timeout }
    );

    try {
      await api.deleteSftpAccount(user.username, account.username);

      await waitForSftpSessionClosed(session, { timeout });
      await waitForCondition(
        async () =>
          (await exec.run('docker', ['exec', containerName, 'id', account.username])).exitCode !==
          0,
        {
          timeout,
          interval: 500,
          message: `${account.username} still exists in the sftp container`,
        }
      );
    } finally {
      await session.close();
    }
  });

  test('kill-sessions on an unknown account or project is a 404', async ({
    authedRequest,
    setupUser,
  }) => {
    await expectStatus(
      await authedRequest.post(
        `projects/${setupUser.username}/sftp-accounts/nonexistent_xyz/kill-sessions`
      ),
      404
    );
    await expectStatus(
      await authedRequest.post('projects/nonexistent_xyz/sftp-accounts/kill-sessions'),
      404
    );
  });
});
