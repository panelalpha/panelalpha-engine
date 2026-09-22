import { z } from 'zod';

const tlsAdvisorySchema = z.object({
  state: z.enum(['valid', 'self_signed']),
  warning: z.string().nullable(),
  instructions: z.record(z.string(), z.string()).nullable(),
});

const deployHookCoreSchema = z.object({
  url: z.string().url(),
  path: z.string().min(1),
  created_at: z.string().min(1),
  updated_at: z.string().min(1),
  warning: z.string().optional(),
});

/** POST /projects/{username}/git/deploy-hook — secret only when `created` is true. */
export const deployHookCreateResponseSchema = z.object({
  data: deployHookCoreSchema
    .extend({
      created: z.boolean(),
      secret: z.string().min(1).optional(),
      tls: tlsAdvisorySchema,
    })
    .passthrough(),
});

export const deployHookDeliverySchema = z
  .object({
    provider: z.string(),
    event: z.string().nullable(),
    outcome: z.string(),
    reason: z.string().nullable(),
    result: z.string().nullable(),
    created_at: z.string(),
  })
  .passthrough();

/** GET /projects/{username}/git/deploy-hook. Never includes the secret. */
export const deployHookShowResponseSchema = z.object({
  data: deployHookCoreSchema
    .extend({
      registered_url: z.string().nullable(),
      url_changed_since_registration: z.boolean(),
      tls: tlsAdvisorySchema,
      deliveries: z.array(deployHookDeliverySchema),
    })
    .passthrough(),
});

/** POST /projects/{username}/git/deploy-hook/rotate. The new secret is in this body only. */
export const deployHookRotateResponseSchema = z.object({
  data: deployHookCoreSchema
    .extend({
      secret: z.string().min(1),
      rotated: z.literal(true),
    })
    .passthrough(),
});
