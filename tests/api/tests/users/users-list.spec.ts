import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { userListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

test.describe('user listing', () => {
  test('the paged list returns well-formed entries', async ({ api }) => {
    // The schema is the field contract: id/username/domain/status and their
    // types, for every row rather than the first one.
    validateParsedApiResponse(await api.listUsers(), userListSchema);
  });

  test('the unpaged list returns an array', async ({ api }) => {
    expect(Array.isArray((await api.listAllUsers()).data)).toBe(true);
  });

  test('the unpaged list can include domain names', async ({ api }) => {
    const { data } = await api.listAllUsers(true);
    expect(Array.isArray(data)).toBe(true);

    for (const user of data) {
      expect(user.domain, `user ${user.username} has no domain`).toBeTruthy();
    }
  });

  test('the unpaged list is never shorter than one page', async ({ api }) => {
    const [all, paged] = await Promise.all([api.listAllUsers(), api.listUsers()]);
    expect(all.data.length).toBeGreaterThanOrEqual(paged.data.length);
  });

  test('per_page=1 returns pagination metadata', async ({ authedRequest }) => {
    const response = await authedRequest.get('projects?per_page=1');
    expect(response.ok()).toBe(true);

    const body = (await response.json()) as { data: unknown[]; meta: Record<string, unknown> };
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.meta).toHaveProperty('current_page');
    expect(body.meta).toHaveProperty('per_page');
    expect(body.meta).toHaveProperty('total');
  });

  test('a free username verifies as available', async ({ api }) => {
    const candidate = `testuser${Date.now().toString().slice(-6)}`;
    expect((await api.verifyUsername(candidate)).valid).toBe(true);
  });

  test('a taken username is rejected by verify-new-username', async ({
    userFactory,
    authedRequest,
  }) => {
    const user = await userFactory.createSimpleUser();

    const response = await authedRequest.post('projects/verify-new-username', {
      data: { username: user.username },
    });

    expectOneOf(response.status(), [400, 422]);
  });
});
