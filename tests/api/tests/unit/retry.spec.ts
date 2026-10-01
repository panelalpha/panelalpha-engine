import { expect, test } from '@/fixtures/test-options';
import { isEngineUnreachable } from '@/helpers/retry';

function apiCallFailed(status: number): Error {
  return new Error(
    `API call failed\n  URL:      https://engine.test/api/system/info\n  Expected: 200  —  Got: ${status}\n  Response: `
  );
}

test.describe('isEngineUnreachable', () => {
  test('a dropped or refused connection is a restart, not a failure', () => {
    expect(isEngineUnreachable(new Error('apiRequestContext.get: socket hang up'))).toBe(true);
    expect(isEngineUnreachable(new Error('apiRequestContext.get: read ECONNRESET'))).toBe(true);
    expect(
      isEngineUnreachable(new Error('apiRequestContext.get: connect ECONNREFUSED 10.0.0.1:2011'))
    ).toBe(true);
  });

  test('a proxy answering 502, 503 or 504 is a restart', () => {
    for (const status of [502, 503, 504]) {
      expect(isEngineUnreachable(apiCallFailed(status))).toBe(true);
    }
  });

  test('an answer from the engine itself is not', () => {
    for (const status of [401, 404, 422, 500, 5020]) {
      expect(isEngineUnreachable(apiCallFailed(status))).toBe(false);
    }
    expect(isEngineUnreachable(new Error('expect(received).toBe(expected)'))).toBe(false);
  });
});
