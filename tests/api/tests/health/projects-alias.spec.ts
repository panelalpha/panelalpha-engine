import { expect, test } from '@/fixtures/test-options';

/**
 * /users is a legacy alias of /projects. The rest of the suite hits /projects;
 * these specs are the only place that still calls /users, to prove the alias
 * has not drifted.
 */
test.describe('projects alias', () => {
  test('GET /projects lists the same accounts as GET /users', async ({
    authedRequest,
    setupUser,
  }) => {
    const users = await authedRequest.get('users/');
    const projects = await authedRequest.get('projects/');
    expect(users.status()).toBe(200);
    expect(projects.status()).toBe(200);

    const userBody = (await users.json()) as { data?: { username?: string }[] };
    const projectBody = (await projects.json()) as { data?: { username?: string }[] };
    const userNames = (userBody.data ?? []).map((row) => row.username).sort();
    const projectNames = (projectBody.data ?? []).map((row) => row.username).sort();
    expect(projectNames).toEqual(userNames);

    const shown = await authedRequest.get(`projects/${setupUser.username}`);
    expect(shown.status()).toBe(200);
  });

  test('GET /projects/{u}/domains matches the legacy /users path', async ({
    authedRequest,
    setupUser,
  }) => {
    const username = setupUser.username;
    const viaProjects = await authedRequest.get(`projects/${username}/domains`);
    const viaUsers = await authedRequest.get(`users/${username}/domains`);
    expect(viaProjects.status()).toBe(200);
    expect(viaUsers.status()).toBe(200);

    const projectBody = (await viaProjects.json()) as { data?: { domain?: string }[] };
    const userBody = (await viaUsers.json()) as { data?: { domain?: string }[] };
    const projectNames = (projectBody.data ?? []).map((row) => row.domain).sort();
    const userNames = (userBody.data ?? []).map((row) => row.domain).sort();
    expect(userNames).toEqual(projectNames);
    expect(projectNames).toContain(setupUser.domain);

    const shownProjects = await authedRequest.get(
      `projects/${username}/domains/${setupUser.domain}`
    );
    const shownUsers = await authedRequest.get(`users/${username}/domains/${setupUser.domain}`);
    expect(shownProjects.status()).toBe(200);
    expect(shownUsers.status()).toBe(200);
  });

  test('POST /projects rejects X-Deploy-Stream (sync create stays on /users)', async ({
    api,
    authedRequest,
    settings,
  }) => {
    const username = `strm${Date.now().toString(36).slice(-8)}`;
    const domain = `${username}.${settings.requireDomain()}`;
    const response = await authedRequest.post('projects', {
      headers: { 'X-Deploy-Stream': 'ndjson' },
      data: { username, domain },
    });
    try {
      expect(response.status()).toBe(400);
    } finally {
      // There only if the engine wrongly created it.
      await api.deleteUserSafe(username);
    }
  });
});
