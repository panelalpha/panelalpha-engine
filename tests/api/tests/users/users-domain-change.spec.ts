import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { randomUsername } from '@/helpers/random';

test.describe('main domain changes', () => {
  test('a user can be moved to a new main domain', async ({ api, userFactory, settings }) => {
    const user = await userFactory.createSimpleUser();
    const newDomain = `${randomUsername()}.${settings.requireDomain()}`;

    await api.updateUser(user.username, { domain: newDomain });

    expect((await api.getUser(user.username)).data.domain).toBe(newDomain);
  });

  test('a domain already in use is refused and the old one kept', async ({ api, userFactory }) => {
    const [mover, occupant] = await Promise.all([
      userFactory.createSimpleUser(),
      userFactory.createSimpleUser(),
    ]);

    await test.step('try to take the occupied domain', async () => {
      const result = await api.updateUserRaw(mover.username, { domain: occupant.domain });

      // The same refusal a create with a taken domain gets.
      await expectStatus(result, 422, JSON.stringify(result.body));
      expect(
        (result.body as { problems?: unknown[] }).problems,
        'the refusal names the domain field as taken'
      ).toContainEqual(expect.objectContaining({ field: 'domain', code: 'domain_taken' }));
    });

    await test.step('check the old domain is kept', async () => {
      expect(
        (await api.getUser(mover.username)).data.domain,
        'the rejected change must not have taken effect'
      ).toBe(mover.domain);
      // Refused before the rename started: it used to save the new domain row first.
      const moverDomains = (await api.listUserDomains(mover.username)).data.map((d) => d.domain);
      expect(moverDomains, 'the old main domain is still listed').toContain(mover.domain);
      expect(moverDomains, 'the occupied domain was not added').not.toContain(occupant.domain);
    });
  });
});
