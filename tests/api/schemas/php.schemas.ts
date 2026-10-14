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

/**
 * GET /projects/{username}/php/error-log. The file is null for a dind project,
 * whose lines are the application service's container output.
 */
export const phpErrorLogResponseSchema = z.object({
  data: z.object({
    lines: z.array(z.string()),
    file: z.string().nullable(),
    size: z.number(),
    mtime: z.number().nullable(),
    truncated: z.boolean(),
  }),
});
