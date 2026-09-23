import { faker } from '@faker-js/faker';
import { type EngineApi } from '@/clients/engine-api';
import { type UserCredentials } from '@/types';
import { getSettings } from '@/config/settings';
import { configuredGitRepo, waitForDeploy } from '@/helpers/deploy-helpers';
import { isProjectCreateOk, taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { randomUsername } from '@/helpers/random';

export interface CreateUserOptions {
  /**
   * Whether the `userFactory` fixture deletes this user when the test ends.
   *
   * Pass `false` for a user that has to outlive a single test — a `serial`
   * describe block whose steps build on each other, for instance. Such a user
   * must be deleted by the test that finishes with it.
   */
  autoCleanup?: boolean;
  /**
   * How the domain reaches this host. Omitted, the engine walks DomainPlan:
   * panelalpha.online first, then panelalpha.direct. Pass `none` to skip the
   * public name and land on `.panelalpha.direct`. Pass `panelalpha` to insist
   * on the tunnel.
   */
  tunnel?: 'none' | 'panelalpha';
  /** Caller-owned name. Omit so the engine allocates. */
  domain?: string;
  /**
   * When `autoCleanup` is on, a failed test keeps the user for inspection.
   * Pass `false` for Online/hub names that must not linger after the run.
   */
  preserveOnFailure?: boolean;
  /**
   * How long to wait for the create task and, for a git deploy, the deploy log.
   * Defaults to `DEPLOY_TIMEOUT` (10 minutes). Supported-app deploys pass a
   * longer budget; a Java or Go build legitimately outlasts the default.
   */
  deployTimeout?: number;
}

/** Insist on `*.panelalpha.online` and always delete the project afterwards. */
export const ONLINE_DEPLOY_USER = {
  tunnel: 'panelalpha',
  preserveOnFailure: false,
} as const satisfies CreateUserOptions;

export class UserFactory {
  private settings = getSettings();

  /** Users created here that nothing has deleted yet — drained by the fixture. */
  private readonly pending = new Map<string, { preserveOnFailure: boolean }>();

  constructor(private api: EngineApi) {}

  /** Usernames still awaiting cleanup, oldest first. */
  get pendingCleanup(): string[] {
    return [...this.pending.keys()];
  }

  /** True when a failed test should keep this user on the engine. */
  shouldPreserveOnFailure(username: string): boolean {
    return this.pending.get(username)?.preserveOnFailure !== false;
  }

  async createUser(options: CreateUserOptions = {}): Promise<UserCredentials> {
    const created = await this.createAccount(options);
    const mysqlPassword = this.generateSecurePassword();
    const { username, domain } = created;

    const databaseName = `${username}_wp`;
    await this.api.createMySqlDatabase(username, databaseName);

    await this.api.createMySqlUser(username, username, mysqlPassword);
    const mysqlUsername = `${username}_${username}`;

    await this.api.grantPrivileges(
      username,
      mysqlUsername,
      databaseName,
      this.settings.mysql.defaultPrivileges
    );

    return {
      username,
      domain,
      database: databaseName,
      mysqlHost: this.settings.mysql.host,
      mysqlUsername,
      mysqlPassword,
    };
  }

  async createSimpleUser(
    options: CreateUserOptions = {}
  ): Promise<{ username: string; domain: string }> {
    return this.createAccount(options);
  }

  /**
   * Empty DinD account — no git clone. Returns null when this engine refuses
   * the dind template, so callers can skip rather than fail.
   */
  async createDindUser(
    options: CreateUserOptions = {}
  ): Promise<{ username: string; domain: string } | null> {
    const username = randomUsername();
    const created = await this.api.createUserRaw({
      ...this.projectFields(username, options),
      template: 'dind',
    });
    if (!(await this.finishCreatedProject(created, username, options))) {
      return null;
    }
    return { username, domain: await this.assignedDomain(username) };
  }

  async createDeployedUser(
    options: CreateUserOptions & { git_repo?: string; git_branch?: string } = {}
  ): Promise<{ username: string; domain: string } | null> {
    const username = randomUsername();
    const created = await this.api.createUserRaw({
      ...this.projectFields(username, options),
      git_repo: options.git_repo ?? configuredGitRepo(),
      git_branch: options.git_branch,
    });
    if (!(await this.finishCreatedProject(created, username, options))) {
      return null;
    }
    await waitForDeploy(this.api, username, {
      timeout: options.deployTimeout ?? this.settings.timing.deployTimeout,
    });
    return { username, domain: await this.assignedDomain(username) };
  }

  async deleteUser(username: string): Promise<void> {
    this.pending.delete(username);
    await this.cleanupUserDomains(username);

    try {
      await this.api.deleteUser(username);
    } catch (error) {
      // User might already be deleted
      console.warn(
        `Failed to delete user ${username}:`,
        error instanceof Error ? error.message : error
      );
    }
  }

  private track(username: string, options: CreateUserOptions): void {
    if (options.autoCleanup === false) {
      return;
    }
    this.pending.set(username, {
      preserveOnFailure: options.preserveOnFailure !== false,
    });
  }

  /**
   * POST /projects is 202 + a DeployProject task. 200/201 are the older
   * synchronous create. Anything else is a refusal (skip, do not track).
   */
  private async finishCreatedProject(
    created: { status: number; body: unknown },
    username: string,
    options: CreateUserOptions
  ): Promise<boolean> {
    if (!isProjectCreateOk(created.status)) {
      return false;
    }
    this.track(username, options);
    if (created.status !== 202) {
      return true;
    }
    const taskId = taskIdFromBody(created.body);
    if (taskId === undefined) {
      throw new Error('POST /projects returned 202 without a task id');
    }
    const task = await waitForTask(this.api, taskId, {
      timeout: options.deployTimeout ?? this.settings.timing.deployTimeout,
    });
    if (task.status !== 'completed') {
      throw new Error(`POST /projects task ${taskId} ended ${task.status}`);
    }
    return true;
  }

  /**
   * POST /projects without a domain (unless the caller named one).
   * `tunnel` is omitted by default so the engine tries panelalpha.online first.
   */
  private projectFields(
    username: string,
    options: CreateUserOptions
  ): { username: string; tunnel?: 'none' | 'panelalpha'; domain?: string } {
    return {
      username,
      ...(options.tunnel !== undefined ? { tunnel: options.tunnel } : {}),
      ...(options.domain ? { domain: options.domain } : {}),
    };
  }

  private async createAccount(
    options: CreateUserOptions
  ): Promise<{ username: string; domain: string }> {
    const username = randomUsername();
    const created = await this.api.createUser(this.projectFields(username, options));
    this.track(username, options);
    return { username, domain: created.data.domain };
  }

  private async assignedDomain(username: string): Promise<string> {
    const user = await this.api.getUser(username);
    const domain = user.data.domain;
    if (!domain) {
      throw new Error(`Project ${username} was created without a domain`);
    }
    return domain;
  }

  private generateSecurePassword(): string {
    return faker.internet.password({
      length: 16,
      memorable: false,
      pattern: /[A-Za-z0-9!@#$%^&*]/,
    });
  }

  private async cleanupUserDomains(username: string): Promise<void> {
    try {
      const response = await this.api.listUserDomains(username);
      const domains = response.data;

      if (!Array.isArray(domains)) {
        return;
      }

      for (const domain of domains) {
        if (domain.type === 'addon' || domain.type === 'alias') {
          try {
            await this.api.deleteDomain(username, domain.domain);
          } catch (error) {
            console.warn(`Failed to delete domain ${domain.domain}:`, error);
          }
        }
      }
    } catch (error) {
      console.warn(`Failed to cleanup domains for ${username}:`, error);
    }
  }
}
