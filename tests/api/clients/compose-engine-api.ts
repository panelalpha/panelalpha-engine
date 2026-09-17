import { EngineApiBase } from './engine-api-base';
import { AcmeApi } from './resources/acme.api';
import { AppUsersApi } from './resources/app-users.api';
import { ContainersApi } from './resources/containers.api';
import { CronApi } from './resources/cron.api';
import { CsfApi } from './resources/csf.api';
import { DomainsApi } from './resources/domains.api';
import { EximApi } from './resources/exim.api';
import { FilesApi } from './resources/files.api';
import { FtpApi } from './resources/ftp.api';
import { IpApi } from './resources/ip.api';
import { LighthouseApi } from './resources/lighthouse.api';
import { McpApi } from './resources/mcp.api';
import { ModSecurityApi } from './resources/modsec.api';
import { MySqlApi } from './resources/mysql.api';
import { PhpApi } from './resources/php.api';
import { ProxyRulesApi } from './resources/proxy-rules.api';
import { SftpApi } from './resources/sftp.api';
import { SshApi } from './resources/ssh.api';
import { InspectApi } from './resources/inspect.api';
import { BackupsApi } from './resources/backups.api';
import { GitApi } from './resources/git.api';
import { TunnelsApi } from './resources/tunnels.api';
import { ProjectSettingsApi } from './resources/project-settings.api';
import { TasksApi } from './resources/tasks.api';
import { BugReportsApi } from './resources/bug-reports.api';
import { SystemApi } from './resources/system.api';
import { UsersApi } from './resources/users.api';
import { VaultApi } from './resources/vault.api';
import { WpCliApi } from './resources/wpcli.api';

/** Domain API clients composed into {@link EngineApi}. */
export const ENGINE_API_CLIENTS = [
  UsersApi,
  EximApi,
  DomainsApi,
  PhpApi,
  MySqlApi,
  FtpApi,
  SftpApi,
  CronApi,
  WpCliApi,
  CsfApi,
  ModSecurityApi,
  SystemApi,
  FilesApi,
  LighthouseApi,
  IpApi,
  McpApi,
  ProxyRulesApi,
  AcmeApi,
  ContainersApi,
  AppUsersApi,
  SshApi,
  InspectApi,
  BackupsApi,
  GitApi,
  TunnelsApi,
  ProjectSettingsApi,
  TasksApi,
  BugReportsApi,
  VaultApi,
] as const;

export type EngineApiClient = InstanceType<(typeof ENGINE_API_CLIENTS)[number]>;

const BASE_PROTOTYPE = EngineApiBase.prototype;
const BASE_METHOD_NAMES = new Set(
  Object.getOwnPropertyNames(BASE_PROTOTYPE).filter((name) => name !== 'constructor')
);

/**
 * Copies public API methods from a domain client onto the composed EngineApi instance.
 * Methods are bound to the domain client so `this.api` resolves correctly.
 */
export function mixinApiClient(target: EngineApiBase, client: EngineApiClient): void {
  const proto = Object.getPrototypeOf(client) as object;
  for (const key of Object.getOwnPropertyNames(proto)) {
    if (key === 'constructor' || BASE_METHOD_NAMES.has(key)) {
      continue;
    }
    const descriptor = Object.getOwnPropertyDescriptor(proto, key);
    if (!descriptor?.value || typeof descriptor.value !== 'function') {
      continue;
    }
    Object.defineProperty(target, key, {
      value: (descriptor.value as (...args: unknown[]) => unknown).bind(client),
      writable: true,
      configurable: true,
      enumerable: true,
    });
  }
}
