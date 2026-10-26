import { z } from 'zod';

export const ipv4NatMapSchema = z.object({
  id: z.number().optional(),
  local_ip: z.string(),
  public_ip: z.string(),
  created_at: z.string().optional(),
  updated_at: z.string().optional(),
});

export const hostInfoSchema = z.object({
  hostname: z.string().nullable(),
  os: z.string().nullable(),
  kernel: z.string().nullable(),
  uptime_seconds: z.number().int().nonnegative().nullable(),
  cpu_cores: z.number().int().positive().nullable(),
  memory_total_bytes: z.number().int().positive().nullable(),
  disk_total_bytes: z.number().int().positive().nullable(),
  ipv4: z.string().nullable(),
});

export const systemInfoSchema = z
  .object({
    version: z.string(),
    host: hostInfoSchema,
    webserver: z.union([z.string(), z.record(z.string(), z.unknown())]),
    php_versions: z.array(z.unknown()).optional(),
    default_ipv4: z.string().nullable().optional(),
    default_ipv6: z.string().nullable().optional(),
    default_tunnel: z.enum(['panelalpha', 'none']).nullable().optional(),
    ipv4_nat_mode: z.boolean().optional(),
    ipv4_nat_maps: z.array(ipv4NatMapSchema).optional(),
    latest_webserver_change: z
      .object({
        started_at: z.number().nullable().optional(),
        finished_at: z.number().nullable().optional(),
        pid: z.number().nullable().optional(),
        exit_code: z.number().nullable().optional(),
        from_version: z.string().nullable().optional(),
        to_version: z.string().nullable().optional(),
      })
      .nullable()
      .optional(),
    latest_update: z
      .object({
        started_at: z.number().nullable().optional(),
        finished_at: z.number().nullable().optional(),
        pid: z.number().nullable().optional(),
        exit_code: z.number().nullable().optional(),
        failed: z.boolean().optional(),
        reason: z.string().nullable().optional(),
        from_version: z.string().nullable().optional(),
        to_version: z.string().nullable().optional(),
      })
      .nullable()
      .optional(),
  })
  .passthrough();

export const systemInfoResponseSchema = z.object({
  data: systemInfoSchema,
});

export const currentMetricsSchema = z.object({
  cpu_usage_percent: z.number(),
  ram_total: z.number(),
  ram_free: z.number(),
  ram_used: z.number(),
  ram_usage_percent: z.number(),
  disk_path: z.string(),
  disk_total: z.number(),
  disk_free: z.number(),
  disk_used: z.number(),
  disk_usage_percent: z.number(),
});

export const currentMetricsResponseSchema = z.object({
  data: currentMetricsSchema,
});

export const historicalMetricsEntrySchema = z.object({
  period: z.string(),
  cpu_percent: z.number().optional(),
  cpu_load_avg_1: z.number().optional(),
  cpu_load_avg_5: z.number().optional(),
  cpu_load_avg_15: z.number().optional(),
  ram_percent: z.number().optional(),
  swap_percent: z.number().optional(),
  disk_read_bps: z.number().optional(),
  disk_write_bps: z.number().optional(),
  disk_read_iops: z.number().optional(),
  disk_write_iops: z.number().optional(),
  net_in_bps: z.number().optional(),
  net_out_bps: z.number().optional(),
  net_in_pps: z.number().optional(),
  net_out_pps: z.number().optional(),
});

export const historicalMetricsResponseSchema = z.object({
  data: z.array(historicalMetricsEntrySchema),
});

export const metricsHistoryRanges = ['1h', '24h', '7d', '30d'] as const;

export const metricsHistoryResponseSchema = z.object({
  data: z.array(
    historicalMetricsEntrySchema.extend({ disk_percent: z.number().nullable().optional() })
  ),
  meta: z.object({
    range: z.enum(metricsHistoryRanges),
    bucket_seconds: z.number().int().positive(),
    from: z.string(),
    to: z.string(),
  }),
});

export const systemMetricsSchema = z
  .object({
    cpu_usage: z.number().optional(),
    memory_usage: z.number().optional(),
    disk_usage: z.number().optional(),
    load_average: z.array(z.number()).optional(),
    avg_cpu_percent: z.number().optional(),
    avg_ram_percent: z.number().optional(),
  })
  .passthrough();

export const systemMetricsResponseSchema = z.object({
  data: systemMetricsSchema,
});

export const apiAccessEntrySchema = z.object({
  project: z.string(),
  scope: z.union([z.enum(['self', 'all']), z.array(z.string())]),
  mcp: z.boolean(),
  token_id: z.number(),
  allowed_at: z.string().nullable(),
  pending_revoke_token_id: z.number().nullable(),
  warning: z.string().nullable(),
});

export const apiAccessListResponseSchema = z.object({
  data: z.array(apiAccessEntrySchema),
});

export const apiAccessEntryResponseSchema = z.object({
  data: apiAccessEntrySchema,
});

export const apiAccessRemovalResponseSchema = z.object({
  data: z.object({ project: z.string(), removed: z.boolean() }),
});

export const apiAccessRotationResponseSchema = z.object({
  data: apiAccessEntrySchema,
  task_id: z.number(),
});
