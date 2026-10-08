import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps, type CleanupTestInfo } from '@/helpers/cleanup-step';

interface Attachment {
  name: string;
  body: string;
}

function fakeInfo(overrides: Partial<CleanupTestInfo> = {}) {
  const attachments: Attachment[] = [];
  const info = {
    annotations: [] as { type: string; description?: string }[],
    attach: (name: string, options?: { body?: string | Buffer }) => {
      attachments.push({ name, body: String(options?.body ?? '') });
      return Promise.resolve();
    },
    ...overrides,
  } as unknown as CleanupTestInfo;
  return { info, attachments };
}

test.describe('CleanupSteps', () => {
  test('every step runs even when an earlier one failed', async () => {
    const { info } = fakeInfo();
    const cleanup = new CleanupSteps(info);
    const ran: string[] = [];

    await cleanup.run('first', () => {
      ran.push('first');
      throw new Error('boom');
    });
    await cleanup.run('second', () => {
      ran.push('second');
    });

    expect(ran).toEqual(['first', 'second']);
    expect(cleanup.failed.map((failure) => failure.step)).toEqual(['first']);
  });

  test('a verify that returns false is a failure', async () => {
    const { info } = fakeInfo();
    const cleanup = new CleanupSteps(info);

    const ok = await cleanup.run('restore mode', () => undefined, {
      verify: () => false,
      describe: () => 'mode is still off',
    });

    expect(ok).toBe(false);
    await expect(cleanup.finish()).rejects.toThrow(/restore mode: .*mode is still off/);
  });

  test('a verify that throws is a failure', async () => {
    const { info } = fakeInfo();
    const cleanup = new CleanupSteps(info);

    await cleanup.run('restore mode', () => undefined, {
      verify: () => {
        throw new Error('GET failed');
      },
    });

    await expect(cleanup.finish()).rejects.toThrow(/could not verify the restore: GET failed/);
  });

  test('a passing step with a passing verify records nothing', async () => {
    const { info } = fakeInfo();
    const cleanup = new CleanupSteps(info);

    expect(await cleanup.run('ok', () => undefined, { verify: () => true })).toBe(true);
    await cleanup.finish();
    expect(info.annotations).toEqual([]);
  });

  test('finish throws one error listing every failed step when the body passed', async () => {
    const { info } = fakeInfo({ status: 'passed' });
    const cleanup = new CleanupSteps(info);

    await cleanup.run('a', () => {
      throw new Error('first reason');
    });
    await cleanup.run('b', () => {
      throw new Error('second reason');
    });

    const error = await cleanup.finish().then(
      () => undefined,
      (caught: Error) => caught
    );
    expect(error?.message).toContain('2 cleanup step(s) failed');
    expect(error?.message).toContain('a: first reason');
    expect(error?.message).toContain('b: second reason');
    expect(info.annotations.map((a) => a.type)).toEqual(['cleanup', 'cleanup']);
    expect(info.annotations[0].description).toBe('a: failed (first reason)');
  });

  test('a failing body plus a failing cleanup is annotated, not thrown', async () => {
    const { info, attachments } = fakeInfo();
    const cleanup = new CleanupSteps(info);
    const bodyError = new Error('the assertion that failed');

    const outcome = await (async () => {
      try {
        throw bodyError;
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run('restore mode', () => {
          throw new Error('restore refused');
        });
        await cleanup.finish();
      }
    })().then(
      () => undefined,
      (caught: Error) => caught
    );

    expect(outcome).toBe(bodyError);
    const types = info.annotations.map((a) => a.type);
    expect(types).toEqual(['cleanup', 'cleanup-failed']);
    expect(info.annotations[1].description).toContain('restore mode: restore refused');
    expect(attachments).toHaveLength(1);
    expect(attachments[0].name).toBe('cleanup-failures.txt');
    expect(attachments[0].body).toContain('restore mode: restore refused');
  });

  test('a test that already failed is read from its status in an afterEach hook', async () => {
    const { info } = fakeInfo({ status: 'failed' });
    const cleanup = new CleanupSteps(info);

    await cleanup.run('restore', () => {
      throw new Error('nope');
    });

    await cleanup.finish();
    expect(info.annotations.map((a) => a.type)).toContain('cleanup-failed');
  });

  test('reasons are redacted', async () => {
    const { info } = fakeInfo();
    const cleanup = new CleanupSteps(info);

    await cleanup.run('restore', () => {
      throw new Error('login failed with password=hunter2-cleanup-secret');
    });

    const error = await cleanup.finish().then(
      () => undefined,
      (caught: Error) => caught
    );
    expect(error?.message).not.toContain('hunter2-cleanup-secret');
    expect(info.annotations[0].description).not.toContain('hunter2-cleanup-secret');
  });
});
