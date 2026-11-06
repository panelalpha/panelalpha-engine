import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';

interface VaultConfig {
  globals: { ref: string; type: string; purpose: string | null }[];
}

/** A global entry filled through its paste form shows in `vault/config` without its secret. */
test.describe('vault config', () => {
  test('a global entry is listed once filled and never with its secret', async ({
    api,
    authedRequest,
    playwright,
  }) => {
    const type = `pa_cfg_${uniqueId()
      .replace(/[^a-z0-9]/gi, '')
      .toLowerCase()}`;
    const secret = uniqueId('vault-secret-');
    const created = await api.createVaultSecret({ type, scope: 'global', purpose: 'api suite' });
    const ref = created.data.ref;
    const listed = async (): Promise<VaultConfig['globals']> => {
      const response = await authedRequest.get('vault/config');
      await expectStatus(response, 200, 'GET vault/config');
      return ((await response.json()) as { data: VaultConfig }).data.globals;
    };

    try {
      await test.step('an entry nobody filled is not listed', async () => {
        expect((await listed()).map((entry) => entry.ref)).not.toContain(ref);
      });

      await test.step('fill it through its paste form', async () => {
        const browser = await playwright.request.newContext({ ignoreHTTPSErrors: true });
        try {
          const form = await browser.get(created.data.url);
          await expectStatus(form, 200);
          const csrf = /name="_token"\s+value="([^"]+)"/.exec(await form.text())?.[1];
          expect(csrf, 'the form carries a CSRF token').toBeDefined();
          const saved = await browser.post(created.data.url, {
            form: { _token: csrf!, secret },
            maxRedirects: 0,
          });
          await expectStatus(saved, 200, 'POST the paste form');
        } finally {
          await browser.dispose();
        }
      });

      await test.step('the filled entry is listed with its type and purpose', async () => {
        const globals = await listed();
        expect(globals.find((entry) => entry.ref === ref)).toEqual({
          ref,
          type,
          purpose: 'api suite',
        });
        expect(JSON.stringify(globals), 'the secret never leaves the vault').not.toContain(secret);
      });

      await test.step('a deleted entry is no longer listed', async () => {
        await api.deleteVaultSecret(ref);
        expect((await listed()).map((entry) => entry.ref)).not.toContain(ref);
      });
    } finally {
      await api.deleteVaultSecretSafe(ref);
    }
  });
});
