// The base `test` object is what the recorder reads `info` from.
// eslint-disable-next-line no-restricted-imports
import { test as base } from '@playwright/test';
import { expect, test } from '@/fixtures/test-options';
import { describeAttempts, httpReason, recordAttempts } from '@/helpers/attempts';

/** The retry annotations the running test carries. */
function retries(): string[] {
  return test
    .info()
    .annotations.filter((a) => a.type === 'retry')
    .map((a) => a.description ?? '');
}

test.describe('recordAttempts', () => {
  test('notes the attempts and the distinct reasons', () => {
    recordAttempts('PUT x', 3, ['HTTP 503', 'ECONNRESET', 'HTTP 503']);

    expect(retries()).toEqual(['PUT x: 3 attempts; reasons: HTTP 503; ECONNRESET']);
  });

  test('a single successful call leaves no annotation', () => {
    recordAttempts('PUT x', 1, []);

    expect(retries()).toEqual([]);
  });

  test('a single failed call with a reason is still noted', () => {
    recordAttempts('PUT x', 1, ['HTTP 500']);

    expect(retries()).toEqual(['PUT x: 1 attempt; reasons: HTTP 500']);
  });

  test('cuts each reason to its first line and about 120 characters', () => {
    recordAttempts('PUT x', 2, [`first line\nsecond line ${'a'.repeat(300)}`, 'b'.repeat(300)]);

    const [description] = retries();
    expect(description).toContain('reasons: first line; ');
    expect(description).not.toContain('second line');
    expect((description ?? '').length).toBeLessThan(300);
  });

  test('is a no-op outside a running test', () => {
    const info = base.info.bind(base);
    base.info = () => {
      throw new Error('Cannot be called outside a test');
    };
    try {
      expect(() => recordAttempts('PUT x', 4, ['HTTP 503'])).not.toThrow();
    } finally {
      base.info = info;
    }
    expect(retries()).toEqual([]);
  });
});

test.describe('attempt descriptions', () => {
  test('httpReason keeps the status and the first line of the message', () => {
    expect(httpReason(503)).toBe('HTTP 503');
    expect(httpReason(422, { message: 'Container is restarting\nmore' })).toBe(
      'HTTP 422 Container is restarting'
    );
  });

  test('describeAttempts states the count without reasons', () => {
    expect(describeAttempts(5, [])).toBe('5 attempts');
  });

  test('a secret in an engine message never reaches a reason', () => {
    const saved = process.env.API_TOKEN;
    process.env.API_TOKEN = 'tok_PLANTEDATTEMPTS1234';
    try {
      const reason = httpReason(502, { message: 'upstream rejected tok_PLANTEDATTEMPTS1234' });
      expect(reason).not.toContain('tok_PLANTEDATTEMPTS1234');
      expect(reason).toContain('[REDACTED]');
      expect(describeAttempts(2, ['bad tok_PLANTEDATTEMPTS1234'])).not.toContain(
        'tok_PLANTEDATTEMPTS1234'
      );
    } finally {
      if (saved === undefined) {
        delete process.env.API_TOKEN;
      } else {
        process.env.API_TOKEN = saved;
      }
    }
  });
});
