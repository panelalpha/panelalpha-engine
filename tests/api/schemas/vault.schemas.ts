import { z } from 'zod';

export const vaultSecretStatusSchema = z.enum(['pending', 'filled', 'abandoned', 'expired']);

export const vaultSecretScopeSchema = z.enum(['project', 'global']);

/**
 * A listed or fetched entry.
 *
 * `ref` is `vault:<id>`, the reference a request passes. The paste URL's
 * token is not: only its hash is stored. There is no passthrough: the secret
 * must never appear on a read path, and a field the schema does not name
 * fails the test instead of slipping by.
 */
export const vaultSecretEntrySchema = z.strictObject({
  id: z.number().int().positive(),
  ref: z.string().regex(/^vault:\d+$/),
  type: z.string(),
  scope: vaultSecretScopeSchema,
  // The project a `project` entry belongs to; null until one claims it, and always for a global.
  project: z.string().nullable(),
  purpose: z.string().nullable(),
  verify_with: z.record(z.string(), z.string()).nullable(),
  verification: z.record(z.string(), z.unknown()).nullable(),
  status: vaultSecretStatusSchema,
  used_count: z.number(),
  last_used_at: z.string().nullable(),
  created_at: z.string().nullable(),
  // When the secret expires and is deleted; null when it never does.
  expires_at: z.string().nullable(),
  url_expires_at: z.string().nullable(),
});

export const vaultSecretListSchema = z.object({
  data: z.array(vaultSecretEntrySchema),
});

export const vaultSecretResponseSchema = z.object({
  data: vaultSecretEntrySchema,
});

/** The create response: the entry plus the paste URL, the one place its token appears. */
export const createdVaultSecretSchema = vaultSecretEntrySchema.extend({
  url: z.string().url(),
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
