import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { uniqueId } from '@/helpers/random';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import {
  createdVaultSecretResponseSchema,
  deletedVaultSecretResponseSchema,
  vaultSecretListSchema,
  vaultSecretResponseSchema,
} from '@/schemas';

/**
 * The secret vault: a slot the customer pastes a secret into in their browser,
 * referenced from API calls as `vault:<ref>` so the secret never passes through
 * the caller.
 *
 * The properties under test are the ones the mechanism exists for. The
 * reference is returned exactly once, at creation; no read path hands it or the
 * secret back; and deleting an entry stops it resolving. A regression in any of
 * those turns the vault into an ordinary — and worse — way of moving a token
 * around, while every endpoint still answers 200.
 *
 * `type` is free-form snake_case by design, so these use a generated one rather
 * than a real field name: nothing here should depend on `git_token` being the
 * only type the engine knows.
 */
function vaultType(): string {
  return uniqueId('pw_vault_')
    .toLowerCase()
    .replace(/[^a-z0-9_]/g, '_');
}

test.describe('secret vault', () => {
  test('creating a slot returns a reference, a paste URL and a pending status', async ({ api }) => {
    const type = vaultType();
    const created = await api.createVaultSecret({ type });

    try {
      validateParsedApiResponse(created, createdVaultSecretResponseSchema);
      expect(created.data.type).toBe(type);
      expect(created.data.status).toBe('pending');

      // The form URL and the `vault:` value carry the same raw ref, which is
      // what keeps the page and the reference from ever disagreeing.
      const raw = created.data.ref.slice('vault:'.length);
      expect(raw.length).toBeGreaterThan(20);
      expect(created.data.url.endsWith(`/vault/${raw}`)).toBe(true);
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
    }
  });

  test('a reference resolves both as `vault:<id>` and bare', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType() });
    const prefixed = created.data.ref;
    const bare = prefixed.slice('vault:'.length);

    try {
      for (const ref of [prefixed, bare]) {
        const entry = await api.getVaultSecret(ref);
        validateParsedApiResponse(entry, vaultSecretResponseSchema);
        expect(entry.data.status, `lookup by ${ref === bare ? 'bare id' : 'vault: value'}`).toBe(
          'pending'
        );
      }
    } finally {
      await api.deleteVaultSecretSafe(prefixed);
    }
  });

  test('no read path returns the reference or the secret', async ({ api }) => {
    const type = vaultType();
    const created = await api.createVaultSecret({ type });
    const raw = created.data.ref.slice('vault:'.length);

    try {
      const listing = await api.listVaultSecrets(type);
      // The schema is strict and pins `ref` to null, so an added field or a
      // reference leaking back into the listing fails right here.
      validateParsedApiResponse(listing, vaultSecretListSchema);

      const single = await api.getVaultSecret(created.data.ref);
      validateParsedApiResponse(single, vaultSecretResponseSchema);

      // Belt and braces against the raw reference reaching a read path under
      // some other key: only its sha-256 is stored, so this can never appear.
      for (const [label, body] of [
        ['listing', listing],
        ['single entry', single],
      ] as const) {
        expect(JSON.stringify(body), `the raw reference appears in the ${label}`).not.toContain(
          raw
        );
      }
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
    }
  });

  test('the listing filters by type', async ({ api }) => {
    const mine = vaultType();
    const other = vaultType();
    const a = await api.createVaultSecret({ type: mine });
    const b = await api.createVaultSecret({ type: other });

    try {
      const filtered = await api.listVaultSecrets(mine);
      validateParsedApiResponse(filtered, vaultSecretListSchema);
      expect(filtered.data.length).toBe(1);
      expect(filtered.data[0].type).toBe(mine);

      const all = await api.listVaultSecrets();
      expect(all.data.map((entry) => entry.type)).toEqual(expect.arrayContaining([mine, other]));
    } finally {
      await api.deleteVaultSecretSafe(a.data.ref);
      await api.deleteVaultSecretSafe(b.data.ref);
    }
  });

  test('a fresh entry has a future expiry and no uses', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType() });

    try {
      const entry = (await api.getVaultSecret(created.data.ref)).data;
      expect(entry.used_count).toBe(0);
      expect(entry.last_used_at).toBeNull();
      expect(new Date(entry.expires_at).getTime()).toBeGreaterThan(Date.now());

      // `expires_in` from create and `expires_at` from the read have to agree,
      // or an agent waiting for a paste is working from the wrong deadline.
      const declared = created.data.expires_in * 1000;
      const observed = new Date(entry.expires_at).getTime() - Date.now();
      expect(Math.abs(observed - declared)).toBeLessThan(60_000);
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
    }
  });

  test('a deleted entry stops resolving', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType() });

    const deleted = await api.deleteVaultSecret(created.data.ref);
    validateParsedApiResponse(deleted, deletedVaultSecretResponseSchema);

    expect((await api.getVaultSecretRaw(created.data.ref)).status).toBe(404);
  });

  test('deleting twice is not an error', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType() });

    await api.deleteVaultSecret(created.data.ref);
    // Matches the engine's delete semantics elsewhere — see
    // tests/integration/delete-idempotency.spec.ts.
    expect((await api.deleteVaultSecretRaw(created.data.ref)).status).toBe(200);
  });

  test('an unknown reference is not found', async ({ api }) => {
    expect((await api.getVaultSecretRaw('vault:nosuchreference999')).status).toBe(404);
  });

  test('a malformed type is refused', async ({ api }) => {
    for (const type of ['', 'Git_Token', '1token', 'git-token', 'a'.repeat(65)]) {
      const response = await api.createVaultSecretRaw({ type });
      expectOneOf(response.status, [400, 422]);
    }

    expectOneOf((await api.createVaultSecretRaw({})).status, [400, 422]);
  });

  test('the vault endpoints require authentication', async ({ playwright, settings }) => {
    const request = await playwright.request.newContext({
      baseURL: settings.apiBaseUrl,
      ignoreHTTPSErrors: true,
      extraHTTPHeaders: { Accept: 'application/json' },
    });

    try {
      expectOneOf((await request.get('vault/secrets')).status(), [401, 403]);
      expectOneOf(
        (await request.post('vault/secrets', { data: { type: 'git_token' } })).status(),
        [401, 403]
      );
    } finally {
      await request.dispose();
    }
  });
});
