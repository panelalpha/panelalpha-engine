import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { UserFactory } from '@/test-data/factories/user.factory';

const TOKEN = 'tok_PLANTEDREFUSALTOKEN01';

interface Raw {
  status: number;
  body: unknown;
}

/** An Engine that answers POST /projects with `created`. */
function factoryFor(created: Raw): UserFactory {
  const api = {
    createUserRaw: () => Promise.resolve(created),
    getTaskRaw: () =>
      Promise.resolve<Raw>({
        status: 200,
        body: { data: { id: 23, status: 'completed', details: {}, logs: [], next_after_id: 0 } },
      }),
    getUser: () => Promise.resolve({ data: { domain: 'x.test' } }),
  };
  return new UserFactory(api as unknown as EngineApi);
}

function problem(field: string, code: string): Raw {
  return {
    status: 422,
    body: {
      message: 'invalid',
      errors: { [field]: ['invalid'] },
      problems: [{ field, code, message: 'invalid' }],
    },
  };
}

test.describe('UserFactory create refusal', () => {
  test.beforeEach(() => {
    process.env.API_TOKEN = TOKEN;
    process.env.API_BASE_URL ??= 'https://engine.test/api';
  });
  test.afterEach(() => {
    delete process.env.API_TOKEN;
  });

  test('422 template_not_found on template is the one refusal that returns null', async () => {
    const factory = factoryFor(problem('template', 'template_not_found'));
    expect(await factory.createDindUser()).toBeNull();
    expect(factory.pendingCleanup).toEqual([]);
  });

  test('422 with another problem code throws', async () => {
    const error = await factoryFor(problem('template', 'template_conflicts_with_git'))
      .createDindUser()
      .catch((e: unknown) => e as Error);
    expect((error as Error).message).toContain('POST /projects answered 422');
    expect((error as Error).message).toContain('template_conflicts_with_git');
  });

  test('template_not_found on another field throws', async () => {
    const error = await factoryFor(problem('recipe', 'template_not_found'))
      .createDindUser()
      .catch((e: unknown) => e as Error);
    expect((error as Error).message).toContain('POST /projects answered 422');
  });

  test('a 500 throws with the status and a redacted body start', async () => {
    const body = { message: `Server Error token ${TOKEN}`, trace: 'x'.repeat(5000) };
    const factory = factoryFor({ status: 500, body });
    const error = await factory.createDindUser().catch((e: unknown) => e as Error);
    const message = (error as Error).message;

    expect(message.startsWith('POST /projects answered 500\n')).toBe(true);
    expect(message).toContain('Server Error');
    expect(message).not.toContain(TOKEN);
    expect(message.length).toBeLessThan(2500);
    // Kept tracked: teardown deletes it, and a 404 there counts as already gone.
    expect(factory.pendingCleanup).toHaveLength(1);
  });

  test('a 202 is still tracked and followed to its task', async () => {
    const factory = factoryFor({ status: 202, body: { data: { id: 23 } } });
    const user = await factory.createDindUser();
    expect(user?.domain).toBe('x.test');
    expect(factory.pendingCleanup).toHaveLength(1);
  });
});
