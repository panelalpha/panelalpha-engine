import type { SystemChangeStatus } from '@/types';

/**
 * True once a background run (engine update, webserver change) has written its
 * exit code. `finished_at` is that file's mtime, so it can show a moment before
 * the code is readable.
 */
export function hasExitCode(change: SystemChangeStatus | null | undefined): boolean {
  return Boolean(change?.finished_at) && typeof change?.exit_code === 'number';
}

/** The run's exit code, where its log is, and the output tail the engine reports. */
export function describeFinishedChange(
  what: string,
  change: SystemChangeStatus | null | undefined
): string {
  if (!change) {
    return `${what}: the engine reports no run.`;
  }

  const lines = [
    `${what} exited with ${change.exit_code ?? 'no exit code'} (log: ${change.logs_path ?? 'unknown'}).`,
  ];
  for (const [stream, tail] of [
    ['stdout', change.tail_stdout],
    ['stderr', change.tail_stderr],
  ] as const) {
    const text = tail?.trim();
    if (text) {
      lines.push(`${stream} tail:`, ...text.split('\n').map((line) => `  ${line.trimEnd()}`));
    }
  }
  return lines.join('\n');
}
