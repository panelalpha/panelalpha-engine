import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { randomDomain, randomEmail, randomUsername } from '@/helpers/random';
import { rawResponseBody } from '@/helpers/raw-response';
import { sharedProject } from '@/helpers/shared-project';
import { PROJECT_CREATE_OK } from '@/helpers/task-helpers';

const REJECTED = [400, 422] as const;
/** A duplicate may be refused outright, or accepted and then rejected on conflict. */
const CONFLICT = [400, 409, 422] as const;

test.describe('user creation validation', () => {
  const invalidUsernames = [
    ['empty', ''],
    ['single character', 'a'],
    ['containing spaces', 'user with spaces'],
    ['containing @', 'user@domain'],
    ['containing punctuation', 'user-with-special-chars!'],
    ['over the length limit', 'toolongusernamethatexceedslimits'],
    ['leading digits', '123numeric'],
    ['uppercase', 'UPPERCASE'],
    ['containing a dot', 'user.name'],
    ['far over the length limit', 'user_name_that_is_way_too_long_for_system'],
  ] as const;

  for (const [label, username] of invalidUsernames) {
    test(`rejects a username ${label}`, async ({ api, settings }) => {
      // The domain is valid and ours, so the only thing left to reject is the
      // username — and an engine that wrongly accepts leaves no vhost behind
      // pointing at someone else's domain.
      const result = await api.createUserRaw({
        username,
        domain: randomDomain(settings.requireDomain()),
      });

      // Clean up immediately if the engine wrongly accepted it, so the
      // assertion failure does not also leak a user.
      if ([201, 202].includes(result.status)) {
        const body = rawResponseBody<{ data?: { username?: string }; username?: string }>(result);
        await api.deleteUserSafe(body.data?.username ?? body.username ?? username);
      }

      expectOneOf(result.status, REJECTED, `username "${username}" should have been rejected`);
    });
  }

  test('rejects a malformed recipe id', async ({ api, settings }) => {
    const username = randomUsername();
    const result = await api.createUserRaw({
      username,
      domain: randomDomain(settings.requireDomain()),
      recipe: 'Not A Recipe',
    });
    try {
      expectOneOf(result.status, REJECTED);
      // Parsed before provision: a 422 that still created the account would
      // take the name, and a retry under the same username would then 422
      // for the wrong reason.
      await expectStatus(await api.getUserRaw(username), 404);
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('accepts git_repo with the dind template', { tag: ['@slow'] }, async ({ api, settings }) => {
    // Provision is the cost; we do not wait for the git deploy.
    test.setTimeout(Timeouts.deploy);
    const username = randomUsername();
    const result = await api.createUserRaw({
      username,
      domain: randomDomain(settings.requireDomain()),
      template: 'dind',
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
    });
    try {
      if (result.status === 422) {
        test.skip(
          /template_not_found|Template directory does not exist/i.test(JSON.stringify(result.body)),
          'DinD is not available on this engine.'
        );
      }
      expectOneOf(result.status, PROJECT_CREATE_OK);
      const shown = await api.getUserRaw(username);
      await expectStatus(shown, 200);
      expect(
        (shown.body as { data?: { details?: { template?: string } } }).data?.details?.template,
        'the stored template is dind'
      ).toBe('dind');
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('rejects git_repo with a template other than dind', async ({ api, settings }) => {
    const username = randomUsername();
    const result = await api.createUserRaw({
      username,
      domain: randomDomain(settings.requireDomain()),
      template: 'default',
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
    });
    try {
      expectOneOf(result.status, REJECTED);
      expect(
        JSON.stringify(result.body),
        'the refusal names template_conflicts_with_git'
      ).toContain('template_conflicts_with_git');
      await expectStatus(await api.getUserRaw(username), 404);
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  const incompletePayloads = [
    ['a null username', (domain: string) => ({ username: null, domain })],
    ['a null domain', () => ({ username: '', domain: null })],
    ['a missing domain', () => ({ username: '', domain: undefined })],
  ] as const;

  test('a missing username is generated from the domain', async ({ api, settings }) => {
    // Username is optional. With only a domain, the engine names the project
    // from that domain and answers 202. A null username is still a 422; that
    // case stays in the rejection loop above.
    const result = await api.createUserRaw({
      domain: randomDomain(settings.requireDomain()),
    } as never);
    const body = rawResponseBody<{ data?: { username?: string } }>(result);
    const username = body.data?.username;
    try {
      await expectStatus(result, 202);
      expect(username, 'the generated project name is returned').toEqual(expect.any(String));
    } finally {
      if (username) {
        await api.deleteUserSafe(username);
      }
    }
  });

  for (const [label, buildPayload] of incompletePayloads) {
    test(`rejects ${label}`, async ({ api, settings }) => {
      // Deliberately malformed — the point is that the engine rejects it.
      const result = await api.createUserRaw(
        buildPayload(randomDomain(settings.requireDomain())) as never
      );
      expectOneOf(result.status, REJECTED);
    });
  }

  const sqlInjectionUsernames = [
    "test'; DROP TABLE users; --",
    'admin" OR "1"="1',
    'admin) OR (1=1',
  ];

  for (const username of sqlInjectionUsernames) {
    test(`rejects the SQL injection payload ${JSON.stringify(username)}`, async ({
      api,
      settings,
    }) => {
      const result = await api.createUserRaw({
        username,
        domain: randomDomain(settings.requireDomain()),
      });
      expectOneOf(result.status, REJECTED);
    });
  }

  test('rejects a username that already exists', async ({ api, settings }) => {
    const baseDomain = settings.requireDomain();
    const username = `${randomUsername().slice(0, 12)}dup`;

    const first = await api.createUserRaw({ username, domain: randomDomain(baseDomain) });
    expectOneOf(first.status, [201, 202, 409, 422]);

    try {
      test.skip(
        ![201, 202].includes(first.status),
        'First user was not created, so there is no duplicate.'
      );
      const second = await api.createUserRaw({ username, domain: randomDomain(baseDomain) });
      expectOneOf(second.status, CONFLICT);
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('rejects a domain that already belongs to another user', async ({ api, settings }) => {
    const domain = randomDomain(settings.requireDomain());
    const username = `${randomUsername().slice(0, 10)}dupd`;

    const first = await api.createUserRaw({ username, domain });
    expectOneOf(first.status, [201, 202, 409, 422]);

    try {
      test.skip(
        ![201, 202].includes(first.status),
        'First user was not created, so the domain is not taken.'
      );
      const second = await api.createUserRaw({
        username: `${randomUsername().slice(0, 10)}other`,
        domain,
      });
      expectOneOf(second.status, CONFLICT);
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test("refuses a memory_limit below Docker's 6 MB minimum before it creates anything", async ({
    api,
    settings,
  }) => {
    const username = randomUsername();
    const result = await api.createUserRaw({
      username,
      domain: randomDomain(settings.requireDomain()),
      memory_limit: 4,
    });
    try {
      await expectStatus(result, 422, JSON.stringify(result.body));
      expect((result.body as { problems?: unknown[] }).problems).toContainEqual(
        expect.objectContaining({ field: 'memory_limit', code: 'memory_limit_too_small' })
      );
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('refuses a container project below the 256 MB its own Docker daemon needs', async ({
    api,
    settings,
  }) => {
    const username = randomUsername();
    const result = await api.createUserRaw({
      username,
      domain: randomDomain(settings.requireDomain()),
      template: 'dind',
      memory_limit: 128,
    });
    try {
      await expectStatus(result, 422, JSON.stringify(result.body));
      expect((result.body as { problems?: unknown[] }).problems).toContainEqual(
        expect.objectContaining({
          field: 'memory_limit',
          code: 'memory_limit_too_small',
          min_mb: 256,
        })
      );
    } finally {
      await api.deleteUserSafe(username);
    }
  });

  test('lets at most one of two concurrent creations of the same username win', async ({
    api,
    settings,
  }) => {
    const baseDomain = settings.requireDomain();
    const username = `${randomUsername()}cc`;

    const results = await Promise.all([
      api.createUserRaw({ username, domain: randomDomain(baseDomain) }),
      api.createUserRaw({ username, domain: randomDomain(baseDomain) }),
    ]);

    try {
      const statuses = results.map((r) => r.status);
      const created = statuses.filter((status) => status === 201 || status === 202);

      expect(created.length, `both requests created "${username}"`).toBeLessThanOrEqual(1);

      for (const status of statuses.filter((status) => status !== 201 && status !== 202)) {
        // ProjectCreator::saveNewProject maps the unique-index loser to a
        // ProblemException (422 name_taken / domain_taken), the same answer a
        // sequential duplicate gets. A 500 here is a regression.
        expectOneOf(status, [422]);
      }
    } finally {
      await api.deleteUserSafe(username);
    }
  });
});

test.describe('user update validation', () => {
  // The refused updates share one project: each sends one field the Engine must not store, and
  // no test reads another's field. The test that really writes profile fields keeps its own.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  const invalidEmails = [
    'not-an-email',
    'missing-at.com',
    'user@',
    '@domain.com',
    'user@domain..com',
  ];

  for (const email of invalidEmails) {
    test(`does not persist the invalid email ${JSON.stringify(email)}`, async ({ api }) => {
      const user = await shared.get();

      await api.updateUserRaw(user.username, { email });

      expect(
        (await api.getUser(user.username)).data.email ?? '',
        'the invalid email was not stored'
      ).not.toBe(email);
    });
  }

  const negativeLimits = [
    { disk_space_limit: -2 },
    { memory_limit: -1 },
    { cpu_limit: -0.5 },
    { bandwidth_limit: -100 },
  ];

  for (const limit of negativeLimits) {
    const [field] = Object.keys(limit);
    test(`rejects a negative ${field}`, async ({ api }) => {
      const user = await shared.get();
      expectOneOf((await api.updateUserRaw(user.username, limit)).status, REJECTED);
    });
  }

  test("refuses a memory_limit below Docker's 6 MB minimum and keeps the old one", async ({
    api,
  }) => {
    const user = await shared.get();
    const before = (await api.getUser(user.username)).data.config.memory_limit;

    const result = await api.updateUserRaw(user.username, { memory_limit: 5 });

    await expectStatus(result, 422, JSON.stringify(result.body));
    expect((result.body as { problems?: unknown[] }).problems).toContainEqual(
      expect.objectContaining({ field: 'memory_limit', code: 'memory_limit_too_small' })
    );
    expect((await api.getUser(user.username)).data.config.memory_limit).toBe(before);
  });

  test('strips script payloads from profile fields', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();

    await api.updateUserRaw(user.username, {
      name: '<script>alert(1)</script>',
      email: randomEmail(),
    });

    const storedName = (await api.getUser(user.username)).data.name;
    if (storedName) {
      expect(storedName, 'the stored name has no script tag').not.toContain('<script');
    } else {
      expect(storedName, 'the stored name is empty').toBeFalsy();
    }
  });
});

test.describe('unknown users', () => {
  const paths = [
    ['details', (username: string) => `projects/${username}`],
    ['domains', (username: string) => `projects/${username}/domains`],
    ['usage', (username: string) => `projects/${username}/usage`],
  ] as const;

  for (const [label, buildPath] of paths) {
    test(`requesting ${label} of an unknown user returns 404`, async ({ api }) => {
      await expectStatus(await api.get(buildPath(`nonexistent_${Date.now()}`)), 404);
    });
  }
});
