import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { sharedProject } from '@/helpers/shared-project';
import { skipUnless } from '@/helpers/test-helpers';
import { expectStatus } from '@/helpers/expect-status';

test.describe('project backups validation', () => {
  // The two PHP-account tests only receive refusals and share one project.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('a PHP account is no longer refused: a missing store is 404', async ({ api }) => {
    const user = await shared.get();
    const response = await api.createProjectBackupRaw(user.username, { container: 'missing' });
    await expectStatus(response, 404);
    expect(JSON.stringify(response.body), 'the refusal says the store is unknown').toMatch(
      /container not found/i
    );
  });

  test('listing backups for an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.listProjectBackupsRaw('nosuchuser999'), 404);
  });

  test('a missing backup is 404', async ({ api }) => {
    const user = await shared.get();
    await expectStatus(await api.getProjectBackupRaw(user.username, 999_999_999), 404);
  });

  test('creating a backup without a store is refused', async ({ api, sharedEmptyDind }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');
    await test.step('a request without a store is refused', async () => {
      const missingStore = await api.createProjectBackupRaw(user.username, {});
      expectOneOf(missingStore.status, [400, 422]);
    });
    await test.step('a request with an unknown store is refused', async () => {
      const unknownStore = await api.createProjectBackupRaw(user.username, {
        container: 'no-such-store',
      });
      expectOneOf(unknownStore.status, [404, 422]);
    });
  });
});
