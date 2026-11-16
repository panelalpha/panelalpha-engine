import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { rand } from '@/helpers/random';

/** A clone refused over credentials says the token was refused when the caller sent one. */
test.describe('a clone refused over credentials', () => {
  test('says the token was refused when one was sent', async ({ api }) => {
    test.setTimeout(Timeouts.deploy);

    const response = await api.inspectSourceRaw(
      {
        source: `https://github.com/panelalpha/no-such-repo-${rand()}.git`,
        type: 'git',
        git_token: `ghp_${'x'.repeat(36)}`,
      },
      { timeout: Timeouts.deploy }
    );

    await expectStatus(response, 422);
    const problems = (response.body as { problems?: { field?: string; code?: string }[] }).problems;
    expect(problems?.[0]?.field, 'the field the refusal names').toBe('source');
    expect(problems?.[0]?.code, 'the code the refusal carries').toBe('source_unreadable');

    const message = String((problems?.[0] as { message?: string })?.message ?? '');
    expect(message, 'the refusal names the clone').toContain('Could not clone the repository');
    expect(message, 'the refusal blames the token that was sent').toContain(
      'the token was refused, or it cannot read this repository'
    );
    expect(message, 'the refusal never names a request field').not.toMatch(/git_token|Pass /);
  });
});
