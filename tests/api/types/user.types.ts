/** Shared resource limit fields present in both UserDetails and UserConfig. */
export interface UserLimits {
  home_dir: string;
  mysql_prefix: string;
  disk_space_limit: number;
  memory_limit?: number | null;
  cpu_limit?: number | null;
  device_read_bps?: number | null;
  device_write_bps?: number | null;
  bandwidth_limit?: number | null;
  mysql_databases_limit?: number | null;
  ftp_accounts_limit?: number | null;
  sftp_accounts_limit?: number | null;
  addon_domains_limit?: number | null;
  subdomains_limit?: number | null;
  inodes_limit?: number | null;
  dedicated_ipv4?: boolean;
  dedicated_ipv6?: boolean;
  UID: number;
  GID: number;
}

export interface UserDetails extends UserLimits {
  /** Raw string/null — as stored by the engine */
  php_fpm_pool_settings?: string | null;
  lsphp_settings?: string | null;
  template?: string | null;
  git_repo?: string | null;
  git_branch?: string | null;
  deploy_strategy?: string | null;
  deployment_status?: string | null;
  /** Sentences that made a finished deploy `partial`. Empty when the deploy was clean. */
  deployment_warnings?: string[] | null;
  /** How the main domain was chosen — see DomainPlan sources. */
  domain?: {
    source?: string;
    publicly_resolvable?: boolean | null;
    tls_terminated_at?: string;
    tunnel?: string | null;
    fallback_reason?: string | null;
  };
}

export interface UserConfig extends UserLimits {
  /** Parsed key-value map — as returned by the config endpoint */
  php_fpm_pool_settings?: Record<string, string>;
  lsphp_settings?: Record<string, string>;
  ip_addresses: {
    ipv4: string[];
    ipv6: string[];
  };
}

export interface ProjectApp {
  type: string | null;
  framework: string | null;
  label: string | null;
  status: 'live' | 'building' | 'failed' | 'down' | 'suspended' | 'none';
  public_url: string | null;
  admin_sso: boolean;
}

export interface User {
  id: number;
  username: string;
  domain: string;
  name: string | null;
  email: string | null;
  email_verified_at: string | null;
  status: 'active' | 'suspended' | 'pending';
  /** While true, an API or MCP token may not delete, stop or redeploy this project. */
  protected: boolean;
  staging?: string | null;
  staging_of?: string | null;
  created_at: string;
  updated_at: string;
  details: UserDetails;
  config: UserConfig;
  app: ProjectApp;
}

export interface UserCredentials {
  username: string;
  domain: string;
  database: string;
  mysqlHost: string;
  mysqlUsername: string;
  mysqlPassword: string;
}

/** One table of one database, as a push selection and the table diff name it. */
export interface PushTable {
  database: string;
  name: string;
}

/** What `GET /projects/{username}/push/diff` answers under `data`. */
export interface PushDiff {
  tables_structure_diff: PushTable[];
  tables_data_diff: PushTable[];
}

/**
 * Combined data structure produced by the setup test and consumed by dependent tests.
 * Shared via playwright-relay under key '[setup] should create test user with WordPress'.
 */ export interface SetupTestData extends UserCredentials {
  wpPath: string;
  url: string;
  admin_username: string;
  admin_password: string;
  admin_email: string;
}

export interface CreateUserRequest {
  username: string;
  /** Omit to let the engine allocate (panelalpha.online first, then .direct). */
  domain?: string;
  tunnel?: 'none' | 'panelalpha';
  domain_redirect_url?: string | null;
  email?: string;
  template?: string | null;
  disk_space_limit?: number | null;
  memory_limit?: number | null;
  cpu_limit?: number | null;
  device_read_bps?: number | null;
  device_write_bps?: number | null;
  bandwidth_limit?: number | null;
  mysql_databases_limit?: number | null;
  ftp_accounts_limit?: number | null;
  sftp_accounts_limit?: number | null;
  addon_domains_limit?: number | null;
  subdomains_limit?: number | null;
  inodes_limit?: number | null;
  php_fpm_pool_settings?: string | null;
  lsphp_settings?: string | null;
  redis_config?: string | null;
  dedicated_ipv4?: boolean | null;
  dedicated_ipv6?: boolean | null;
  git_repo?: string | null;
  git_branch?: string | null;
  git_token?: string | null;
  env_vars?: Record<string, string | null> | null;
  stages?: Record<string, unknown> | null;
  recipe?: string | null;
}

export interface AttachSourceRequest {
  git_repo: string;
  git_branch?: string | null;
  git_token?: string | null;
  env_vars?: Record<string, string | null> | null;
  stages?: Record<string, unknown> | null;
  recipe?: string | null;
}

export interface UpdateUserRequest {
  domain?: string;
  email?: string;
  name?: string;
  redis_config?: string | null;
  disk_space_limit?: number;
  memory_limit?: number | null;
  cpu_limit?: number | null;
  device_read_bps?: number | null;
  device_write_bps?: number | null;
  bandwidth_limit?: number | null;
  mysql_databases_limit?: number | null;
  ftp_accounts_limit?: number | null;
  sftp_accounts_limit?: number | null;
  addon_domains_limit?: number | null;
  subdomains_limit?: number | null;
  inodes_limit?: number | null;
  php_fpm_pool_settings?: string | null;
  lsphp_settings?: string | null;
}
