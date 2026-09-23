import { z } from 'zod';

export const vaultSecretStatusSchema = z.enum(['pending', 'filled', 'expired']);

/**
 * A listed or fetched entry.
 *
 * `ref` is null for a request entry (only its hash is stored) and
 * `global:<type>` for an engine-wide one. There is no passthrough: the secret
 * must never appear on a read path, and a field the schema does not name
 * fails the test instead of slipping by.
 */
export const vaultSecretEntrySchema = z.strictObject({
  id: z.number().int().positive(),
  // A request entry's ref is shown once, at create, and is not stored. A
  // global entry is addressed as `global:<type>`. Anything else on a read
  // path would be the secret's reference leaking back.
  ref: z.union([z.null(), z.string().regex(/^global:/)]),
  type: z.string(),
  scope: z.enum(['request', 'global']),
  purpose: z.string().nullable(),
  status: vaultSecretStatusSchema,
  used_count: z.number(),
  last_used_at: z.string().nullable(),
  created_at: z.string().nullable(),
  expires_at: z.string().nullable(),
  url_expires_at: z.string().nullable(),
});

export const vaultSecretListSchema = z.object({
  data: z.array(vaultSecretEntrySchema),
});

export const vaultSecretResponseSchema = z.object({
  data: vaultSecretEntrySchema,
});

/** The create response — the one shape that carries the reference in the clear. */
export const createdVaultSecretSchema = z.strictObject({
  id: z.number().int().positive(),
  ref: z.string().startsWith('vault:'),
  type: z.string(),
  scope: z.enum(['request', 'global']),
  purpose: z.string().nullable(),
  url: z.string().url(),
  status: vaultSecretStatusSchema,
  expires_in: z.number().positive().nullable(),
  url_expires_in: z.number().positive(),
});

export const createdVaultSecretResponseSchema = z.object({
  data: createdVaultSecretSchema,
});

export const deletedVaultSecretResponseSchema = z.object({
  data: z.object({
    ref: z.string(),
    deleted: z.literal(true),
  }),
});
