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
 * referenced from API calls as `vault:<id>` so the secret never passes through
 * the caller.
 *
 * The properties under test are the ones the mechanism exists for. The paste
 * URL's token is returned exactly once, at creation; no read path hands it or
 * the secret back; and deleting an entry stops it resolving. A regression in any of
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
      expect(created.data.ref).toBe(`vault:${created.data.id}`);
      // Project scope by default, unclaimed until a project is given it.
      expect(created.data.scope).toBe('project');
      expect(created.data.project).toBeNull();

      // The paste URL carries a long random token, not the guessable id.
      const token = created.data.url.split('/vault/')[1];
      expect(token.length).toBeGreaterThan(20);
      expect(token).not.toBe(String(created.data.id));
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

  test('no read path returns the paste token or the secret', async ({ api }) => {
    const type = vaultType();
    const created = await api.createVaultSecret({ type });
    const raw = created.data.url.split('/vault/')[1];

    try {
      const listing = await api.listVaultSecrets(type);
      // The schema is strict, so an added field fails here.
      validateParsedApiResponse(listing, vaultSecretListSchema);

      const single = await api.getVaultSecret(created.data.ref);
      validateParsedApiResponse(single, vaultSecretResponseSchema);

      // Belt and braces against the paste token reaching a read path under
      // some other key: only its sha-256 is stored, so this can never appear.
      for (const [label, body] of [
        ['listing', listing],
        ['single entry', single],
      ] as const) {
        expect(JSON.stringify(body), `the paste token appears in the ${label}`).not.toContain(raw);
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

  test('a fresh entry has an open paste form and no uses', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType() });

    try {
      const entry = (await api.getVaultSecret(created.data.ref)).data;
      expect(entry.used_count).toBe(0);
      expect(entry.last_used_at).toBeNull();
      expect(entry.url_expires_at).not.toBeNull();

      // `url_expires_in` from create and `url_expires_at` from the read have to
      // agree, or an agent waiting for a paste is working from the wrong deadline.
      const declared = created.data.url_expires_in * 1000;
      const observed = new Date(entry.url_expires_at!).getTime() - Date.now();
      expect(Math.abs(observed - declared)).toBeLessThan(60_000);
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
    }
  });

  test('a global entry is created on request and filtered by scope', async ({ api }) => {
    const type = vaultType();
    const project = await api.createVaultSecret({ type });
    const global = await api.createVaultSecret({ type, scope: 'global' });

    try {
      validateParsedApiResponse(global, createdVaultSecretResponseSchema);
      expect(global.data.scope).toBe('global');

      const listing = await api.listVaultSecrets(type, 'global');
      validateParsedApiResponse(listing, vaultSecretListSchema);
      expect(listing.data.map((entry) => entry.ref)).toEqual([global.data.ref]);
    } finally {
      await api.deleteVaultSecretSafe(project.data.ref);
      await api.deleteVaultSecretSafe(global.data.ref);
    }
  });

  test('a project secret can be created for a named project', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType(), project: 'pwvaultowner' });

    try {
      validateParsedApiResponse(created, createdVaultSecretResponseSchema);
      expect(created.data.scope).toBe('project');
      expect(created.data.project).toBe('pwvaultowner');
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
    }

    expectOneOf(
      (
        await api.createVaultSecretRaw({
          type: vaultType(),
          scope: 'global',
          project: 'pwvaultowner',
        })
      ).status,
      [400, 422]
    );
  });

  test('a secret can be given an expiry', async ({ api }) => {
    const created = await api.createVaultSecret({ type: vaultType(), expires_in: 86_400 });
    const plain = await api.createVaultSecret({ type: vaultType() });

    try {
      validateParsedApiResponse(created, createdVaultSecretResponseSchema);
      const inOneDay = Date.now() + 86_400_000;
      expect(Math.abs(new Date(created.data.expires_at!).getTime() - inOneDay)).toBeLessThan(
        60_000
      );
      expect(plain.data.expires_at).toBeNull();
    } finally {
      await api.deleteVaultSecretSafe(created.data.ref);
      await api.deleteVaultSecretSafe(plain.data.ref);
    }
  });

  test('an old-style scope is refused', async ({ api }) => {
    expectOneOf(
      (await api.createVaultSecretRaw({ type: vaultType(), scope: 'request' })).status,
      [400, 422]
    );
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
    expect((await api.getVaultSecretRaw('vault:999999999')).status).toBe(404);
    expect((await api.getVaultSecretRaw('vault:notanid')).status).toBe(404);
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
