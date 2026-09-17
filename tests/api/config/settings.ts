import * as path from 'path';
import { fileURLToPath } from 'url';
import { Timeouts } from './timeouts';
import { toPanelAlphaDirectZone } from './site-domain';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export class Settings {
  readonly apiBaseUrl: string;
  readonly apiToken: string | undefined;
  readonly domain: string | undefined;
  readonly testEnv: string;
  readonly cleanTestEnv: boolean;

  /**
   * Test timing defaults. Override via env vars (PROPAGATION_DELAY, CRON_MAX_WAIT_TIME, etc.).
   * propagationDelay — DNS/config propagation after domains/users/settings changes.
   * webserverChangeTimeout — webserver switch can take many minutes on slow hosts.
   */
  readonly timing: {
    readonly propagationDelay: number;
    readonly phpExecutionDelay: number;
    readonly cronCheckInterval: number;
    readonly cronMaxWaitTime: number;
    readonly defaultTimeout: number;
    readonly expectTimeout: number;
    readonly setupTimeout: number;
    readonly webserverChangeTimeout: number;
    readonly apiCallTimeout: number;
    readonly deployTimeout: number;
  };

  readonly ports: {
    readonly ftp: number;
    readonly sftp: number;
  };

  readonly mysql: {
    readonly host: string;
    readonly defaultPrivileges: string;
  };

  readonly paths: {
    readonly cacheDir: string;
    readonly staticDataDir: string;
  };

  constructor() {
    this.testEnv = process.env.TEST_ENV ?? '';
    this.cleanTestEnv = process.env.CLEAN_TEST_ENV === '1' || process.env.CLEAN_TEST_ENV === 'true';

    this.apiBaseUrl = this.getRequiredEnv('API_BASE_URL');
    this.apiToken = process.env.API_TOKEN;
    this.domain = this.resolveTestDomain(process.env.DOMAIN, this.apiBaseUrl);

    this.timing = {
      propagationDelay: this.getEnvNumber('PROPAGATION_DELAY', 5000),
      phpExecutionDelay: this.getEnvNumber('PHP_EXECUTION_DELAY', 3000),
      cronCheckInterval: this.getEnvNumber('CRON_CHECK_INTERVAL', 5000),
      cronMaxWaitTime: this.getEnvNumber('CRON_MAX_WAIT_TIME', 120000),
      defaultTimeout: this.getEnvNumber('DEFAULT_TIMEOUT', Timeouts.default),
      expectTimeout: this.getEnvNumber('EXPECT_TIMEOUT', Timeouts.expect),
      setupTimeout: this.getEnvNumber('SETUP_TIMEOUT', Timeouts.setup),
      webserverChangeTimeout: this.getEnvNumber(
        'WEBSERVER_CHANGE_TIMEOUT',
        Timeouts.webserverChange
      ),
      apiCallTimeout: this.getEnvNumber('API_CALL_TIMEOUT', Timeouts.apiCall),
      deployTimeout: this.getEnvNumber('DEPLOY_TIMEOUT', Timeouts.deploy),
    };

    this.ports = {
      ftp: 21,
      sftp: this.getEnvNumber('SFTP_PORT', 2222),
    };

    this.mysql = {
      host: process.env.MYSQL_HOST ?? 'database-users.shared-hosting.palocal',
      defaultPrivileges: 'SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,INDEX,ALTER',
    };

    const rootDir = path.resolve(__dirname, '..');

    this.paths = {
      cacheDir: path.join(rootDir, '.playwright/cache'),
      staticDataDir: path.join(rootDir, 'test-data/static'),
    };
  }

  private getRequiredEnv(name: string): string {
    const value = process.env[name];
    if (!value) {
      throw new Error(
        `Required environment variable '${name}' is not set. Please configure it before running tests.`
      );
    }
    return value;
  }

  private getEnvNumber(name: string, defaultValue: number): number {
    const value = process.env[name];
    if (!value) {
      return defaultValue;
    }
    const parsed = parseInt(value, 10);
    return isNaN(parsed) ? defaultValue : parsed;
  }

  /**
   * The base domain every generated test domain hangs off, guaranteed to resolve.
   *
   * Tests create real vhosts and then fetch them over HTTP, so a made-up name
   * would fail for want of DNS rather than for anything the engine did. Throws
   * rather than returning undefined, so a misconfigured `DOMAIN` surfaces at the
   * first test instead of as a puzzling connection error later.
   */
  requireDomain(): string {
    if (!this.domain) {
      throw new Error(
        'No test domain is configured. Set DOMAIN in env/.env, or point API_BASE_URL at a ' +
          'host that can serve as one. A bare IPv4 works: it is mapped to <dashed-ip>.panelalpha.direct.'
      );
    }
    return this.domain;
  }

  /**
   * Resolves the base domain, mapping a bare IPv4 to `{dashed-ip}.panelalpha.direct`.
   *
   * That zone resolves back to the address, which gives every generated
   * subdomain working DNS without anyone maintaining a zone — and the engine
   * rejects a bare IP as a domain anyway.
   */
  private resolveTestDomain(rawDomain: string | undefined, apiBaseUrl: string): string | undefined {
    const explicitDomain = (rawDomain ?? '').trim().toLowerCase();
    if (explicitDomain) {
      return this.normalizeDomainHost(explicitDomain);
    }

    try {
      const apiHost = new URL(apiBaseUrl).hostname.trim().toLowerCase();
      if (!apiHost) {
        return undefined;
      }
      return this.normalizeDomainHost(apiHost);
    } catch {
      return undefined;
    }
  }

  private normalizeDomainHost(host: string): string {
    return this.isIpv4(host) ? toPanelAlphaDirectZone(host) : host;
  }

  private isIpv4(host: string): boolean {
    const parts = host.split('.');
    if (parts.length !== 4) {
      return false;
    }

    for (const part of parts) {
      if (!/^\d{1,3}$/.test(part)) {
        return false;
      }
      const value = Number(part);
      if (value < 0 || value > 255) {
        return false;
      }
    }

    return true;
  }

  getCacheFilePath(): string {
    const envSuffix = this.testEnv ? `-${this.testEnv}` : '';
    return path.join(this.paths.cacheDir, `test-cache${envSuffix}.json`);
  }

  getSshPublicKeyPath(): string {
    return path.join(this.paths.staticDataDir, 'ssh_test_key.pub');
  }
}

let settingsInstance: Settings | null = null;

export function getSettings(): Settings {
  settingsInstance ??= new Settings();
  return settingsInstance;
}

export function resetSettings(): void {
  settingsInstance = null;
}
