import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { sharedProject } from '@/helpers/shared-project';
import { type CdnSecurityLevel } from '@/types';

/**
 * The CDN endpoints answer from Cloudflare. A successful call needs a real
 * Cloudflare token and would reach Cloudflare's API, which these specs must not
 * do, so only the refusals the engine decides on its own are asserted here;
 * the mapped responses are covered by the unit tests over Http::fake.
 */
test.describe('CDN', () => {
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.getCdnRaw('nosuchuser999', 'missing.example.test'), 404);
  });

  test('an unknown domain is 404', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(await api.getCdnRaw(user.username, 'missing.example.test'), 404);
  });

  test('the status without a Cloudflare token is 422 cloudflare_token_missing', async ({ api }) => {
    const user = await shared.get();
    const response = await api.getCdnRaw(user.username, user.domain);

    await expectStatus(response, 422);
    const body = response.body as { problems?: { code?: string }[] };
    expect(body.problems?.[0]?.code).toBe('cloudflare_token_missing');
  });

  test('a purge of more than thirty files is refused before Cloudflare is called', async ({
    api,
  }) => {
    const user = await shared.get();
    const files = Array.from(
      { length: 31 },
      (_, index) => `https://${user.domain}/file-${index}.css`
    );

    const response = await api.purgeCdnRaw(user.username, user.domain, { files });

    await expectStatus(response, 422);
    const body = response.body as { errors?: Record<string, string[]> };
    expect(body.errors?.files?.length).toBeGreaterThan(0);
  });

  test('an unknown security level is refused', async ({ api }) => {
    const user = await shared.get();
    const response = await api.setCdnSecurityLevelRaw(user.username, user.domain, {
      level: 'paranoid' as unknown as CdnSecurityLevel,
    });

    await expectStatus(response, 422);
    const body = response.body as { errors?: Record<string, string[]> };
    expect(body.errors?.level?.length).toBeGreaterThan(0);
  });
});
