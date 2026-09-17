import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { ftpAccountListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import {
  CHATTY_BANNER_PATTERN,
  QUOTA_TEST_SIZE_MB,
  UPDATED_FTP_PASSWORD,
  bannerLines,
  ftpLoginAndList,
  ftpLoginSucceeds,
  withFtpClient,
} from '@/helpers/ftp-helpers';
import { rand } from '@/helpers/random';
import { delay, waitForCondition } from '@/helpers/retry';
import { requireEngineConnectHost } from '@/helpers/engine-host';

test.describe('FTP accounts', () => {
  test('the account list is returned', async ({ api, setupUser }) => {
    const listing = await api.listFtpAccounts(setupUser.username);
    validateParsedApiResponse(listing, ftpAccountListSchema);
  });

  test('a new user has no FTP accounts', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    expect((await api.listFtpAccounts(user.username)).data).toHaveLength(0);
  });

  /**
   * Goes all the way to the wire: the account has to be usable over FTP, a new
   * password has to take effect, and the old one has to stop working.
   */
  test('an account is created, connectable, updatable and deletable', async ({
    api,
    ftpFactory,
    settings,
    setupUser,
  }) => {
    const created = await ftpFactory.createFtpAccount(
      setupUser.username,
      setupUser.domain,
      rand('ftp')
    );
    const account = created.user;
    const host = await requireEngineConnectHost(api);
    let pendingCleanup = true;

    try {
      await waitForCondition(
        async () =>
          (await api.listFtpAccounts(setupUser.username)).data.some(
            (candidate) => candidate.user === account
          ),
        { timeout: settings.timing.propagationDelay, interval: 500 }
      );

      await test.step('the original credentials work', async () => {
        await ftpLoginAndList({
          host,
          user: account,
          password: created.password,
        });
      });

      await api.updateFtpAccount(setupUser.username, account, {
        password: UPDATED_FTP_PASSWORD,
        unlimited_quota: true,
      });
      await delay(settings.timing.propagationDelay);

      await test.step('the new password works and the old one does not', async () => {
        await ftpLoginAndList({
          host,
          user: account,
          password: UPDATED_FTP_PASSWORD,
        });

        expect(
          await ftpLoginSucceeds({
            host,
            user: account,
            password: created.password,
          }),
          'the replaced password still logs in'
        ).toBe(false);
      });

      await test.step('the quota can be limited and lifted again', async () => {
        await api.updateFtpAccount(setupUser.username, account, {
          unlimited_quota: false,
          quota: QUOTA_TEST_SIZE_MB,
        });
        await delay(settings.timing.propagationDelay);

        await api.updateFtpAccount(setupUser.username, account, { unlimited_quota: true });
        await delay(settings.timing.propagationDelay);
      });

      await api.deleteFtpAccount(setupUser.username, account);
      pendingCleanup = false;
      expect(
        (await api.listFtpAccounts(setupUser.username)).data.map((entry) => entry.user)
      ).not.toContain(account);
    } finally {
      if (pendingCleanup) {
        await ftpFactory.deleteFtpAccount(setupUser.username, account);
      }
    }
  });
});

/**
 * pure-ftpd's default greeting advertises the server software, the local time
 * and the connected user count. The engine trims it, and these tests hold that
 * line — a verbose banner hands a scanner the server version for free.
 */
test.describe('FTP login banner', () => {
  test('the greeting is short and says nothing about the server', async ({
    api,
    ftpFactory,
    setupUser,
  }) => {
    const account = await ftpFactory.createFtpAccount(setupUser.username, setupUser.domain);
    const host = await requireEngineConnectHost(api);

    try {
      await withFtpClient(async (client) => {
        const welcome = await client.access({
          host,
          user: account.user,
          password: account.password,
          secure: false,
        });

        const lines = bannerLines(welcome.message);
        expect(welcome.code).toBe(220);
        expect(lines.length).toBeLessThanOrEqual(2);
        expect(lines.join('\n')).not.toMatch(CHATTY_BANNER_PATTERN);
      });
    } finally {
      await ftpFactory.deleteFtpAccount(setupUser.username, account.user);
    }
  });

  test('USER is answered with a plain password prompt', async ({
    api,
    ftpFactory,
    settings,
    setupUser,
  }) => {
    const account = await ftpFactory.createFtpAccount(setupUser.username, setupUser.domain);
    const host = await requireEngineConnectHost(api);

    try {
      await withFtpClient(async (client) => {
        const welcome = await client.connect(host, settings.ports.ftp);
        expect(welcome.code).toBe(220);
        expect(bannerLines(welcome.message).length).toBeLessThanOrEqual(2);

        const prompt = await client.send(`USER ${account.user}`);
        expect(prompt.code).toBe(331);
        expect(prompt.message).toMatch(/user .* ok\. password required/i);
      });
    } finally {
      await ftpFactory.deleteFtpAccount(setupUser.username, account.user);
    }
  });
});

test.describe('FTP validation', () => {
  test('a negative quota is refused', async ({ authedRequest, ftpFactory, setupUser }) => {
    const account = await ftpFactory.createFtpAccount(setupUser.username, setupUser.domain);

    try {
      const response = await authedRequest.put(
        `projects/${setupUser.username}/ftp-accounts/${account.user}`,
        { data: { unlimited_quota: false, quota: -1 } }
      );
      expectOneOf(response.status(), [400, 422]);
    } finally {
      await ftpFactory.deleteFtpAccount(setupUser.username, account.user);
    }
  });

  test('updating an unknown account returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.put(
      `projects/${setupUser.username}/ftp-accounts/nonexistent_xyz`,
      { data: { unlimited_quota: false, quota: 100 } }
    );
    expect(response.status()).toBe(404);
  });

  test('deleting an unknown account returns 404', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.delete(
      `projects/${setupUser.username}/ftp-accounts/nonexistent_xyz`
    );
    expect(response.status()).toBe(404);
  });

  test('creating an account past the limit is refused', async ({
    api,
    authedRequest,
    setupUser,
  }) => {
    const current = (await api.listFtpAccounts(setupUser.username)).data.length;
    await api.updateUser(setupUser.username, { ftp_accounts_limit: current });

    try {
      const response = await authedRequest.post(`projects/${setupUser.username}/ftp-accounts`, {
        data: {
          user: `limittest${Date.now()}`,
          domain: setupUser.domain,
          password: 'LimitTest123!',
        },
      });
      expectOneOf(response.status(), [400, 422]);
    } finally {
      await api.updateUser(setupUser.username, { ftp_accounts_limit: null });
    }
  });
});
