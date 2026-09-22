import { z } from 'zod';

const iniValueSchema = z.union([z.string(), z.number(), z.boolean()]);

export const customIniSettingsResponseSchema = z.object({
  data: z.preprocess(
    (value) => (Array.isArray(value) || value === false || value == null ? {} : value),
    z.record(z.string(), iniValueSchema)
  ),
});

/**
 * GET /domains/{domain}/php-directives. An empty file is `{}`: the controller
 * encodes a missing map as an object, not PHP's empty-array `[]`.
 */
export const domainPhpDirectivesResponseSchema = z.object({
  data: z.record(z.string(), z.string()),
});
