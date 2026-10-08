import { expect, test } from '@/fixtures/test-options';
import { rand } from '@/helpers/random';
import { expectStatus } from '@/helpers/expect-status';

test.describe('HTTP ACME challenges', () => {
  test('the challenge list for the setup domain starts as an array', async ({ api, setupUser }) => {
    const listed = await api.listHttpAcmeChallenges(setupUser.domain);
    expect(listed.data.domain).toBe(setupUser.domain);
    expect(Array.isArray(listed.data.challenges)).toBe(true);
  });

  test('a challenge is created, read back and deleted', async ({ api, setupUser }) => {
    const token = rand('tok');
    const content = `acme-content-${rand('c')}`;

    try {
      await api.deleteAllHttpAcmeChallenges(setupUser.domain);

      const created = await api.createHttpAcmeChallenge(setupUser.domain, { token, content });
      expect(created.data.domain).toBe(setupUser.domain);
      expect(created.data.token).toBe(token);
      expect(created.data.content).toBe(content);

      const fetched = await api.getHttpAcmeChallenge(setupUser.domain, token);
      expect(fetched.data.content).toBe(content);

      const listed = await api.listHttpAcmeChallenges(setupUser.domain);
      expect(listed.data.challenges.some((challenge) => challenge.token === token)).toBe(true);

      await api.deleteHttpAcmeChallenge(setupUser.domain, token);
      expect(
        (await api.listHttpAcmeChallenges(setupUser.domain)).data.challenges.map((c) => c.token)
      ).not.toContain(token);
    } finally {
      await api.deleteAllHttpAcmeChallengesSafe(setupUser.domain);
    }
  });

  test('a duplicate token is rejected with 409', async ({ api, setupUser }) => {
    const token = rand('dup');
    const content = 'first';

    try {
      await api.deleteAllHttpAcmeChallenges(setupUser.domain);
      await api.createHttpAcmeChallenge(setupUser.domain, { token, content });

      const duplicate = await api.createHttpAcmeChallengeRaw(setupUser.domain, {
        token,
        content: 'second',
      });
      await expectStatus(duplicate, 409);
    } finally {
      await api.deleteAllHttpAcmeChallengesSafe(setupUser.domain);
    }
  });

  test('an unknown domain returns 404', async ({ api }) => {
    const response = await api.listHttpAcmeChallengesRaw('no-such-domain.example.test');
    await expectStatus(response, 404);
  });

  test('delete-all is idempotent', async ({ api, setupUser }) => {
    await api.deleteAllHttpAcmeChallenges(setupUser.domain);
    await api.deleteAllHttpAcmeChallenges(setupUser.domain);
    expect((await api.listHttpAcmeChallenges(setupUser.domain)).data.challenges).toHaveLength(0);
  });
});
