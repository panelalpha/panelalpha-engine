import { expect, test } from '@/fixtures/test-options';
import { inspectApplicationSchema } from '@/schemas';

test.describe('inspect application schema', () => {
  test('accepts candidates on an inspect application payload', () => {
    expect(
      inspectApplicationSchema.parse({
        strategy: 'static',
        verdict: 'deployable',
        deployable: true,
        candidates: [{ id: 'static' }, { id: 'fallback', label: 'Fallback' }],
      }).candidates?.[0]?.id
    ).toBe('static');
  });
});
