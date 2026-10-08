import type { APIRequestContext, APIResponse } from '@playwright/test';

/**
 * Playwright's APIResponse knows its URL but not the method that produced it. The
 * request fixtures record it here so a failed status assertion can name the request.
 */
const methods = new WeakMap<object, string>();

const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'head'] as const;

/** Remembers which HTTP method produced `response`. */
export function rememberRequest<T extends object>(response: T, method: string): T {
  methods.set(response, method.toUpperCase());
  return response;
}

/** The method that produced `response`, or undefined when it was not recorded. */
export function requestMethodOf(response: object): string | undefined {
  return methods.get(response);
}

function fetchMethod(target: unknown, options: unknown): string {
  const fromOptions = (options as { method?: unknown } | undefined)?.method;
  if (typeof fromOptions === 'string' && fromOptions !== '') {
    return fromOptions;
  }
  const fromRequest = (target as { method?: unknown } | undefined)?.method;
  return typeof fromRequest === 'string' && fromRequest !== '' ? fromRequest : 'GET';
}

/**
 * `context` with every request method recording the verb of the response it returns.
 * Everything else (dispose, storageState, ...) passes through to the real context.
 */
export function withRequestMethods(context: APIRequestContext): APIRequestContext {
  return new Proxy(context, {
    get(target, prop) {
      const value: unknown = Reflect.get(target, prop, target);
      if (typeof value !== 'function') {
        return value;
      }
      const original = value as (...args: unknown[]) => unknown;
      const bound = (...args: unknown[]): unknown => original.apply(target, args);
      if (typeof prop === 'string' && (VERBS as readonly string[]).includes(prop)) {
        return async (...args: unknown[]): Promise<APIResponse> =>
          rememberRequest((await bound(...args)) as APIResponse, prop);
      }
      if (prop === 'fetch') {
        return async (...args: unknown[]): Promise<APIResponse> =>
          rememberRequest((await bound(...args)) as APIResponse, fetchMethod(args[0], args[1]));
      }
      return bound;
    },
  });
}
