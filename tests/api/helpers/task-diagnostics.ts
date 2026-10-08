import type { TaskLogLine, TaskSnapshot } from '@/types';
import { redactAndTruncate, redactText } from './redact';
import { taskFromBody } from './task-helpers';

/** The slice of the client diagnostics need; the deploy log is optional. */
export interface TaskDiagnosticsApi {
  getTaskRaw(id: number, afterId?: number): Promise<{ status: number; body: unknown }>;
  listTaskLogsRaw(
    id: number,
    params?: { after_id?: number }
  ): Promise<{ status: number; body: unknown }>;
  getDeployLogRaw?(username: string): Promise<{ status: number; body: unknown }>;
}

export interface TaskDiagnosticsOptions {
  /** Project whose deploy log is read when the task kept no tail of its own. */
  username?: string;
  /** Pages of `GET tasks/{id}/logs` to read after the snapshot. Default 10. */
  maxPages?: number;
  /** Total time for everything below, in ms. Default 20 000. */
  budgetMs?: number;
  /** Log lines kept, counted from the end. Default 80. */
  tailLines?: number;
}

export interface TaskDiagnostics {
  taskId: number;
  username?: string;
  status?: string;
  error?: string;
  timings: { queuedAt?: string; startedAt?: string; finishedAt?: string };
  /** Rendered task log lines, oldest first. */
  logTail: string[];
  /** True when the Engine copied the deploy log tail onto the task. */
  logTailKept: boolean;
  /** Last lines of the project's deploy log, when it still exists. */
  deployLogTail: string[];
  /** What could not be read, and why. */
  notes: string[];
}

const DEFAULT_PAGES = 10;
const DEFAULT_BUDGET_MS = 20_000;
const DEFAULT_TAIL_LINES = 80;
const SHORT_TAIL_LINES = 40;
const MAX_LINE_CHARS = 400;
const SHORT_MAX_CHARS = 6_000;
const FULL_MAX_CHARS = 60_000;
const TAIL_HEADER = /^Last \d+ lines of the deploy log/;

class BudgetExceeded extends Error {}

/**
 * What the Engine still knows about a task: its error, timings and the end of its log.
 * Never throws; whatever cannot be read becomes a note.
 */
export async function collectTaskDiagnostics(
  api: TaskDiagnosticsApi,
  taskId: number,
  options: TaskDiagnosticsOptions = {}
): Promise<TaskDiagnostics> {
  const diagnostics: TaskDiagnostics = {
    taskId,
    username: options.username,
    timings: {},
    logTail: [],
    logTailKept: false,
    deployLogTail: [],
    notes: [],
  };
  try {
    await gather(api, diagnostics, options);
  } catch (error) {
    diagnostics.notes.push(`diagnostics stopped: ${describe(error)}`);
  }
  return diagnostics;
}

async function gather(
  api: TaskDiagnosticsApi,
  d: TaskDiagnostics,
  options: TaskDiagnosticsOptions
): Promise<void> {
  const deadline = Date.now() + (options.budgetMs ?? DEFAULT_BUDGET_MS);
  const maxPages = options.maxPages ?? DEFAULT_PAGES;
  const keep = options.tailLines ?? DEFAULT_TAIL_LINES;
  const rendered: { text: string; kept: boolean }[] = [];
  let cursor = 0;

  try {
    const response = await within(deadline, api.getTaskRaw(d.taskId));
    const task = response.status === 200 ? taskFromBody(response.body) : undefined;
    if (task) {
      readTask(d, task);
      for (const line of task.logs ?? []) {
        rendered.push(renderLine(line));
      }
      cursor = lastId(task.logs, task.next_after_id, cursor);
    } else {
      d.notes.push(`GET tasks/${d.taskId} answered ${response.status}`);
    }
  } catch (error) {
    d.notes.push(`GET tasks/${d.taskId} failed: ${describe(error)}`);
  }

  try {
    for (let page = 0; page < maxPages; page++) {
      const response = await within(deadline, api.listTaskLogsRaw(d.taskId, { after_id: cursor }));
      const body = response.body as { data?: TaskLogLine[]; meta?: { next_after_id?: number } };
      if (response.status !== 200 || !Array.isArray(body?.data)) {
        d.notes.push(`GET tasks/${d.taskId}/logs answered ${response.status}`);
        break;
      }
      if (body.data.length === 0) {
        break;
      }
      for (const line of body.data) {
        rendered.push(renderLine(line));
      }
      const next = lastId(body.data, body.meta?.next_after_id, cursor);
      if (next <= cursor) {
        break;
      }
      cursor = next;
      if (page === maxPages - 1) {
        d.notes.push(`task log paging stopped after ${maxPages} pages`);
      }
    }
  } catch (error) {
    d.notes.push(`GET tasks/${d.taskId}/logs failed: ${describe(error)}`);
  }

  d.logTailKept = rendered.some((line) => line.kept);
  d.logTail = rendered.slice(-keep).map((line) => line.text);

  const username = d.username;
  if (!d.logTailKept && username && api.getDeployLogRaw) {
    try {
      const response = await within(deadline, api.getDeployLogRaw(username));
      const lines = (response.body as { data?: { lines?: unknown[] } })?.data?.lines;
      if (response.status === 200 && Array.isArray(lines)) {
        d.deployLogTail = lines.slice(-keep).map((line) => renderLine(line).text);
      } else {
        d.notes.push(`deploy log of ${username} answered ${response.status} (project gone?)`);
      }
    } catch (error) {
      d.notes.push(`deploy log of ${username} failed: ${describe(error)}`);
    }
  }
}

function readTask(d: TaskDiagnostics, task: TaskSnapshot): void {
  d.status = task.status;
  d.username ??= task.username ?? undefined;
  d.error = errorOf(task.details);
  d.timings = {
    queuedAt: task.queued_at ?? undefined,
    startedAt: task.started_at ?? undefined,
    finishedAt: task.completed_at ?? task.failed_at ?? task.cancelled_at ?? undefined,
  };
}

function errorOf(details: unknown): string | undefined {
  if (typeof details === 'string') {
    try {
      return errorOf(JSON.parse(details));
    } catch {
      return details;
    }
  }
  const error = (details as { error?: unknown } | null)?.error;
  if (typeof error === 'string') {
    return error;
  }
  return error === undefined || error === null ? undefined : JSON.stringify(error);
}

function lastId(lines: TaskLogLine[] | undefined, next: number | undefined, fallback: number) {
  const last = lines && lines.length > 0 ? lines[lines.length - 1].id : undefined;
  return Math.max(
    fallback,
    typeof next === 'number' ? next : 0,
    typeof last === 'number' ? last : 0
  );
}

/** One readable line. `log` is often a JSON object with msg, level and stage. */
function renderLine(line: unknown): { text: string; kept: boolean } {
  if (typeof line === 'string') {
    return { text: line, kept: TAIL_HEADER.test(line) };
  }
  const entry = (line ?? {}) as Record<string, unknown>;
  let fields: Record<string, unknown> = entry;
  const raw = entry.log;
  if (typeof raw === 'string' && raw.trimStart().startsWith('{')) {
    try {
      fields = { ...entry, ...(JSON.parse(raw) as Record<string, unknown>) };
    } catch {
      // keep the raw text
    }
  }
  const message =
    typeof fields.msg === 'string'
      ? fields.msg
      : typeof raw === 'string'
        ? raw
        : JSON.stringify(line);
  const level = typeof fields.level === 'string' ? fields.level : '';
  const stage = typeof fields.stage === 'string' ? fields.stage : '';
  const prefix = [level && `[${level}]`, stage && `[${stage}]`].filter(Boolean).join('');
  return {
    text: prefix ? `${prefix} ${message}` : message,
    kept: fields.deploy_log_tail === true || TAIL_HEADER.test(message),
  };
}

/** Rejects when the deadline passes first, so no call can outlive the budget. */
async function within<T>(deadline: number, work: Promise<T>): Promise<T> {
  const remaining = deadline - Date.now();
  if (remaining <= 0) {
    // The read ran out of budget; its late rejection has no caller left to report to.
    void work.catch(() => undefined);
    throw new BudgetExceeded('time budget used up');
  }
  let timer: NodeJS.Timeout | undefined;
  try {
    return await Promise.race([
      work,
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new BudgetExceeded('time budget used up')), remaining);
      }),
    ]);
  } finally {
    clearTimeout(timer);
    // A read that lost the race to the deadline has no caller left; swallow its late rejection.
    void work.catch(() => undefined);
  }
}

function describe(error: unknown): string {
  return error instanceof Error ? error.message.split('\n')[0] : String(error);
}

function clip(lines: readonly string[]): string[] {
  return lines.map((line) =>
    line.length > MAX_LINE_CHARS ? `${line.slice(0, MAX_LINE_CHARS)}…` : line
  );
}

function render(d: TaskDiagnostics, tailLines: number, maxChars: number, full: boolean): string {
  const parts: string[] = [];
  if (d.username) {
    parts.push(`user: ${d.username}`);
  }
  if (full) {
    const { queuedAt, startedAt, finishedAt } = d.timings;
    parts.push(
      `task ${d.taskId}: status=${d.status ?? 'unknown'} queued=${queuedAt ?? '-'} started=${startedAt ?? '-'} finished=${finishedAt ?? '-'}`
    );
  }
  parts.push(`error: ${d.error ?? '(none on the task)'}`);

  const tail = d.logTail.slice(-tailLines);
  if (tail.length > 0) {
    const label = d.logTailKept ? 'deploy log tail kept on the task' : 'task log';
    parts.push(`${label} (last ${tail.length} lines):`, ...clip(tail).map((line) => `  ${line}`));
  } else if (d.deployLogTail.length === 0) {
    parts.push('(no deploy log tail kept on the task)');
  }
  const deployLog = d.deployLogTail.slice(-tailLines);
  if (deployLog.length > 0) {
    parts.push(
      `deploy log (last ${deployLog.length} lines):`,
      ...clip(deployLog).map((l) => `  ${l}`)
    );
  }
  if (d.notes.length > 0) {
    parts.push(`diagnostics notes: ${d.notes.join('; ')}`);
  }
  return redactAndTruncate(parts.join('\n'), maxChars);
}

/** Short, redacted text for an error message: the error and the last ~40 lines. */
export function formatTaskDiagnostics(d: TaskDiagnostics): string {
  return render(d, SHORT_TAIL_LINES, SHORT_MAX_CHARS, false);
}

/** Fuller, redacted text for an attachment: timings and the whole kept tail. */
export function formatTaskDiagnosticsReport(d: TaskDiagnostics): string {
  return render(d, Number.MAX_SAFE_INTEGER, FULL_MAX_CHARS, true);
}

/** A redacted one-liner for any error text that is about to be shown. */
export function safeMessage(error: unknown): string {
  return redactText(error instanceof Error ? error.message : String(error));
}
