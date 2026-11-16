import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { testNetwork } from '@/helpers/test-network';

interface DeployKeyView {
  created: boolean;
  public_key: string;
  fingerprint: string;
  hosts: { host: string; pinned: string }[];
}

function keyOf(body: unknown): DeployKeyView {
  return (body as { data: DeployKeyView }).data;
}

/** A project's SSH deploy key is made once; only its public half ever leaves the engine. */
test.describe('git deploy key', () => {
  test('a key is created once, answered again, and replaced after a delete', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();

    const first = await test.step('the first call creates a key', async () => {
      const response = await api.createDeployKeyRaw(user.username);
      await expectStatus(response, 201, 'POST git/deploy-key');
      const key = keyOf(response.body);
      expect(key.created).toBe(true);
      expect(key.public_key).toMatch(/^ssh-ed25519 \S+/);
      expect(key.fingerprint).toMatch(/^SHA256:\S+$/);
      expect(key.hosts.map((host) => host.host)).toEqual(
        expect.arrayContaining(['github.com', 'gitlab.com', 'bitbucket.org'])
      );
      expect(JSON.stringify(response.body)).not.toMatch(/PRIVATE KEY|private_key/);
      return key;
    });

    await test.step('a second call answers the same key', async () => {
      const response = await api.createDeployKeyRaw(user.username, { host: 'github.com' });
      await expectStatus(response, 200, 'POST git/deploy-key again');
      const key = keyOf(response.body);
      expect(key.created).toBe(false);
      expect(key.public_key).toBe(first.public_key);
      expect(key.fingerprint).toBe(first.fingerprint);
    });

    await test.step('the key is read back without making one', async () => {
      const response = await api.getDeployKeyRaw(user.username);
      await expectStatus(response, 200, 'GET git/deploy-key');
      const key = keyOf(response.body);
      expect(key.created).toBe(false);
      expect(key.public_key).toBe(first.public_key);
      expect(key.fingerprint).toBe(first.fingerprint);
      expect(JSON.stringify(response.body)).not.toMatch(/PRIVATE KEY|private_key/);
    });

    await test.step('a host that is not a host name is refused', async () => {
      await expectStatus(
        await api.createDeployKeyRaw(user.username, { host: 'not a host!' }),
        422,
        'malformed host'
      );
    });

    await test.step('an internal host is refused', async () => {
      await expectStatus(
        await api.createDeployKeyRaw(user.username, { host: testNetwork.LOOPBACK }),
        422,
        'loopback host'
      );
    });

    await test.step('delete removes the key', async () => {
      const removed = await api.deleteDeployKeyRaw(user.username);
      await expectStatus(removed, 204);
      await expectStatus(await api.deleteDeployKeyRaw(user.username), 404, 'second delete');
    });

    await test.step('a read after the delete is a 404 that makes no key', async () => {
      const response = await api.getDeployKeyRaw(user.username);
      await expectStatus(response, 404, 'GET git/deploy-key after delete');
      expect(String((response.body as { message?: unknown } | null)?.message)).toContain(
        'has no deploy key'
      );
      await expectStatus(await api.getDeployKeyRaw(user.username), 404, 'second GET makes no key');
    });

    await test.step('the next call makes a new key', async () => {
      const response = await api.createDeployKeyRaw(user.username);
      await expectStatus(response, 201, 'POST git/deploy-key after delete');
      expect(keyOf(response.body).fingerprint).not.toBe(first.fingerprint);
    });
  });

  test('the deploy key of a project that does not exist is 404', async ({ api }) => {
    await expectStatus(await api.createDeployKeyRaw('nosuchproject999'), 404, 'POST');
    await expectStatus(await api.getDeployKeyRaw('nosuchproject999'), 404, 'GET');
    await expectStatus(await api.deleteDeployKeyRaw('nosuchproject999'), 404, 'DELETE');
  });
});
