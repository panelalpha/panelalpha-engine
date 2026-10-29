import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { testNetwork } from '@/helpers/test-network';
import { buildAllowList } from '@/scripts/lib/leak-check/allow';
import { compareSnapshots } from '@/scripts/lib/leak-check/compare';
import { leakCheckEnabled } from '@/scripts/lib/leak-check/run';
import { takeSnapshot } from '@/scripts/lib/leak-check/snapshot';
import type { ConfigSection, ResourceSection, Snapshot } from '@/scripts/lib/leak-check/types';

/** A listing as the engine answers it. */
const list = <T>(data: T[]) => Promise.resolve({ data });
const one = <T>(data: T) => Promise.resolve({ data });

interface FirewallRuleRow {
  id: number;
  action: string;
  source: string | null;
  comment: string | null;
}

interface World {
  projects: string[];
  proxyRules: { id: number; username?: string }[];
  firewallRules: FirewallRuleRow[];
  modsecMode: string;
  smtpPassword: string;
  failing: string[];
}

function fakeApi(world: World): EngineApi {
  const guard = <T>(name: string, value: () => Promise<T>) =>
    world.failing.includes(name)
      ? Promise.reject(new Error(`API call failed\n  Expected: 200  —  Got: 500\n  Response: boom`))
      : value();

  return {
    listAllUsers: () =>
      list([
        ...world.projects.map((username, id) => ({
          id,
          username,
          created_at: '2026-01-01T00:00:00Z',
          status: 'active',
        })),
        { id: 99, username: 'shop', created_at: '', status: 'active' },
      ]),
    listUserDomains: (u: string) => list([{ domain: `${u}.example.test` }]),
    listMySqlDatabases: () => list([]),
    listMySqlUsers: () => list([]),
    listFtpAccounts: () => list([]),
    listSftpAccounts: () => list([]),
    listCronJobs: () => list([]),
    listProxyRules: () =>
      guard('proxy-rules', () =>
        list(
          world.proxyRules.map((rule) => ({
            id: rule.id,
            username: rule.username ?? null,
            owner_scope: rule.username ? 'user' : 'system',
            transport: 'tcp',
            listen_ip: testNetwork.ANY,
            listen_port: 9000 + rule.id,
            server_name: null,
            upstream_host: 'x',
            upstream_port: 80,
            is_generated: false,
          }))
        )
      ),
    listSubnets: () => Promise.resolve({ data: [], meta: {} }),
    listAssignedIps: () => list([]),
    listFirewallRules: () =>
      list(
        world.firewallRules.map((rule) => ({
          ...rule,
          scope: 'host',
          direction: 'in',
          protocol: 'tcp',
          port: '22',
          managed: false,
        }))
      ),
    listTrustedAddresses: () => list([]),
    getFirewallStatus: () =>
      one({ enabled: true, default_incoming: 'deny', default_outgoing: 'allow' }),
    getModSecurityConfig: () => one({ mode: world.modsecMode, enabled_rulesets: ['crs'] }),
    listModSecurityRulesets: () =>
      list([{ name: 'crs', enabled: true, config_files: ['b.conf', 'a.conf.disabled'] }]),
    getEximConfig: () => one({ smtp_host: 'mail.test', smtp_password: world.smtpPassword }),
    getSslConfig: () => one({ issuer: 'acme', shared_zone: false }),
    getSystemInfo: () => one({ webserver: { slug: 'nginx' }, version: '1' }),
    listBackupContainers: () => list([]),
    listVaultSecrets: () => list([]),
    listMcpTokens: () => list([]),
  } as unknown as EngineApi;
}

const baseWorld = (overrides: Partial<World> = {}): World => ({
  projects: [],
  proxyRules: [],
  firewallRules: [],
  modsecMode: 'DetectionOnly',
  smtpPassword: 'hunter2-planted',
  failing: [],
  ...overrides,
});

const snap = async (overrides?: Partial<World>): Promise<Snapshot> =>
  takeSnapshot(fakeApi(baseWorld(overrides)));

const allowNone = buildAllowList();

test.describe('takeSnapshot', () => {
  test('lists only test projects, with what each one owns', async () => {
    const snapshot = await snap({ projects: ['pw0123abcd'] });
    const projects = snapshot.sections.projects as ResourceSection;
    expect(Object.keys(projects.items)).toEqual(['pw0123abcd']);
    expect(projects.items.pw0123abcd.created_at).toBe('2026-01-01T00:00:00Z');
    const domains = snapshot.sections['project-domain'] as ResourceSection;
    expect(Object.keys(domains.items)).toEqual(['pw0123abcd/pw0123abcd.example.test']);
  });

  test('a failing section is recorded and does not stop the others', async () => {
    const snapshot = await snap({ failing: ['proxy-rules'] });
    expect(snapshot.sections['proxy-rules']).toMatchObject({
      kind: 'error',
      error: expect.stringContaining('Got: 500'),
    });
    expect(snapshot.sections['modsec-mode'].kind).toBe('config');
  });

  test('sections without a list endpoint are marked unsupported with a reason', async () => {
    const snapshot = await snap();
    expect(snapshot.sections['modsec-global-rules']).toMatchObject({ kind: 'unsupported' });
    expect(snapshot.sections['docker-images']).toMatchObject({ kind: 'unsupported' });
  });

  test('redacts credentials in config sections', async () => {
    const snapshot = await snap();
    const exim = snapshot.sections['exim-config'] as ConfigSection;
    expect(JSON.stringify(exim)).not.toContain('hunter2-planted');
    expect(exim.values.smtp_host).toBe('mail.test');
  });
});

test.describe('compareSnapshots', () => {
  test('identical snapshots are ok', async () => {
    const result = compareSnapshots(await snap(), await snap(), { allow: allowNone });
    expect(result).toMatchObject({ ok: true, leaks: [], changed: [], errors: [] });
  });

  test('a new project is a leak', async () => {
    const result = compareSnapshots(await snap(), await snap({ projects: ['pwdeadbeef'] }), {
      allow: allowNone,
    });
    expect(result.ok).toBe(false);
    expect(result.leaks.map((l) => `${l.section}/${l.key}`)).toContain('projects/pwdeadbeef');
    expect(result.leaks.map((l) => l.section)).toContain('project-domain');
  });

  test('a new proxy rule is a leak', async () => {
    const result = compareSnapshots(await snap(), await snap({ proxyRules: [{ id: 7 }] }), {
      allow: allowNone,
    });
    expect(result.leaks).toEqual([expect.objectContaining({ section: 'proxy-rules', key: '7' })]);
  });

  test('a changed ModSecurity mode is reported with both values', async () => {
    const result = compareSnapshots(await snap(), await snap({ modsecMode: 'On' }), {
      allow: allowNone,
    });
    expect(result.ok).toBe(false);
    expect(result.changed).toEqual([
      expect.objectContaining({
        section: 'modsec-mode',
        key: 'mode',
        before: 'DetectionOnly',
        after: 'On',
      }),
    ]);
  });

  test('the shared setup project and what it owns are allowed', async () => {
    const after = await snap({
      projects: ['pw5e7a0001'],
      proxyRules: [{ id: 3, username: 'pw5e7a0001' }],
    });
    const result = compareSnapshots(await snap(), after, {
      allow: buildAllowList(['pw5e7a0001']),
    });
    expect(result.ok).toBe(true);
    expect(result.leaks).toEqual([]);
    expect(result.allowed.map((a) => `${a.section}/${a.key}`)).toEqual(
      expect.arrayContaining(['projects/pw5e7a0001', 'proxy-rules/3'])
    );
  });

  test('a fail2ban ban made during the run is allowed, not a leak', async () => {
    const ban = {
      id: 7,
      action: 'deny',
      source: testNetwork.public(50),
      comment: 'by Fail2Ban after 5 attempts against sshd',
    };
    const result = compareSnapshots(await snap(), await snap({ firewallRules: [ban] }), {
      allow: allowNone,
    });
    expect(result.ok).toBe(true);
    expect(result.allowed.map((a) => `${a.section}/${a.key}`)).toEqual(['firewall-rules/7']);
  });

  test('a firewall rule that is not a ban is still a leak', async () => {
    const rules = [
      { id: 8, action: 'allow', source: testNetwork.public(51), comment: 'by Fail2Ban lookalike' },
      { id: 9, action: 'deny', source: null, comment: 'by Fail2Ban lookalike' },
      { id: 10, action: 'deny', source: testNetwork.public(52), comment: 'left by a test' },
    ];
    const result = compareSnapshots(await snap(), await snap({ firewallRules: rules }), {
      allow: allowNone,
    });
    expect(result.ok).toBe(false);
    expect(result.leaks.map((l) => `${l.section}/${l.key}`)).toEqual([
      'firewall-rules/8',
      'firewall-rules/9',
      'firewall-rules/10',
    ]);
  });

  test('items gone after the run are listed as removed and do not fail', async () => {
    const result = compareSnapshots(await snap({ projects: ['pw01d01d01'] }), await snap(), {
      allow: allowNone,
    });
    expect(result.ok).toBe(true);
    expect(result.removed.map((r) => `${r.section}/${r.key}`)).toContain('projects/pw01d01d01');
  });

  test('a section that could not be read fails the check', async () => {
    const result = compareSnapshots(await snap(), await snap({ failing: ['proxy-rules'] }), {
      allow: allowNone,
    });
    expect(result.ok).toBe(false);
    expect(result.errors).toEqual([
      expect.objectContaining({ section: 'proxy-rules', side: 'after' }),
    ]);
  });

  test('a changed secret is not written into the result', async () => {
    const result = compareSnapshots(await snap(), await snap({ smtpPassword: 'another-secret' }), {
      allow: allowNone,
    });
    expect(JSON.stringify(result)).not.toMatch(/hunter2-planted|another-secret/);
  });
});

test.describe('leakCheckEnabled', () => {
  test('follows LEAK_CHECK, then CI', () => {
    expect(leakCheckEnabled({})).toBe(false);
    expect(leakCheckEnabled({ CI: 'true' })).toBe(true);
    expect(leakCheckEnabled({ CI: 'true', LEAK_CHECK: '0' })).toBe(false);
    expect(leakCheckEnabled({ LEAK_CHECK: '1' })).toBe(true);
  });
});
