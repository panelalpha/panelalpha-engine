import { z } from 'zod';

export const vaultSecretStatusSchema = z.enum(['pending', 'filled', 'expired']);

/**
 * A listed or fetched entry.
 *
 * `ref` is pinned to null and there is no passthrough: only the reference's
 * hash is stored, and the secret must never appear in a read path. A strict
 * object is what makes an added field fail the test instead of slipping by.
 */
export const vaultSecretEntrySchema = z.strictObject({
  ref: z.null(),
  type: z.string(),
  status: vaultSecretStatusSchema,
  used_count: z.number(),
  last_used_at: z.string().nullable(),
  created_at: z.string().nullable(),
  expires_at: z.string(),
});

export const vaultSecretListSchema = z.object({
  data: z.array(vaultSecretEntrySchema),
});

export const vaultSecretResponseSchema = z.object({
  data: vaultSecretEntrySchema,
});

/** The create response — the one shape that carries the reference in the clear. */
export const createdVaultSecretSchema = z.strictObject({
  ref: z.string().startsWith('vault:'),
  type: z.string(),
  url: z.string().url(),
  status: vaultSecretStatusSchema,
  expires_in: z.number().positive(),
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
