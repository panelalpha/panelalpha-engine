import type { TaskSnapshot } from '@/types';
import { Timeouts } from '@/config/timeouts';
import { waitForCondition } from './retry';

/** POST /projects queues DeployProject and returns this. */
export const PROJECT_CREATE_ACCEPTED = 202;

/** Legacy synchronous create (POST /users, or older POST /projects). */
export const PROJECT_CREATE_SYNC = [200, 201] as const;

/** Any HTTP status that means the account was stored. */
export const PROJECT_CREATE_OK: readonly number[] = [200, 201, 202];

export const TERMINAL_TASK_STATUSES = ['completed', 'failed', 'cancelled'] as const;

export type TerminalTaskStatus = (typeof TERMINAL_TASK_STATUSES)[number];

export function isTerminalTaskStatus(status: string): status is TerminalTaskStatus {
  return (TERMINAL_TASK_STATUSES as readonly string[]).includes(status);
}

export function isProjectCreateOk(status: number): boolean {
  return PROJECT_CREATE_OK.includes(status);
}

export interface TaskReader {
  getTaskRaw(id: number, afterId?: number): Promise<{ status: number; body: unknown }>;
}

export function taskFromBody(body: unknown): TaskSnapshot | undefined {
  if (typeof body !== 'object' || body === null) {
    return undefined;
  }
  const data = (body as { data?: unknown }).data;
  if (typeof data === 'object' && data !== null && typeof (data as TaskSnapshot).id === 'number') {
    return data as TaskSnapshot;
  }
  if (
    typeof (body as TaskSnapshot).id === 'number' &&
    typeof (body as TaskSnapshot).status === 'string'
  ) {
    return body as TaskSnapshot;
  }
  return undefined;
}

/**
 * The task a 202 answer queued. An answer whose `data` is the project (push,
 * staging) names the task in a top-level `task_id`, and that wins: the
 * project's own `id` is not a task id.
 */
export function taskIdFromBody(body: unknown): number | undefined {
  const named =
    typeof body === 'object' && body !== null ? (body as { task_id?: unknown }).task_id : undefined;
  if (typeof named === 'number') {
    return named;
  }
  const id = taskFromBody(body)?.id;
  return typeof id === 'number' ? id : undefined;
}

export async function waitForTask(
  api: TaskReader,
  id: number,
  options: { timeout?: number; interval?: number } = {}
): Promise<TaskSnapshot> {
  const timeout = options.timeout ?? Timeouts.deploy;
  const interval = options.interval ?? 2_000;
  let latest: TaskSnapshot | undefined;

  await waitForCondition(
    async () => {
      const response = await api.getTaskRaw(id);
      if (response.status !== 200) {
        return false;
      }
      latest = taskFromBody(response.body);
      return latest !== undefined && isTerminalTaskStatus(latest.status);
    },
    {
      timeout,
      interval,
      message: `Task ${id} did not reach a terminal status`,
      describeLast: () => (latest ? `status=${latest.status}` : 'task not readable yet'),
    }
  );

  if (!latest) {
    throw new Error(`Task ${id} produced no snapshot`);
  }
  return latest;
}
