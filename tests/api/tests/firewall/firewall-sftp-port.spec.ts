import { expect, test } from '@/fixtures/test-options';
import {
  ENGINE_SFTP_PORT,
  listFirewallRules,
  requireFirewall,
  restoreFirewallState,
} from '@/helpers/firewall-helpers';
import { rand } from '@/helpers/random';
import { delay } from '@/helpers/retry';
import { sftpLoginAndList } from '@/helpers/sftp-helpers';
import { requireEngineConnectHost } from '@/helpers/engine-host';

/**
 * SFTP does not run on port 22 here, so the engine opens 2222 in the firewall
 * itself, as a managed rule. If it does not, every user loses SFTP the moment
 * the firewall comes up — which is exactly what this test would catch.
 */
test('SFTP on its non-standard port survives the firewall', async ({
  api,
  ftpFactory,
  settings,
  setupUser,
}) => {
  const { enabled } = await requireFirewall(api);

  const rule = (await listFirewallRules(api)).find(
    (entry) => entry.port === ENGINE_SFTP_PORT && entry.action === 'allow' && entry.managed
  );
  expect(rule, `no managed allow rule for port ${ENGINE_SFTP_PORT}`).toBeDefined();
  expect(rule?.protocol).toBe('tcp');
  expect(rule?.source, 'SFTP must be open to every address').toBeNull();

  if (!enabled) {
    await api.enableFirewall();
  }

  try {
    const account = await ftpFactory.createSftpAccountWithPassword(
      setupUser.username,
      `${setupUser.username}_${rand('sftp')}`
    );

    try {
      await delay(settings.timing.propagationDelay);

      expect(
        (await api.listSftpAccounts(setupUser.username)).data.map((entry) => entry.username)
      ).toContain(account.username);

      const listing = await sftpLoginAndList({
        host: await requireEngineConnectHost(api),
        port: settings.ports.sftp,
        username: account.username,
        password: account.password,
      });

      expect(
        Array.isArray(listing),
        `SFTP on port ${settings.ports.sftp} did not answer while the firewall was enabled`
      ).toBe(true);
    } finally {
      await ftpFactory.deleteSftpAccount(setupUser.username, account.username);
    }
  } finally {
    await restoreFirewallState(api, enabled);
  }
});
