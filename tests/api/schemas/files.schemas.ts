import { z } from 'zod';

export const fileExistsResponseSchema = z.object({
  exists: z.boolean(),
  path: z.string(),
});

export const fileStatSchema = z.object({
  file_name: z.string(),
  size: z.string(),
  user_id: z.string(),
  group_id: z.string(),
  access_time: z.string(),
  modify_time: z.string(),
  status_change_time: z.string(),
  create_time: z.string(),
});
