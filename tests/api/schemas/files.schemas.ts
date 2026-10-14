import { z } from 'zod';

export const fileExistsResponseSchema = z.object({
  exists: z.boolean(),
  path: z.string(),
});

export const fileStatSchema = z.object({
  name: z.string(),
  path: z.string(),
  type: z.enum(['file', 'dir', 'link']),
  size: z.number(),
  mtime: z.number(),
  mode: z.string(),
  uid: z.number(),
  gid: z.number(),
  target: z.string().nullable(),
  target_type: z.enum(['file', 'dir', 'missing']).nullable(),
  has_subdirectories: z.boolean().nullable(),
});

export const fileListResponseSchema = z.object({
  data: z.array(fileStatSchema),
  meta: z.object({
    path: z.string(),
    truncated: z.boolean(),
    total: z.number(),
  }),
});
