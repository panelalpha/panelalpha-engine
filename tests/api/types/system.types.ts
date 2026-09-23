import type { PhpVersion } from './php.types';

export interface Ipv4NatMap {
  id?: number;
  local_ip: string;
  public_ip: string;
  created_at?: string;
  updated_at?: string;
}

export interface SystemInfo {
  webserver:
    | string
    | {
        name?: string;
        version?: string;
        slug?: string;
        type?: string;
        metadata?: Record<string, unknown>;
      };
  version: string;
  php_versions?: PhpVersion[];
  default_ipv4?: string | null;
  default_ipv6?: string | null;
  cert_domain?: string | null;
  sites_base_domain?: string | null;
  url?: string | null;
  api_url?: string | null;
  ipv4_nat_mode?: boolean;
  ipv4_nat_maps?: Ipv4NatMap[];
  latest_webserver_change?: SystemChangeStatus | null;
  latest_update?: SystemChangeStatus | null;
}

export interface SystemChangeStatus {
  started_at?: number | null;
  finished_at?: number | null;
  pid?: number | null;
  exit_code?: number | null;
  tail_stdout?: string | null;
  tail_stderr?: string | null;
  from_version?: string | null;
  to_version?: string | null;
  logs_path?: string | null;
  [key: string]: unknown;
}

export interface SystemMetrics {
  cpu_usage: number;
  memory_usage: number;
  disk_usage: number;
  load_average: number[];
}

export interface UsageLimitPair {
  usage: number;
  maximum: number;
}

export interface UserUsage {
  storage: UsageLimitPair;
  /** Calendar-month transfer in bytes. `maximum` is null when the project is unlimited. */
  bandwidth: {
    usage: number;
    maximum: number | null;
  };
  addon_domains: UsageLimitPair;
  subdomains: UsageLimitPair;
  ftp_accounts: UsageLimitPair;
  sftp_accounts: UsageLimitPair;
  mysql_databases: UsageLimitPair;
}

export interface SslConfig {
  issuer: string;
  sites_base_domain: string | null;
  shared_zone: boolean;
  acme_directory_url: string | null;
  acme_email: string | null;
}

export interface EngineCertificateRequest {
  domain?: string | null;
  email?: string | null;
  ip?: string | null;
  staging?: boolean | null;
  force_renewal?: boolean | null;
  skip_dns_check?: boolean | null;
  dry_run?: boolean | null;
}
