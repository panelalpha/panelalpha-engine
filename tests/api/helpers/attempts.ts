import { test } from '@playwright/test';
import { redactText } from './redact';

const MAX_REASON_LENGTH = 120;

/** The first line of a message, cut to a length that fits an annotation. */
function shorten(text: string): string {
  const line = redactText(text).trim().split(/\r?\n/, 1)[0] ?? '';
  return line.length > MAX_REASON_LENGTH ? `${line.slice(0, MAX_REASON_LENGTH - 1)}…` : line;
}

/**
 * A short reason for a retried HTTP answer: the status and the first line of the
 * body's `message`. Nothing else of the request or response is kept.
 */
export function httpReason(status: number, body?: unknown): string {
  const candidate =
    typeof body === 'object' && body !== null
      ? (body as { message?: unknown }).message
      : typeof body === 'string'
        ? body
        : undefined;
  const message = typeof candidate === 'string' ? shorten(candidate) : '';
  return shorten(message === '' ? `HTTP ${status}` : `HTTP ${status} ${message}`);
}

/** A short reason for a retried error: its first message line. */
export function errorReason(error: unknown): string {
  return shorten(error instanceof Error ? error.message : String(error));
}

/** Distinct reasons in the order first seen, each cut short. */
export function distinctReasons(reasons: readonly string[]): string[] {
  return [...new Set(reasons.map(shorten).filter((reason) => reason !== ''))];
}

/** "3 attempts; reasons: a; b", for an error message or an annotation. */
export function describeAttempts(attempts: number, reasons: readonly string[]): string {
  const distinct = distinctReasons(reasons);
  const count = `${attempts} attempt${attempts === 1 ? '' : 's'}`;
  return distinct.length === 0 ? count : `${count}; reasons: ${distinct.join('; ')}`;
}

/**
 * Notes on the running test that an operation was retried, so a report shows
 * what a green test went through. Silent for a call that succeeded first try,
 * and a no-op outside a running test (unit code, global setup).
 */
export function recordAttempts(operation: string, attempts: number, reasons: string[]): void {
  if (attempts <= 1 && distinctReasons(reasons).length === 0) {
    return;
  }
  try {
    test.info().annotations.push({
      type: 'retry',
      description: `${operation}: ${describeAttempts(attempts, reasons)}`,
    });
  } catch {
    // Not inside a test.
  }
}

/** Notes any other fact about a retry, such as a cancelled task or a longer timeout. */
export function recordRetryNote(operation: string, note: string): void {
  try {
    test.info().annotations.push({ type: 'retry', description: `${operation}: ${shorten(note)}` });
  } catch {
    // Not inside a test.
  }
}
