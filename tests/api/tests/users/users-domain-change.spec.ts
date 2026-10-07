import { expect, test } from '@/fixtures/test-options';
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

    const result = await api.updateUserRaw(mover.username, { domain: occupant.domain });

    // The same refusal a create with a taken domain gets.
    expect(result.status, JSON.stringify(result.body)).toBe(422);
    expect((result.body as { problems?: unknown[] }).problems).toContainEqual(
      expect.objectContaining({ field: 'domain', code: 'domain_taken' })
    );
    expect(
      (await api.getUser(mover.username)).data.domain,
      'the rejected change must not have taken effect'
    ).toBe(mover.domain);
    // Refused before the rename started: it used to save the new domain row first.
    const moverDomains = (await api.listUserDomains(mover.username)).data.map((d) => d.domain);
    expect(moverDomains).toContain(mover.domain);
    expect(moverDomains).not.toContain(occupant.domain);
  });
});
