import type { APIResponse } from '@playwright/test';
import type { RawApiResponse } from '@/types';
import { redactText } from './redact';
import { requestMethodOf } from './request-method';

/** How much of the response body is shown; the head, where the engine puts its message. */
const BODY_CHARS = 2000;

const REQUEST_ID_HEADERS = ['x-request-id', 'x-correlation-id'];

/** The first `max` characters of `text`, behind a note of how much was cut. */
function cutHead(text: string, max: number): string {
  return text.length <= max ? text : `${text.slice(0, max)} …[${text.length - max} chars cut]`;
}

/** A typed client's result that carries its payload as `data` rather than `body`. */
interface DataResponse {
  status: number;
  data?: unknown;
}

type ExpectableResponse = APIResponse | RawApiResponse | DataResponse;

function isApiResponse(response: ExpectableResponse): response is APIResponse {
  return typeof (response as APIResponse).status === 'function';
}

function describeExpected(expected: number | readonly number[]): string {
  return typeof expected === 'number' ? String(expected) : `one of ${expected.join(' | ')}`;
}

async function bodyOf(response: ExpectableResponse): Promise<string> {
  try {
    if (!isApiResponse(response)) {
      const payload: unknown = 'body' in response ? response.body : response.data;
      return typeof payload === 'string' ? payload : (JSON.stringify(payload) ?? String(payload));
    }
    return await response.text();
  } catch {
    return '<body unreadable>';
  }
}

/**
 * Asserts the HTTP status. On a mismatch the error names the request (method and URL),
 * the request id when the engine sent one, and the start of the response body, all
 * redacted. The body is read only then, so callers can still read it after a match.
 */
export async function expectStatus(
  response: ExpectableResponse,
  expected: number | readonly number[],
  message?: string
): Promise<void> {
  const actual = isApiResponse(response) ? response.status() : response.status;
  const allowed = typeof expected === 'number' ? [expected] : expected;
  if (allowed.includes(actual)) {
    return;
  }

  const lines: string[] = [];
  if (message) {
    lines.push(message);
  }
  lines.push(`Expected status ${describeExpected(expected)}, got ${actual}`);

  if (isApiResponse(response)) {
    const method = requestMethodOf(response) ?? '<method unknown>';
    lines.push(`  Request:  ${method} ${redactText(response.url())}`);
    const headers = response.headers();
    const idName = REQUEST_ID_HEADERS.find((name) => headers[name]);
    if (idName) {
      lines.push(`  Request-Id: ${redactText(headers[idName])}`);
    }
  }
  const body = cutHead(redactText(await bodyOf(response)), BODY_CHARS);
  lines.push(`  Response: ${body}`);

  throw new Error(lines.join('\n'));
}
