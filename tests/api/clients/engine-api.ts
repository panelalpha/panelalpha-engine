import { type APIResponse } from '@playwright/test';
import { type ApiTransport } from './api-transport';
import { mixinApiClient } from './compose-engine-api';
import { EngineApiBase } from './engine-api-base';
import { AcmeApi } from './resources/acme.api';
import { AppUsersApi } from './resources/app-users.api';
import { ContainersApi } from './resources/containers.api';
import { SshApi } from './resources/ssh.api';
import { InspectApi } from './resources/inspect.api';
import { BackupsApi } from './resources/backups.api';
import { GitApi } from './resources/git.api';
import { TunnelsApi } from './resources/tunnels.api';
import { ProjectSettingsApi } from './resources/project-settings.api';
import { TasksApi } from './resources/tasks.api';
import { BugReportsApi } from './resources/bug-reports.api';
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
import { SystemApi } from './resources/system.api';
import { UsersApi } from './resources/users.api';
import { VaultApi } from './resources/vault.api';
import { WpCliApi } from './resources/wpcli.api';

/** TypeScript surface: all domain methods available on {@link EngineApi}. */
export interface EngineApi
  extends
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
    VaultApi {}

/**
 * Full Engine API client composed from domain-specific API classes.
 *
 * Each domain client extends {@link EngineApiBase} only; this class mixes their
 * public methods onto a single instance for backward-compatible `api.method()` usage.
 *
 * Namespaced access: `api.clients.users`, `api.clients.domains`, etc.
 */
export class EngineApi extends EngineApiBase {
  readonly clients: {
    users: UsersApi;
    exim: EximApi;
    domains: DomainsApi;
    php: PhpApi;
    mysql: MySqlApi;
    ftp: FtpApi;
    sftp: SftpApi;
    cron: CronApi;
    wpcli: WpCliApi;
    csf: CsfApi;
    modsec: ModSecurityApi;
    system: SystemApi;
    files: FilesApi;
    lighthouse: LighthouseApi;
    ip: IpApi;
    mcp: McpApi;
    proxyRules: ProxyRulesApi;
    acme: AcmeApi;
    containers: ContainersApi;
    appUsers: AppUsersApi;
    ssh: SshApi;
    inspect: InspectApi;
    backups: BackupsApi;
    git: GitApi;
    tunnels: TunnelsApi;
    projectSettings: ProjectSettingsApi;
    tasks: TasksApi;
    bugReports: BugReportsApi;
    vault: VaultApi;
  };

  constructor(transport: ApiTransport) {
    super(transport);

    this.clients = {
      users: new UsersApi(transport),
      exim: new EximApi(transport),
      domains: new DomainsApi(transport),
      php: new PhpApi(transport),
      mysql: new MySqlApi(transport),
      ftp: new FtpApi(transport),
      sftp: new SftpApi(transport),
      cron: new CronApi(transport),
      wpcli: new WpCliApi(transport),
      csf: new CsfApi(transport),
      modsec: new ModSecurityApi(transport),
      system: new SystemApi(transport),
      files: new FilesApi(transport),
      lighthouse: new LighthouseApi(transport),
      ip: new IpApi(transport),
      mcp: new McpApi(transport),
      proxyRules: new ProxyRulesApi(transport),
      acme: new AcmeApi(transport),
      containers: new ContainersApi(transport),
      appUsers: new AppUsersApi(transport),
      ssh: new SshApi(transport),
      inspect: new InspectApi(transport),
      backups: new BackupsApi(transport),
      git: new GitApi(transport),
      tunnels: new TunnelsApi(transport),
      projectSettings: new ProjectSettingsApi(transport),
      tasks: new TasksApi(transport),
      bugReports: new BugReportsApi(transport),
      vault: new VaultApi(transport),
    };

    for (const client of Object.values(this.clients)) {
      mixinApiClient(this, client);
    }
  }

  async get(endpoint: string): Promise<APIResponse> {
    return this.api.get(endpoint);
  }

  async post(endpoint: string, data?: unknown): Promise<APIResponse> {
    return this.api.post(endpoint, data ? { data } : undefined);
  }

  async put(endpoint: string, data?: unknown): Promise<APIResponse> {
    return this.api.put(endpoint, data ? { data } : undefined);
  }

  async delete(endpoint: string): Promise<APIResponse> {
    return this.api.delete(endpoint);
  }
}
