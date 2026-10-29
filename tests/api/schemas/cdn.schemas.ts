import { z } from 'zod';

export const cdnSecurityLevelSchema = z.enum([
  'off',
  'essentially_off',
  'low',
  'medium',
  'high',
  'under_attack',
]);

export const cdnStatusSchema = z.object({
  provider: z.literal('cloudflare'),
  zone_id: z.string(),
  zone_name: z.string(),
  proxied: z.boolean(),
  security_level: cdnSecurityLevelSchema,
  development_mode: z.boolean(),
});

export const cdnStatusResponseSchema = z.object({
  data: cdnStatusSchema,
});

export const cdnPurgeResponseSchema = z.object({
  data: z.object({
    purged: z.boolean(),
  }),
});

export const cdnSecurityLevelResponseSchema = z.object({
  data: z.object({
    security_level: cdnSecurityLevelSchema,
  }),
});
