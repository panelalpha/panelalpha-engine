import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { configuredGitRepo } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { skipUnless } from '@/helpers/test-helpers';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import {
  deployHookCreateResponseSchema,
  deployHookRotateResponseSchema,
  deployHookShowResponseSchema,
} from '@/schemas';

/**
 * The provider-registration half of the deploy hook: `register` is opt-in, a
 * checkout without a token cannot be registered, and until a registration
 * exists rotate and delete never touch a provider. Nothing here reaches a real
 * git provider: every case stops before the call, or is a registration the
 * engine has no provider API for.
 */
test.describe('deploy hook provider registration', () => {
  test('register must be a boolean', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();

    const refused = await api.createDeployHookRaw(user.username, {
      register: 'yes' as unknown as boolean,
    });

    await expectStatus(refused, 422);
    expect(JSON.stringify(refused.body), 'the refusal names the field').toMatch(/register/i);
  });

  test('a connected checkout is registered only when asked, and rotates without one', async ({
    api,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);

    const repo = configuredGitRepo();
    const host = new URL(repo).hostname.toLowerCase();
    test.skip(
      host !== 'github.com',
      `the configured GIT_REPO is ${host}, whose provider API this spec does not register against`
    );

    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const connected = await api.gitConnectRaw(user.username, { repo_url: repo, branch: 'main' });
    await expectStatus(connected, 200);

    try {
      await test.step('register: true without a checkout token is refused and the hook stays', async () => {
        const refused = await api.createDeployHookRaw(user.username, { register: true });
        await expectStatus(refused, 422);
        expect(JSON.stringify(refused.body), 'the refusal is the missing token').toMatch(
          /provider_token_missing/
        );
        const refusal = refused.body as { problems?: { hook?: unknown }[] };
        const carried = validateParsedApiResponse(
          { data: refusal.problems?.[0]?.hook },
          deployHookCreateResponseSchema
        );
        expect(carried.data.created, 'the refusal carries the hook it created').toBe(true);
        expect(carried.data.secret, 'with the secret, shown this once').toBeTruthy();

        const shown = await api.getDeployHookRaw(user.username);
        await expectStatus(shown, 200);
        const parsed = validateParsedApiResponse(shown.body, deployHookShowResponseSchema);
        expect(parsed.data.url, 'the hook the refusal carried').toBe(carried.data.url);
        expect(parsed.data.registration, 'nothing was registered').toBeNull();
        expect(parsed.data.registration_warning).toBeUndefined();

        await expectStatus(await api.deleteDeployHookRaw(user.username), 204);
      });

      await test.step('without register the hook is created unregistered', async () => {
        const createdRaw = await api.createDeployHookRaw(user.username);
        await expectStatus(createdRaw, 201);
        const created = validateParsedApiResponse(createdRaw.body, deployHookCreateResponseSchema);
        expect(created.data.created, 'a fresh create').toBe(true);
        expect(created.data.registration).toBeNull();
        expect(created.data.registration_warning).toBeUndefined();
      });

      await test.step('rotating an unregistered hook leaves no registration warning', async () => {
        const rotatedRaw = await api.rotateDeployHookRaw(user.username);
        await expectStatus(rotatedRaw, 200);
        const rotated = validateParsedApiResponse(rotatedRaw.body, deployHookRotateResponseSchema);
        expect(rotated.data.registration).toBeNull();
        expect(rotated.data.registration_warning).toBeUndefined();
      });

      await test.step('deleting an unregistered hook still removes it', async () => {
        await expectStatus(await api.deleteDeployHookRaw(user.username), 204);
        await expectStatus(await api.getDeployHookRaw(user.username), 404);
      });
    } finally {
      await api.deleteDeployHookRaw(user.username);
    }
  });
});
