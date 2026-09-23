import { z } from 'zod';

/**
 * A PHP associative array. With keys it is a JSON object; with none it is
 * `[]`, because PHP does not encode an empty map as `{}`.
 */
function phpMap(value: z.ZodType) {
  return z.union([z.record(z.string(), value), z.tuple([])]);
}

/** GET /projects/{username}/bandwidth and the per-domain twin. Date keys, byte values. */
export const bandwidthSeriesSchema = phpMap(z.number());

export const visitorOverviewSchema = z
  .object({
    unique: z.number(),
    total: z.number(),
    visits: z.object({
      records: phpMap(z.number()),
      total: z.number(),
    }),
    visits_length: phpMap(z.number()),
  })
  .passthrough();

export const visitorBreakdownSchema = z.array(
  z
    .object({
      label: z.string(),
      visits: z.number(),
    })
    .passthrough()
);
