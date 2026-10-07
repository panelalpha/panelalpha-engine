import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import {
  ENGINE_API_PORT,
  ENGINE_SFTP_PORT,
  FIREWALL_UNAVAILABLE,
  deleteFirewallRulesQuietly,
  listFirewallRules,
  randomTestNet,
  randomTestNetAddress,
  randomTestPort,
  readFirewallStatus,
  requireFirewall,
  restoreFirewallState,
} from '@/helpers/firewall-helpers';
import { firewallRuleResponseSchema } from '@/schemas';
import type { FirewallRule } from '@/types';

const RULE_COMMENT = 'api-test rule';
const UPDATED_RULE_COMMENT = 'api-test rule, updated';

/** An id no rule has: ids are 12 hex characters derived from the match. */
const UNKNOWN_RULE_ID = '000000000000';

/**
 * Every rule these specs add matches only a documentation range (RFC 5737), or
 * outbound traffic the default policy already allows, so neither a rule that
 * stays behind nor a validation that fails to fire can lock the runner out.
 */

async function listedIds(api: EngineApi): Promise<string[]> {
  return (await listFirewallRules(api)).map((rule) => rule.id);
}

async function createRule(
  api: EngineApi,
  created: Set<string>,
  rule: Parameters<EngineApi['createFirewallRule']>[0]
): Promise<FirewallRule> {
  const { data } = firewallRuleResponseSchema.parse(await api.createFirewallRule(rule));
  created.add(data.id);
  return data;
}

test.describe('Firewall', () => {
  test('the status names the provider and its state', { tag: ['@smoke'] }, async ({ api }) => {
    const status = await readFirewallStatus(api);
    expect(status.provider).toBe('ufw');
    test.skip(status.enabled === null, FIREWALL_UNAVAILABLE);

    expect(status.error).toBeNull();
    expect(status.version).toBeTruthy();
    expect(status.default_incoming).toBeTruthy();
    expect(status.default_outgoing).toBeTruthy();
  });

  test('the rules listing has unique ids', async ({ api }) => {
    await requireFirewall(api);
    const ids = await listedIds(api);
    expect(new Set(ids).size, 'two listed rules share an id').toBe(ids.length);
  });

  test('a reload keeps the firewall state and its rules', async ({ api }) => {
    const before = await requireFirewall(api);
    const rulesBefore = await listedIds(api);

    await api.reloadFirewall();

    expect((await readFirewallStatus(api)).enabled).toBe(before.enabled);
    expect((await listedIds(api)).sort()).toEqual(rulesBefore.sort());
  });
});

test.describe('Firewall rules', () => {
  const created = new Set<string>();

  test.beforeEach(async ({ api }) => {
    await requireFirewall(api);
  });

  test.afterEach(async ({ api }) => {
    await deleteFirewallRulesQuietly(api, created);
    created.clear();
  });

  test('an allow rule is created, edited and deleted', async ({ api }) => {
    const source = randomTestNetAddress();
    const port = randomTestPort();

    const rule = await createRule(api, created, {
      action: 'allow',
      protocol: 'tcp',
      port,
      source: `${source}/32`,
      comment: RULE_COMMENT,
    });
    expect(rule).toMatchObject({
      action: 'allow',
      direction: 'in',
      protocol: 'tcp',
      port,
      // A /32 is stored as the bare address, so it is found again under the same id.
      source,
      destination: null,
      comment: RULE_COMMENT,
      managed: false,
      editable: true,
    });
    expect(await listedIds(api)).toContain(rule.id);

    // The comment is not part of the match, so the id survives the edit.
    const renamed = (await api.updateFirewallRule(rule.id, { comment: UPDATED_RULE_COMMENT })).data;
    expect(renamed.id).toBe(rule.id);
    expect(renamed.comment).toBe(UPDATED_RULE_COMMENT);
    expect((await listFirewallRules(api)).find((r) => r.id === rule.id)?.comment).toBe(
      UPDATED_RULE_COMMENT
    );

    // The port is, so the rule comes back under a new id and the old one is gone.
    const newPort = String(Number(port) + 1);
    const moved = (await api.updateFirewallRule(rule.id, { port: newPort })).data;
    created.add(moved.id);
    expect(moved.id).not.toBe(rule.id);
    expect(moved).toMatchObject({
      port: newPort,
      protocol: 'tcp',
      source,
      comment: UPDATED_RULE_COMMENT,
    });
    const afterMove = await listedIds(api);
    expect(afterMove).toContain(moved.id);
    expect(afterMove, 'the pre-edit rule is still listed').not.toContain(rule.id);

    // Clearing everything the rule matches on would make it match all traffic.
    const cleared = await api.updateFirewallRuleRaw(moved.id, { port: null, source: null });
    expect(cleared.status).toBe(422);
    expect(await listedIds(api)).toContain(moved.id);

    const deleted = (await api.deleteFirewallRule(moved.id)).data;
    expect(deleted.id).toBe(moved.id);
    expect(await listedIds(api)).not.toContain(moved.id);

    expect((await api.deleteFirewallRuleRaw(moved.id)).status).toBe(404);
    expect((await api.updateFirewallRuleRaw(moved.id, { comment: RULE_COMMENT })).status).toBe(404);
  });

  test('a deny rule is placed above the allow rules', async ({ api }) => {
    const allow = await createRule(api, created, {
      action: 'allow',
      protocol: 'tcp',
      port: randomTestPort(),
      source: randomTestNetAddress(),
      comment: RULE_COMMENT,
    });
    const net = randomTestNet();
    const deny = await createRule(api, created, {
      action: 'deny',
      source: `${net}.77/24`,
      comment: RULE_COMMENT,
    });
    // Host bits are dropped from a CIDR, the way the firewall stores it.
    expect(deny).toMatchObject({ action: 'deny', source: `${net}.0/24`, port: null });

    const rules = await listFirewallRules(api);
    const denyAt = rules.findIndex((rule) => rule.id === deny.id);
    const firstAllowAt = rules.findIndex((rule) => rule.action === 'allow');
    expect(denyAt, 'the deny rule is not listed').toBeGreaterThanOrEqual(0);
    expect(rules.map((rule) => rule.id)).toContain(allow.id);
    expect(denyAt, 'a deny rule below an allow rule never matches').toBeLessThan(firstAllowAt);
  });

  test('rules the API cannot write safely are refused', async ({ api }) => {
    const port = randomTestPort();
    const source = randomTestNetAddress();
    const cases: [string, Record<string, unknown>][] = [
      // Outbound, so even an accepted rule matches only what the default allows.
      ['no port, source or destination', { action: 'allow', direction: 'out' }],
      ['a port range without a protocol', { action: 'allow', port: '40000:40009', source }],
      ['a port list without a protocol', { action: 'allow', port: '40000,40001', source }],
      [
        'an address that is not one',
        { action: 'allow', protocol: 'tcp', port, source: '192.0.2.300' },
      ],
      [
        'a hostname as an address',
        { action: 'allow', protocol: 'tcp', port, destination: 'example.com' },
      ],
      [
        'the comment prefix of managed rules',
        { action: 'allow', protocol: 'tcp', port, source, comment: 'panelalpha: api-test' },
      ],
      ['an unknown action', { action: 'reject', protocol: 'tcp', port, source }],
      ['no action', { protocol: 'tcp', port, source }],
      // Valid to the API, refused by ufw itself: its own words come back as a 422.
      ['addresses of two IP versions', { action: 'deny', source, destination: '2001:db8::1' }],
    ];

    for (const [name, body] of cases) {
      await test.step(name, async () => {
        const response = await api.createFirewallRuleRaw({ comment: RULE_COMMENT, ...body });
        const id = (response.body as { data?: { id?: unknown } }).data?.id;
        if (typeof id === 'string') {
          created.add(id);
        }
        expect(response.status, JSON.stringify(response.body)).toBe(422);
      });
    }
  });

  test('an unknown rule id is not found', async ({ api }) => {
    expect((await api.deleteFirewallRuleRaw(UNKNOWN_RULE_ID)).status).toBe(404);
    expect(
      (await api.updateFirewallRuleRaw(UNKNOWN_RULE_ID, { comment: RULE_COMMENT })).status
    ).toBe(404);
  });

  test('the engine keeps its own ports open, and the API cannot change them', async ({ api }) => {
    const rules = await listFirewallRules(api);
    const managed = (port: string) =>
      rules.find((rule) => rule.port === port && rule.action === 'allow' && rule.managed);

    for (const port of [ENGINE_API_PORT, ENGINE_SFTP_PORT]) {
      const rule = managed(port);
      expect(rule, `no managed allow rule for port ${port}`).toBeDefined();
      expect(rule?.comment).toMatch(/^panelalpha:/);
      expect(rule?.editable).toBe(false);
    }

    const sftp = managed(ENGINE_SFTP_PORT);
    if (!sftp) {
      return;
    }

    // An empty edit: if the refusal regresses, nothing about the rule changes.
    const edit = await api.updateFirewallRuleRaw(sftp.id, {});
    expect(edit.status, JSON.stringify(edit.body)).toBe(422);

    const removal = await api.deleteFirewallRuleRaw(sftp.id);
    if (removal.status === 200) {
      // Keep SFTP reachable for the rest of the run; the comment prefix cannot be restored here.
      created.delete(sftp.id);
      await api.createFirewallRule({
        action: 'allow',
        protocol: sftp.protocol === 'udp' ? 'udp' : 'tcp',
        port: sftp.port,
        source: sftp.source,
        destination: sftp.destination,
        comment: 'restored by the API suite after a managed rule was deletable',
      });
    }
    expect(removal.status, JSON.stringify(removal.body)).toBe(422);
    expect(await listedIds(api)).toContain(sftp.id);
  });
});

test.describe('Firewall enable and disable', () => {
  let enabledAtStart: boolean | undefined;

  test.beforeEach(async ({ api }) => {
    enabledAtStart = (await requireFirewall(api)).enabled;
  });

  test.afterEach(async ({ api }) => {
    if (enabledAtStart !== undefined) {
      await restoreFirewallState(api, enabledAtStart);
    }
    enabledAtStart = undefined;
  });

  test('enabling an already-enabled firewall is accepted', async ({ api }) => {
    await api.enableFirewall();
    await api.enableFirewall();
    expect((await readFirewallStatus(api)).enabled).toBe(true);
  });

  test(
    'the firewall comes back with its rules after being disabled',
    { tag: ['@slow'] },
    async ({ api }) => {
      await api.enableFirewall();
      const rulesBefore = await listedIds(api);

      await api.disableFirewall();
      expect((await readFirewallStatus(api)).enabled).toBe(false);

      await api.enableFirewall();
      expect((await readFirewallStatus(api)).enabled).toBe(true);
      expect((await listedIds(api)).sort()).toEqual(rulesBefore.sort());
    }
  );

  test(
    'disabling an already-disabled firewall is accepted',
    { tag: ['@slow'] },
    async ({ api }) => {
      await api.disableFirewall();
      await api.disableFirewall();
      expect((await readFirewallStatus(api)).enabled).toBe(false);
    }
  );
});
