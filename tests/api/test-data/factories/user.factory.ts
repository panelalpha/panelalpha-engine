import { faker } from '@faker-js/faker';
import { test } from '@playwright/test';
import { type EngineApi } from '@/clients/engine-api';
import { type TaskSnapshot, type UserCredentials } from '@/types';
import { getSettings } from '@/config/settings';
import { configuredGitRepo, waitForDeploy } from '@/helpers/deploy-helpers';
import { isProjectCreateOk, taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { randomUsername } from '@/helpers/random';
import { redactText } from '@/helpers/redact';
import { registerSecret } from '@/helpers/secret-registry';
import { recordResource, resolvePreservePolicy } from '@/helpers/resource-registry';
import {
  collectTaskDiagnostics,
  formatTaskDiagnostics,
  formatTaskDiagnosticsReport,
  safeMessage,
} from '@/helpers/task-diagnostics';

export interface CreateUserOptions {
  /**
   * Whether the `userFactory` fixture deletes this user when the test ends.
   *
   * Pass `false` for a user that has to outlive a single test — a `serial`
   * describe block whose steps build on each other, for instance. Such a user
   * must be deleted by the test that finishes with it; the worker deletes
   * whatever is left when it shuts down.
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
   * Only with PRESERVE_ON_FAILURE=1 outside CI: a failed test keeps the user
   * for inspection. Pass `false` for Online/hub names that must never be kept.
   */
  preserveOnFailure?: boolean;
  /**
   * How long to wait for the create task and, for a git deploy, the deploy log.
   * Defaults to `DEPLOY_TIMEOUT` (10 minutes). Supported-app deploys pass a
   * longer budget; a Java or Go build legitimately outlasts the default.
   */
  deployTimeout?: number;
}

/**
 * Deploy a real application the way an operator would: let the engine name the
 * project through DomainPlan -- `panelalpha.online` when the host has a public
 * address, its `panelalpha.direct` name otherwise -- and always delete it
 * afterwards, so an allocated public name is never kept.
 */
export const APPLICATION_DEPLOY_USER = {
  preserveOnFailure: false,
} as const satisfies CreateUserOptions;

/**
 * Users made with `autoCleanup: false` that no test has deleted yet. Lives as long as the
 * worker process, so the worker fixture can delete what a failed serial chain left behind.
 */
const manualCleanupUsers = new Set<string>();

/** Usernames of `autoCleanup: false` users still on the Engine as far as this worker knows. */
export function manualCleanupPending(): string[] {
  return [...manualCleanupUsers];
}

const DIAGNOSTICS_BUDGET_MS = 15_000;
const DEPLOY_LOG_TAIL_LINES = 40;

export class UserFactory {
  /** Read on first use: building a factory must not need the Engine's env (the context fixture builds one for every test). */
  private get settings(): ReturnType<typeof getSettings> {
    return getSettings();
  }

  /** Users created here that nothing has deleted yet — drained by the fixture. */
  private readonly pending = new Map<string, { preserveOnFailure: boolean }>();

  /** The create task of every project made here, by username. */
  private readonly createTasks = new Map<string, { taskId: number; task?: TaskSnapshot }>();

  constructor(private api: EngineApi) {}

  /** The create task of a project made here: its id and the last snapshot read. */
  createTaskOf(username: string): { taskId: number; task?: TaskSnapshot } | undefined {
    return this.createTasks.get(username);
  }

  /** Usernames still awaiting cleanup, oldest first. */
  get pendingCleanup(): string[] {
    return [...this.pending.keys()];
  }

  /** True when the user did not opt out of being kept after a failure. */
  shouldPreserveOnFailure(username: string): boolean {
    return this.pending.get(username)?.preserveOnFailure !== false;
  }

  /** True when a failed test leaves this user on the engine: an explicit local opt-in only. */
  shouldKeepAfterFailure(username: string, env: NodeJS.ProcessEnv = process.env): boolean {
    return resolvePreservePolicy(env).keep && this.shouldPreserveOnFailure(username);
  }

  /**
   * Ends a test's users. After a failure their state is captured first; they are then
   * deleted, except those kept by PRESERVE_ON_FAILURE=1 outside CI. Every deletion is
   * tried before the errors are returned.
   */
  async settle(
    failed: boolean,
    env: NodeJS.ProcessEnv = process.env
  ): Promise<{ kept: string[]; errors: string[] }> {
    const kept: string[] = [];
    const errors: string[] = [];
    for (const username of this.pendingCleanup) {
      if (failed) {
        await this.attachFailureDiagnostics(username);
      }
      if (failed && this.shouldKeepAfterFailure(username, env)) {
        kept.push(username);
        recordResource({ key: username, event: 'preserved', detail: 'PRESERVE_ON_FAILURE=1' });
        continue;
      }
      await this.deleteUser(username).catch((error: unknown) => {
        errors.push(safeMessage(error));
      });
    }
    return { kept, errors };
  }

  /**
   * Attaches the project record and the tail of its deploy log, redacted. Best effort within
   * a fixed budget; whatever goes wrong becomes a note, never an error.
   */
  async attachFailureDiagnostics(
    username: string,
    budgetMs: number = DIAGNOSTICS_BUDGET_MS
  ): Promise<void> {
    let timer: NodeJS.Timeout | undefined;
    const expired = new Promise<string>((resolve) => {
      timer = setTimeout(() => resolve(`(diagnostics cut after ${budgetMs} ms)`), budgetMs);
    });
    const notes: string[] = [];
    const work = this.collectFailureDiagnostics(username, notes).then(
      () => '',
      (error: unknown) => `(diagnostics unavailable: ${safeMessage(error)})`
    );
    const ended = await Promise.race([work, expired]);
    clearTimeout(timer);
    if (ended !== '') {
      notes.push(ended);
    }
    if (notes.length > 0) {
      await attachText(`project-${username}-diagnostics`, notes.join('\n\n'));
    }
  }

  private async collectFailureDiagnostics(username: string, notes: string[]): Promise<void> {
    const project = await this.api.getUserRaw(username);
    notes.push(`GET projects/${username} -> ${project.status}\n${describeBody(project.body)}`);
    if (project.status !== 200) {
      return;
    }
    const log = await this.api.getDeployLogRaw(username);
    if (log.status !== 200) {
      return;
    }
    const lines = (log.body as { data?: { lines?: unknown } } | null)?.data?.lines;
    if (Array.isArray(lines) && lines.length > 0) {
      const tail = lines
        .slice(-DEPLOY_LOG_TAIL_LINES)
        .map((line) => (typeof line === 'string' ? line : JSON.stringify(line)))
        .join('\n');
      notes.push(
        `deploy log, last ${Math.min(lines.length, DEPLOY_LOG_TAIL_LINES)} lines\n${redactText(tail)}`
      );
    }
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
   * Empty DinD account — no git clone. Returns null only when this engine
   * answers 422 with the `template_not_found` problem for `template`; any other
   * refusal throws.
   */
  async createDindUser(
    options: CreateUserOptions = {}
  ): Promise<{ username: string; domain: string } | null> {
    const username = this.begin(options);
    return this.guardCreate(username, async () => {
      const created = await this.api.createUserRaw({
        ...this.projectFields(username, options),
        template: 'dind',
      });
      if (!(await this.finishCreatedProject(created, username, options))) {
        return this.refuse(username);
      }
      return { username, domain: await this.assignedDomain(username) };
    });
  }

  async createDeployedUser(
    options: CreateUserOptions & { git_repo?: string; git_branch?: string } = {}
  ): Promise<{ username: string; domain: string } | null> {
    const username = this.begin(options);
    return this.guardCreate(username, async () => {
      const created = await this.api.createUserRaw({
        ...this.projectFields(username, options),
        git_repo: options.git_repo ?? configuredGitRepo(),
        git_branch: options.git_branch,
      });
      if (!(await this.finishCreatedProject(created, username, options))) {
        return this.refuse(username);
      }
      try {
        await waitForDeploy(this.api, username, {
          timeout: options.deployTimeout ?? this.settings.timing.deployTimeout,
        });
      } catch (error) {
        const taskId = this.createTasks.get(username)?.taskId;
        throw await this.withDiagnostics(
          error,
          `Deploy of ${username} did not finish`,
          username,
          taskId
        );
      }
      return { username, domain: await this.assignedDomain(username) };
    });
  }

  /**
   * A user the test already deleted is fine; one still on the engine afterwards throws and
   * stays tracked, so the next attempt (the fixture, the worker) tries again.
   */
  async deleteUser(username: string): Promise<void> {
    recordResource({ key: username, event: 'deleting' });
    try {
      await this.cleanupUserDomains(username);
      await this.api.deleteUserSafe(username);
    } catch (error) {
      recordResource({ key: username, event: 'delete-failed', detail: safeMessage(error) });
      throw error;
    }
    this.pending.delete(username);
    manualCleanupUsers.delete(username);
    recordResource({ key: username, event: 'deleted' });
  }

  /**
   * Picks the name and tracks it before anything is sent: the name is made here, so a
   * create that fails half way still leaves a name to delete.
   */
  private begin(options: CreateUserOptions): string {
    const username = randomUsername();
    if (options.autoCleanup === false) {
      manualCleanupUsers.add(username);
    } else {
      this.pending.set(username, { preserveOnFailure: options.preserveOnFailure !== false });
    }
    recordResource({ key: username, event: 'creating' });
    return username;
  }

  /** Records a thrown create and keeps the user tracked so teardown deletes it. */
  private async guardCreate<T>(username: string, create: () => Promise<T>): Promise<T> {
    try {
      const result = await create();
      if (result !== null) {
        recordResource({
          key: username,
          event: 'created',
          taskId: this.createTasks.get(username)?.taskId,
        });
      }
      return result;
    } catch (error) {
      recordResource({
        key: username,
        event: 'create-failed',
        taskId: this.createTasks.get(username)?.taskId,
        detail: safeMessage(error),
      });
      throw error;
    }
  }

  /** The Engine refused the create: nothing exists, so nothing is tracked. */
  private refuse(username: string): null {
    this.pending.delete(username);
    manualCleanupUsers.delete(username);
    recordResource({ key: username, event: 'refused' });
    return null;
  }

  /**
   * POST /projects is 202 + a DeployProject task. 200/201 are the older
   * synchronous create. The one refusal that returns false (the caller skips,
   * nothing is tracked) is 422 with the `template_not_found` problem for the
   * `template` field (ProvisionChecks::template). Any other status throws.
   */
  private async finishCreatedProject(
    created: { status: number; body: unknown },
    username: string,
    options: CreateUserOptions
  ): Promise<boolean> {
    if (!isProjectCreateOk(created.status)) {
      if (isTemplateUnavailable(created)) {
        return false;
      }
      throw new Error(`POST /projects answered ${created.status}\n${describeBody(created.body)}`);
    }
    if (created.status !== 202) {
      return true;
    }
    const taskId = taskIdFromBody(created.body);
    if (taskId === undefined) {
      throw new Error('POST /projects returned 202 without a task id');
    }
    this.createTasks.set(username, { taskId });
    let task: TaskSnapshot;
    try {
      task = await waitForTask(this.api, taskId, {
        timeout: options.deployTimeout ?? this.settings.timing.deployTimeout,
      });
    } catch (error) {
      throw await this.withDiagnostics(
        error,
        `POST /projects task ${taskId} did not finish`,
        username,
        taskId
      );
    }
    this.createTasks.set(username, { taskId, task });
    if (task.status !== 'completed') {
      const head = `POST /projects task ${taskId} ended ${task.status}`;
      throw new Error(`${head}\n${await this.diagnose(taskId, username)}`);
    }
    return true;
  }

  /** The original error behind `head`, with what the task kept; the original text comes first. */
  private async withDiagnostics(
    error: unknown,
    head: string,
    username: string,
    taskId: number | undefined
  ): Promise<Error> {
    const parts = [head, safeMessage(error)];
    if (taskId !== undefined) {
      parts.push(await this.diagnose(taskId, username));
    }
    return new Error(parts.join('\n'), { cause: error });
  }

  /**
   * Reads what the Engine kept about a task and attaches the full text. Runs before the
   * fixture deletes the project, and never throws, so the failure it explains stays visible.
   */
  private async diagnose(taskId: number, username: string): Promise<string> {
    try {
      const diagnostics = await collectTaskDiagnostics(this.api, taskId, { username });
      await attachText(`task-${taskId}-diagnostics`, formatTaskDiagnosticsReport(diagnostics));
      return formatTaskDiagnostics(diagnostics);
    } catch (error) {
      return `(task diagnostics unavailable: ${safeMessage(error)})`;
    }
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
    const username = this.begin(options);
    return this.guardCreate(username, async () => {
      const created = await this.api.createUser(this.projectFields(username, options));
      return { username, domain: created.data.domain };
    });
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
    const password = faker.internet.password({
      length: 16,
      memorable: false,
      pattern: /[A-Za-z0-9!@#$%^&*]/,
    });
    registerSecret(password);
    return password;
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

/**
 * The 422 body ProblemException renders (Handler::invalidJson) carries
 * `problems: [{ field, code, message }]`; an unavailable template is
 * `{ field: 'template', code: 'template_not_found' }`.
 */
function isTemplateUnavailable(response: { status: number; body: unknown }): boolean {
  if (response.status !== 422) {
    return false;
  }
  const problems = (response.body as { problems?: unknown } | null)?.problems;
  return (
    Array.isArray(problems) &&
    problems.some((problem: { field?: unknown; code?: unknown } | null) => {
      return problem?.field === 'template' && problem.code === 'template_not_found';
    })
  );
}

const BODY_CHARS = 2000;

/** The start of a response body, redacted. */
function describeBody(body: unknown): string {
  let text: string;
  try {
    text = typeof body === 'string' ? body : (JSON.stringify(body) ?? String(body));
  } catch {
    text = '<body unreadable>';
  }
  const redacted = redactText(text);
  return redacted.length <= BODY_CHARS
    ? redacted
    : `${redacted.slice(0, BODY_CHARS)} …[${redacted.length - BODY_CHARS} chars cut]`;
}

/** Attaches to the running test; outside a test there is nowhere to attach to. */
async function attachText(name: string, body: string): Promise<void> {
  try {
    await test.info().attach(name, { body, contentType: 'text/plain' });
  } catch {
    // not inside a test
  }
}
