import { connect } from 'node:net';
import { expect, test } from '@/fixtures/test-options';
import {
  deleteFirewallRulesQuietly,
  listFirewallRules,
  randomTestNetAddress,
  requireFirewall,
} from '@/helpers/firewall-helpers';
import { firewallLogsResponseSchema, firewallRuleResponseSchema } from '@/schemas';

/** One TCP attempt at the engine's host on a port nothing allows; the answer does not matter. */
function knock(host: string, port: number): Promise<void> {
  return new Promise((resolve) => {
    const socket = connect({ host, port, timeout: 2_000 });
    const done = (): void => {
      socket.destroy();
      resolve();
    };
    socket.once('connect', done).once('timeout', done).once('error', done);
  });
}

test.describe('Firewall log', () => {
  test.beforeEach(async ({ api }) => {
    await requireFirewall(api);
  });

  test('a connection refused by the default policy shows up in the log', async ({
    api,
    settings,
  }) => {
    const host = new URL(settings.apiBaseUrl).hostname;
    // Far from anything the engine opens, and different every run.
    const port = 47_000 + Math.floor(Math.random() * 2_000);

    await expect
      .poll(
        async () => {
          await knock(host, port);
          const { data } = firewallLogsResponseSchema.parse(
            await api.getFirewallLogs({ type: 'blocked', limit: 1000 })
          );
          return data.find((entry) => entry.port === String(port) && entry.direction === 'in');
        },
        {
          // ufw logs blocked packets rate-limited (3 a minute, 10 at once).
          timeout: 90_000,
          intervals: [2_000, 5_000, 10_000],
          message: `no [UFW BLOCK] entry for port ${port}`,
        }
      )
      .toMatchObject({ type: 'blocked', protocol: 'tcp', direction: 'in' });
  });

  test('the log is filtered and its query is checked', async ({ api }) => {
    const { data } = firewallLogsResponseSchema.parse(await api.getFirewallLogs({ limit: 5 }));
    expect(data.length).toBeLessThanOrEqual(5);
    const times = data.map((entry) => Date.parse(entry.time));
    expect(times, 'newest first').toEqual([...times].sort((a, b) => b - a));

    const bans = firewallLogsResponseSchema.parse(await api.getFirewallLogs({ type: 'ban' }));
    expect(bans.data.every((entry) => entry.type === 'ban')).toBe(true);

    const nobody = randomTestNetAddress();
    const filtered = firewallLogsResponseSchema.parse(
      await api.getFirewallLogs({ address: nobody })
    );
    expect(filtered.data.every((entry) => entry.address === nobody)).toBe(true);

    const invalid: Record<string, string>[] = [
      { limit: '0' },
      { limit: '1001' },
      { type: 'allowed' },
      { address: 'example.com' },
    ];
    for (const query of invalid) {
      expect((await api.getFirewallLogsRaw(query)).status, JSON.stringify(query)).toBe(422);
    }
  });
});

test.describe('Firewall rules in both directions', () => {
  const created = new Set<string>();

  test.beforeEach(async ({ api }) => {
    await requireFirewall(api);
  });

  test.afterEach(async ({ api }) => {
    await deleteFirewallRulesQuietly(api, created);
    created.clear();
  });

  test('an address is denied both ways with one rule', async ({ api }) => {
    const source = randomTestNetAddress();

    const { data: rule } = firewallRuleResponseSchema.parse(
      await api.createFirewallRule({
        action: 'deny',
        direction: 'both',
        source,
        comment: 'api-test both',
      })
    );
    created.add(rule.id);
    expect(rule).toMatchObject({ direction: 'both', source, destination: null, editable: true });

    const listed = (await listFirewallRules(api)).filter(
      (r) => r.source === source || r.destination === source
    );
    expect(
      listed.map((r) => r.id),
      'one rule, not its two halves'
    ).toEqual([rule.id]);

    await api.deleteFirewallRule(rule.id);
    created.delete(rule.id);
    const left = (await listFirewallRules(api)).filter(
      (r) => r.source === source || r.destination === source
    );
    expect(left, 'both halves are gone').toEqual([]);
  });

  test('both directions needs the address as source and nothing else', async ({ api }) => {
    for (const body of [
      { action: 'deny', direction: 'both', port: '25', protocol: 'tcp' },
      {
        action: 'deny',
        direction: 'both',
        source: randomTestNetAddress(),
        destination: '192.0.2.1',
      },
    ]) {
      const response = await api.createFirewallRuleRaw(body);
      const id = (response.body as { data?: { id?: unknown } }).data?.id;
      if (typeof id === 'string') {
        created.add(id);
      }
      expect(response.status, JSON.stringify(response.body)).toBe(422);
    }
  });
});
