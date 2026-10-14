import type { EngineApi } from '@/clients/engine-api';
import type { HostExec } from './host-exec';
import { redactText } from './redact';
import { safeMessage } from './task-diagnostics';

/** What a failed test reads back from the Engine, besides what the user factory attaches. */
export const CONTEXT_BUDGET_MS = 20_000;
export const CONTEXT_MAX_BYTES = 200_000;
export const SERVICE_LOG_LINES = 100;
const MAX_SERVICES_PER_PROJECT = 8;
const LOG_PAD_MS = 5_000;
const LOG_MAX_LINES = 400;
const LOG_READ_LINES = 3000;
const ENGINE_COMPOSE_FILE = '/opt/panelalpha/shared-hosting/docker-compose.yml';

export type ContextApi = Pick<
  EngineApi,
  'getUserRaw' | 'listContainersRaw' | 'getContainerLogsRaw'
>;

export interface ContextInput {
  api: ContextApi;
  /** Null when the runner cannot reach the Engine host. */
  hostExec: HostExec | null;
  /** Projects the test created, in creation order. */
  projects: readonly string[];
  /** The shared setup project the test used, if any. */
  setupUser?: string;
  /** Start of the test and the moment of collection, in epoch milliseconds. */
  window: { fromMs: number; toMs: number };
  budgetMs?: number;
  maxBytes?: number;
  now?: () => number;
}

interface Section {
  title: string;
  body: string;
}

/** Resolves with `fallback` when `work` is still running at `deadline`; never rejects. */
async function untilDeadline<T>(
  work: Promise<T>,
  deadline: number,
  now: () => number,
  fallback: (reason: string) => T
): Promise<T> {
  let timer: NodeJS.Timeout | undefined;
  const expired = new Promise<T>((resolve) => {
    timer = setTimeout(
      () => resolve(fallback('cut: the context budget ran out')),
      Math.max(0, deadline - now())
    );
  });
  const guarded = work.catch((error: unknown) => fallback(`unavailable: ${safeMessage(error)}`));
  try {
    return await Promise.race([guarded, expired]);
  } finally {
    clearTimeout(timer);
  }
}

function describeBody(body: unknown): string {
  if (typeof body === 'string') {
    return body;
  }
  try {
    return JSON.stringify(body, null, 2) ?? '';
  } catch {
    return '<body unreadable>';
  }
}

function lastLines(text: string, count: number): string {
  const lines = text.split('\n');
  return lines.length <= count ? text : lines.slice(-count).join('\n');
}

const LARAVEL_STAMP = /^\[(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\]/;

function stampMs(line: string): number | undefined {
  const m = LARAVEL_STAMP.exec(line);
  if (!m) {
    return undefined;
  }
  return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
}

/**
 * The entries of a Laravel log whose timestamp falls in `[fromMs - padMs, toMs + padMs]`.
 * Stamps are read as wall-clock time shifted by `clockOffsetMs` (host clock minus UTC epoch
 * clock). A line without a stamp (a stack frame) belongs to the entry above it. The last
 * `maxLines` matching lines are kept.
 */
export function filterLaravelLog(
  text: string,
  window: { fromMs: number; toMs: number },
  options: { padMs?: number; clockOffsetMs?: number; maxLines?: number } = {}
): string {
  const pad = options.padMs ?? LOG_PAD_MS;
  const offset = options.clockOffsetMs ?? 0;
  const from = window.fromMs - pad + offset;
  const to = window.toMs + pad + offset;
  const kept: string[] = [];
  let inside = false;
  for (const line of text.split('\n')) {
    const at = stampMs(line);
    if (at !== undefined) {
      inside = at >= from && at <= to;
    }
    if (inside) {
      kept.push(line);
    }
  }
  return lastLines(kept.join('\n'), options.maxLines ?? LOG_MAX_LINES);
}

/** Host wall clock minus the real clock, rounded to a quarter hour (a time zone offset). */
export function hostClockOffsetMs(hostNow: string, nowMs: number): number {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/.exec(hostNow.trim());
  if (!m) {
    return 0;
  }
  const wall = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
  const quarter = 15 * 60_000;
  return Math.round((wall - nowMs) / quarter) * quarter;
}

const HOST_LOG_SCRIPT =
  `date '+@NOW %Y-%m-%d %H:%M:%S'; cd /var/www/html/storage/logs 2>/dev/null && ` +
  `ls -t laravel*.log 2>/dev/null | head -2 | xargs -r tail -q -n ${LOG_READ_LINES}`;

async function readHostLog(
  hostExec: HostExec,
  window: { fromMs: number; toMs: number },
  now: () => number
): Promise<string> {
  const attempts: [string, string[]][] = [
    [
      'docker',
      ['compose', '-f', ENGINE_COMPOSE_FILE, 'exec', '-T', 'core', 'sh', '-c', HOST_LOG_SCRIPT],
    ],
    ['sh', ['-c', HOST_LOG_SCRIPT]],
  ];
  for (const [command, args] of attempts) {
    const result = await hostExec.run(command, args).catch(() => null);
    if (result?.exitCode !== 0 || !result.stdout.includes('@NOW ')) {
      continue;
    }
    const [first = '', ...rest] = result.stdout.split('\n');
    const offset = hostClockOffsetMs(first.replace('@NOW ', ''), now());
    const filtered = filterLaravelLog(rest.join('\n'), window, { clockOffsetMs: offset });
    return filtered.trim() === '' ? '(no laravel.log entries in the test window)' : filtered;
  }
  return 'storage/logs/laravel.log could not be read on the host';
}

async function projectSections(
  api: ContextApi,
  project: string,
  deadline: number,
  now: () => number
): Promise<Section[]> {
  const listed = await api.listContainersRaw(project);
  const services = Array.isArray((listed.body as { data?: unknown } | null)?.data)
    ? (listed.body as { data: unknown[] }).data
        .map((item) => (item as { Service?: unknown; Name?: unknown } | null)?.Service)
        .filter((name): name is string => typeof name === 'string' && name !== '')
    : [];
  if (listed.status !== 200 || services.length === 0) {
    return [
      {
        title: `containers ${project}`,
        body: `GET projects/${project}/containers -> ${listed.status}; no container services (not a DinD project, or none up)`,
      },
    ];
  }
  const unique = [...new Set(services)].slice(0, MAX_SERVICES_PER_PROJECT);
  return Promise.all(
    unique.map(async (service): Promise<Section> => {
      const title = `service log ${project}/${service} (last ${SERVICE_LOG_LINES} lines)`;
      const body = await untilDeadline(
        api.getContainerLogsRaw(project, service, SERVICE_LOG_LINES).then((response) => {
          const data = (response.body as { data?: unknown } | null)?.data;
          return response.status === 200 && typeof data === 'string'
            ? lastLines(data, SERVICE_LOG_LINES)
            : `GET projects/${project}/containers/${service}/logs -> ${response.status}\n${describeBody(response.body)}`;
        }),
        deadline,
        now,
        (reason) => `(${reason})`
      );
      return { title, body };
    })
  );
}

/**
 * Reads the Engine's side of a failed test: service logs of the projects it created, the shared
 * setup project's record, and the Engine's laravel.log for the test's time window. Every piece is
 * best effort under one shared deadline; a piece that fails or runs out of time becomes a note.
 * The result is redacted and capped at `maxBytes`. Never throws.
 */
export async function collectEngineContext(input: ContextInput): Promise<string> {
  const now = input.now ?? Date.now;
  const budgetMs = input.budgetMs ?? CONTEXT_BUDGET_MS;
  const maxBytes = input.maxBytes ?? CONTEXT_MAX_BYTES;
  const deadline = now() + budgetMs;

  const jobs: Promise<Section[]>[] = [];
  const piece = (title: string, work: () => Promise<Section[]>): void => {
    jobs.push(
      untilDeadline(Promise.resolve().then(work), deadline, now, (reason): Section[] => [
        { title, body: `(${reason})` },
      ])
    );
  };

  for (const project of input.projects) {
    piece(`containers ${project}`, () => projectSections(input.api, project, deadline, now));
  }
  if (input.setupUser !== undefined) {
    const setupUser = input.setupUser;
    piece(`setup user ${setupUser}`, async () => {
      const response = await input.api.getUserRaw(setupUser);
      return [
        {
          title: `setup user ${setupUser}`,
          body: `GET projects/${setupUser} -> ${response.status}\n${describeBody(response.body)}`,
        },
      ];
    });
  }
  piece('laravel.log', async () => [
    {
      title: 'laravel.log in the test window',
      body: input.hostExec
        ? await readHostLog(input.hostExec, input.window, now)
        : 'host log not available (no host access from this runner)',
    },
  ]);

  const sections = (await Promise.all(jobs)).flat();
  const perSection = Math.max(1_000, Math.floor(maxBytes / Math.max(1, sections.length)) - 200);
  const header =
    `engine context for a failed test\n` +
    `window ${new Date(input.window.fromMs).toISOString()} .. ${new Date(input.window.toMs).toISOString()}\n` +
    `projects: ${input.projects.length > 0 ? input.projects.join(', ') : '(none)'}`;
  const parts = sections.map((section) => {
    const body = redactText(section.body);
    const capped =
      body.length <= perSection
        ? body
        : `…[${body.length - perSection} chars cut]\n${body.slice(body.length - perSection)}`;
    return `===== ${redactText(section.title)} =====\n${capped}`;
  });
  const text = [header, ...parts].join('\n\n');
  return text.length <= maxBytes
    ? text
    : `${text.slice(0, maxBytes)}\n…[context cut at ${maxBytes} bytes]`;
}
