import type { EngineApi } from '@/clients/engine-api';
import { redactText } from '@/helpers/redact';
import { isTestUsername } from '@/helpers/random';
import type {
  ConfigSection,
  ErrorSection,
  ResourceSection,
  Section,
  Snapshot,
  SnapshotItem,
} from './types';

/**
 * An inventory of what the engine holds that a test run could leave behind.
 *
 * Resource sections list things by a stable key; config sections hold settings
 * the tests flip and are expected to put back. A section that cannot be read
 * is recorded as an error and never stops the others.
 */

const ERROR_LIMIT = 300;
const PROJECT_BATCH = 5;

export function describeError(error: unknown): string {
  const text = redactText(error instanceof Error ? error.message : String(error));
  const flat = text.replace(/\s+/g, ' ').trim();
  return flat.length > ERROR_LIMIT ? `${flat.slice(0, ERROR_LIMIT)}…` : flat;
}

/** A config value with credentials replaced; round-trips through JSON so it stays plain data. */
export function redactValue<T>(value: T): T {
  const text = redactText(JSON.stringify(value) ?? 'null');
  try {
    return JSON.parse(text) as T;
  } catch {
    return text as unknown as T;
  }
}

function resource(items: Record<string, SnapshotItem>, errors: string[] = []): ResourceSection {
  return { kind: 'resource', items, ...(errors.length > 0 ? { errors } : {}) };
}

function config(values: Record<string, unknown>): ConfigSection {
  return { kind: 'config', values: redactValue(values) };
}

function unsupported(reason: string): Section {
  return { kind: 'unsupported', reason };
}

type Builder = () => Promise<Section>;

async function guarded(build: Builder): Promise<Section> {
  try {
    return await build();
  } catch (error) {
    return { kind: 'error', error: describeError(error) } satisfies ErrorSection;
  }
}

const isNotFound = (error: unknown): boolean => /Got: 404\b/.test(describeError(error));

interface ProjectLister {
  kind: string;
  list: (username: string) => Promise<{ data: Record<string, unknown>[] }>;
  key: (row: Record<string, unknown>) => unknown;
}

const PROJECT_LISTERS = (api: EngineApi): ProjectLister[] => [
  {
    kind: 'domain',
    list: (u) => api.listUserDomains(u) as never,
    key: (row) => row.domain ?? row.name,
  },
  {
    kind: 'mysql-db',
    list: (u) => api.listMySqlDatabases(u) as never,
    key: (row) => row.name ?? row.database,
  },
  {
    kind: 'mysql-user',
    list: (u) => api.listMySqlUsers(u) as never,
    key: (row) => row.name ?? row.username,
  },
  {
    kind: 'ftp',
    list: (u) => api.listFtpAccounts(u) as never,
    key: (row) => row.username ?? row.name,
  },
  {
    kind: 'sftp',
    list: (u) => api.listSftpAccounts(u) as never,
    key: (row) => row.username ?? row.name,
  },
  {
    kind: 'cron',
    list: (u) => api.listCronJobs(u) as never,
    key: (row) => row.hash ?? row.id,
  },
];

async function projectSections(api: EngineApi): Promise<Record<string, Section>> {
  const listed = await api.listAllUsers();
  const projects: Record<string, SnapshotItem> = {};
  for (const user of listed.data) {
    if (isTestUsername(user.username)) {
      projects[user.username] = {
        owner: user.username,
        id: user.id,
        created_at: user.created_at,
        status: user.status,
      };
    }
  }

  const listers = PROJECT_LISTERS(api);
  const children: Record<string, Record<string, SnapshotItem>> = {};
  for (const lister of listers) {
    children[lister.kind] = {};
  }
  const errors: string[] = [];
  const usernames = Object.keys(projects);

  for (let i = 0; i < usernames.length; i += PROJECT_BATCH) {
    await Promise.all(
      usernames.slice(i, i + PROJECT_BATCH).flatMap((username) =>
        listers.map(async (lister) => {
          try {
            const rows = (await lister.list(username)).data;
            for (const row of rows) {
              const id = lister.key(row);
              if (typeof id === 'string' || typeof id === 'number') {
                children[lister.kind][`${username}/${id}`] = { owner: username };
              }
            }
          } catch (error) {
            // The project went away between the listing and this call: nothing to record.
            if (!isNotFound(error)) {
              errors.push(`${username} ${lister.kind}: ${describeError(error)}`);
            }
          }
        })
      )
    );
  }

  const sections: Record<string, Section> = { projects: resource(projects) };
  for (const lister of listers) {
    sections[`project-${lister.kind}`] = resource(
      children[lister.kind],
      errors.filter((e) => e.includes(` ${lister.kind}: `))
    );
  }
  return sections;
}

function globalSections(api: EngineApi): Record<string, Builder> {
  return {
    'proxy-rules': async () => {
      const { data } = await api.listProxyRules();
      return resource(
        Object.fromEntries(
          data.map((rule) => [
            String(rule.id),
            {
              ...(rule.username ? { owner: rule.username } : {}),
              scope: rule.owner_scope,
              transport: rule.transport,
              listen: `${rule.listen_ip}:${rule.listen_port}`,
              server_name: rule.server_name,
              upstream: `${rule.upstream_host}:${rule.upstream_port}`,
              generated: rule.is_generated,
            },
          ])
        )
      );
    },

    'ip-subnets': async () => {
      const { data } = await api.listSubnets();
      return resource(
        Object.fromEntries(
          data.map((subnet) => [
            `${subnet.ip}/${subnet.mask}`,
            { id: subnet.id, shared: Boolean(subnet.is_shared) },
          ])
        )
      );
    },

    'ip-assignments': async () => {
      const { data } = await api.listAssignedIps();
      return resource(
        Object.fromEntries(
          data.map((row) => [
            `${row.ip_address}`,
            { ...(row.username ? { owner: row.username } : {}), subnet: row.ip_subnet_id },
          ])
        )
      );
    },

    'firewall-rules': async () => {
      const { data } = await api.listFirewallRules();
      return resource(
        Object.fromEntries(
          data.map((rule) => [
            rule.id,
            {
              scope: rule.scope,
              action: rule.action,
              direction: rule.direction,
              protocol: rule.protocol,
              port: rule.port,
              source: rule.source,
              comment: rule.comment,
              managed: rule.managed,
            },
          ])
        )
      );
    },

    'firewall-trusted': async () => {
      const { data } = await api.listTrustedAddresses();
      return resource(
        Object.fromEntries(
          data.map((row) => [row.id, { address: row.address, comment: row.comment }])
        )
      );
    },

    'firewall-status': async () => {
      const { data } = await api.getFirewallStatus();
      return config({
        enabled: data.enabled,
        default_incoming: data.default_incoming,
        default_outgoing: data.default_outgoing,
      });
    },

    'modsec-mode': async () => {
      const { data } = await api.getModSecurityConfig();
      return config({
        mode: data.mode,
        enabled_rulesets: [...(data.enabled_rulesets ?? [])].sort(),
      });
    },

    'modsec-rulesets': async () => {
      const { data } = await api.listModSecurityRulesets();
      return config(
        Object.fromEntries(
          data.map((ruleset) => [
            ruleset.name,
            {
              enabled: ruleset.enabled,
              config_files: (ruleset.config_files ?? [])
                .map((file) => (typeof file === 'string' ? file : `${file.file}:${file.enabled}`))
                .sort(),
            },
          ])
        )
      );
    },

    'exim-config': async () => {
      const { data } = await api.getEximConfig();
      return config({ ...data });
    },

    'ssl-config': async () => {
      const { data } = await api.getSslConfig();
      return config({ ...data });
    },

    'network-config': async () => {
      const { data } = await api.getSystemInfo();
      const webserver = typeof data.webserver === 'string' ? data.webserver : data.webserver?.slug;
      return config({
        webserver,
        default_ipv4: data.default_ipv4,
        default_ipv6: data.default_ipv6,
        cert_domain: data.cert_domain,
        sites_base_domain: data.sites_base_domain,
        ipv4_nat_mode: data.ipv4_nat_mode,
        ipv4_nat_maps: (data.ipv4_nat_maps ?? []).map((m) => `${m.local_ip}>${m.public_ip}`).sort(),
      });
    },

    'backup-containers': async () => {
      const { data } = await api.listBackupContainers();
      return resource(
        Object.fromEntries(
          data.map((container) => [
            String(container.id),
            { name: container.name, driver: container.driver },
          ])
        )
      );
    },

    'vault-secrets': async () => {
      const { data } = await api.listVaultSecrets();
      return resource(
        Object.fromEntries(
          data.map((secret) => [
            secret.ref,
            {
              ...(secret.project ? { owner: secret.project } : {}),
              type: secret.type,
              scope: secret.scope,
              purpose: secret.purpose,
              status: secret.status,
            },
          ])
        )
      );
    },

    'mcp-tokens': async () => {
      const { data } = await api.listMcpTokens();
      return resource(
        Object.fromEntries(
          data.map((token) => [
            String(token.id),
            { name: token.name, created_at: token.created_at, revoked_at: token.revoked_at },
          ])
        )
      );
    },
  };
}

/** Sections the API offers no list endpoint for; recorded so the gap is visible, not silent. */
const UNSUPPORTED: Record<string, string> = {
  'modsec-global-rules': 'no endpoint lists global ModSecurity rules (only rulesets and mode)',
  'system-settings': 'no endpoint lists generic system settings (only ssl-config and system/info)',
  'php-global-settings': 'PHP settings are per domain; there is no global listing endpoint',
  'docker-images':
    'not exposed by the API; infrastructure images are kept by the engine on purpose',
  'docker-cache': 'not exposed by the API; the prewarm cache is kept by the engine on purpose',
};

export async function takeSnapshot(api: EngineApi): Promise<Snapshot> {
  const sections: Record<string, Section> = {};

  const globals = globalSections(api);
  const [projects, ...built] = await Promise.all([
    projectSections(api).then(
      (value) => ({ ok: true as const, value }),
      (error: unknown) => ({ ok: false as const, error })
    ),
    ...Object.values(globals).map((build) => guarded(build)),
  ]);

  if (projects.ok) {
    Object.assign(sections, projects.value);
  } else {
    sections.projects = { kind: 'error', error: describeError(projects.error) };
  }
  Object.keys(globals).forEach((name, index) => {
    sections[name] = built[index];
  });
  for (const [name, reason] of Object.entries(UNSUPPORTED)) {
    sections[name] = unsupported(reason);
  }

  return { version: 1, takenAt: new Date().toISOString(), sections: sortSections(sections) };
}

function sortSections(sections: Record<string, Section>): Record<string, Section> {
  return Object.fromEntries(Object.entries(sections).sort(([a], [b]) => a.localeCompare(b)));
}
