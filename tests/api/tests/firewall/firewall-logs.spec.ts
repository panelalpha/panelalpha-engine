import { lookup } from 'node:dns/promises';
import { networkInterfaces } from 'node:os';
import { expect, test } from '@/fixtures/test-options';
import {
  deleteFirewallRulesQuietly,
  listFirewallRules,
  randomTestNetAddress,
  readFirewallStatus,
  requireFirewall,
} from '@/helpers/firewall-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { testNetwork } from '@/helpers/test-network';
import {
  knock,
  ruleAcceptingEveryPortFrom,
  runnerAddressSeenByHost,
} from '@/helpers/firewall-knock';
import { resolveHostExec } from '@/helpers/host-exec';
import { firewallLogsResponseSchema, firewallRuleResponseSchema } from '@/schemas';

/** Whether `host` is this runner's own machine, where a knock never crosses the firewall. */
async function isThisMachine(host: string): Promise<boolean> {
  const { address } = await lookup(host);
  const own = Object.values(networkInterfaces())
    .flat()
    .map((nic) => nic?.address);
  return address.startsWith('127.') || address === '::1' || own.includes(address);
}

/**
 * Why this runner's knock can never reach ufw's default policy, or null when it
 * can. A host that answers the knock let it in; one that accepts every port
 * from the runner's address (an admin-access rule, a ufw allow for it) does the
 * same even where the answer is lost on the way back.
 */
async function whyKnockIsNeverRefused(
  host: string,
  port: number,
  defaultIncoming: string | null
): Promise<string | null> {
  const outcome = await knock(host, port);
  if (defaultIncoming === 'deny' && (outcome === 'refused' || outcome === 'connected')) {
    return `${host} answered a knock on port ${port} (${outcome}), so a rule accepts this runner before ufw's default deny and nothing is logged. Run it from an address the host does not allow.`;
  }
  const hostExec = await resolveHostExec();
  const runner = hostExec?.mode === 'ssh' ? await runnerAddressSeenByHost(hostExec) : null;
  const rule = hostExec && runner ? await ruleAcceptingEveryPortFrom(hostExec, runner) : null;
  return rule
    ? `${host} sees this runner as ${runner}, which it accepts on every port before ufw's default deny (${rule}), so nothing is logged. Run it from an address the host does not allow.`
    : null;
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
    test.skip(
      await isThisMachine(host),
      `${host} is this runner's own address: the knock goes over lo, which ufw accepts without logging. Run it from another machine.`
    );
    // Far from anything the engine opens, and different every run.
    const port = 47_000 + Math.floor(Math.random() * 2_000);
    const { default_incoming: defaultIncoming } = await readFirewallStatus(api);
    const neverRefused = await whyKnockIsNeverRefused(host, port, defaultIncoming);
    test.skip(neverRefused !== null, neverRefused ?? '');

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
      await expectStatus(await api.getFirewallLogsRaw(query), 422, JSON.stringify(query));
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
        destination: testNetwork.public3(1),
      },
    ]) {
      const response = await api.createFirewallRuleRaw(body);
      const id = (response.body as { data?: { id?: unknown } }).data?.id;
      if (typeof id === 'string') {
        created.add(id);
      }
      await expectStatus(response, 422, JSON.stringify(response.body));
    }
  });
});
