import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { userListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

test.describe('user listing', () => {
  test('the paged list returns well-formed entries', async ({ api }) => {
    // The schema is the field contract: id/username/domain/status and their
    // types, for every row rather than the first one.
    validateParsedApiResponse(await api.listUsers(), userListSchema);
  });

  test('every project carries a normalized app summary', async ({ api, setupUser }) => {
    const { data } = await api.listAllUsers();
    const project = data.find((u) => u.username === setupUser.username);

    expect(project, `${setupUser.username} is missing from the list`).toBeDefined();
    expect(project!.app.status).not.toBe('suspended');
    expect(typeof project!.app.admin_sso).toBe('boolean');
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

  test('a taken username is answered as not free, not as an error', async ({
    userFactory,
    authedRequest,
  }) => {
    const user = await userFactory.createSimpleUser();

    const response = await authedRequest.post('projects/verify-new-username', {
      data: { username: user.username },
    });

    await expectStatus(response, 200);
    expect(await response.json()).toEqual({
      valid: false,
      reason: 'taken',
      message: 'Project name already in use.',
    });
  });

  test('a name that breaks the naming rules is still refused with 422', async ({
    authedRequest,
  }) => {
    const response = await authedRequest.post('projects/verify-new-username', {
      data: { username: 'Shop-1' },
    });

    await expectStatus(response, 422);
  });
});
