import { z } from 'zod';

export const looseRecordSchema = z.record(z.string(), z.unknown());

export const unknownDataEnvelopeSchema = z
  .object({
    data: z.unknown(),
  })
  .passthrough();

export const unknownArrayDataEnvelopeSchema = z
  .object({
    data: z.array(z.unknown()),
  })
  .passthrough();

export const looseEntityEnvelopeSchema = z
  .object({
    data: looseRecordSchema,
  })
  .passthrough();

export const ftpAccountSchema = z
  .object({
    user: z.string(),
    dir: z.string().optional(),
    quota: z.union([z.number(), z.string()]).optional(),
  })
  .passthrough();

export const sftpAccountSchema = z
  .object({
    username: z.string(),
    auth_method: z.string().optional(),
    dir: z.string().optional(),
    quota: z.union([z.number(), z.string()]).optional(),
  })
  .passthrough();

export const ftpAccountListSchema = z.object({
  data: z.array(ftpAccountSchema),
});

export const cronJobSchema = z
  .object({
    hash: z.string().optional(),
    minute: z.string().optional(),
    hour: z.string().optional(),
    month: z.string().optional(),
    command: z.string().optional(),
    day: z.string().optional(),
    weekday: z.string().optional(),
    day_of_month: z.string().optional(),
    day_of_week: z.string().optional(),
  })
  .passthrough();

export const cronJobListSchema = z.object({
  data: z.array(cronJobSchema),
});

export const mysqlDatabaseSchema = z
  .object({
    name: z.string().optional(),
    database: z.string().optional(),
  })
  .passthrough();

export const mysqlDatabaseListSchema = z.object({
  data: z.array(mysqlDatabaseSchema),
});

export const modSecurityConfigSchema = z
  .object({
    mode: z.string(),
  })
  .passthrough();

export const modSecurityConfigResponseSchema = z.object({
  data: modSecurityConfigSchema,
});

export const ipamAssignedIpSchema = z
  .object({
    id: z.coerce.number(),
    user_id: z.coerce.number(),
    username: z.string(),
    domain_names: z.array(z.string()).optional(),
    ip_subnet_id: z.coerce.number(),
    ip_address: z.string(),
    created_at: z.union([z.string(), z.null()]).optional(),
    updated_at: z.union([z.string(), z.null()]).optional(),
  })
  .passthrough();

const ipamAssignedIpListSchema = z.array(ipamAssignedIpSchema);

/** Laravel may nest assigned IPs as `{ data: [...] }` or a bare array depending on serializer version. */
const ipamAssignedIpsSchema = z.union([
  ipamAssignedIpListSchema,
  z.object({ data: ipamAssignedIpListSchema }).passthrough(),
]);

export const ipamSubnetSchema = z
  .object({
    id: z.coerce.number(),
    ip: z.string(),
    mask: z.coerce.number(),
    family: z.coerce.number().optional(),
    is_shared: z.union([z.boolean(), z.number()]).optional(),
    created_at: z.union([z.string(), z.null()]).optional(),
    updated_at: z.union([z.string(), z.null()]).optional(),
    assigned_ips: ipamAssignedIpsSchema.optional(),
  })
  .passthrough();

export const ipamSubnetListMetaSchema = z
  .object({
    default_ipv4: z
      .object({
        address: z.string().nullable().optional(),
        assignments: z.array(z.unknown()).optional(),
      })
      .passthrough()
      .optional(),
    default_ipv6: z
      .object({
        address: z.string().nullable().optional(),
        assignments: z.array(z.unknown()).optional(),
      })
      .passthrough()
      .optional(),
  })
  .passthrough();

export const ipamSubnetListSchema = z
  .object({
    data: z.array(ipamSubnetSchema),
    meta: ipamSubnetListMetaSchema.optional(),
  })
  .passthrough();

export const modSecurityAuditLogEntrySchema = z.object({
  id: z.string().optional(),
  message: z.string().optional(),
  file: z.string().optional(),
  line: z.union([z.string(), z.number()]).optional(),
  severity: z.string().optional(),
  rule_id: z.union([z.string(), z.number()]).optional(),
});

export const eximConfigSchema = z
  .object({
    smarthost_provider: z.string().optional(),
    sendgrid_api_token: z.string().optional(),
    mailchannels_username: z.string().optional(),
    mailchannels_password: z.string().optional(),
    amazon_ses_smtp_endpoint: z.string().optional(),
    amazon_ses_starttls_port: z.string().optional(),
    amazon_ses_smtp_username: z.string().optional(),
    amazon_ses_smtp_password: z.string().optional(),
    smtp_host: z.string().optional(),
    smtp_port: z.string().optional(),
    smtp_username: z.string().optional(),
    smtp_password: z.string().optional(),
    smtp_implicit_tls: z.union([z.boolean(), z.string()]).optional(),
    sender_domain: z.string().optional(),
  })
  .passthrough();
export const eximConfigResponseSchema = z.object({ data: eximConfigSchema });

export const lighthouseReportSchema = z
  .object({
    report: z.unknown().optional(),
    url: z.string().optional(),
    desktop_preset: z.boolean().optional(),
    no_local_resolve: z.boolean().optional(),
  })
  .passthrough();

export const lighthouseReportResponseSchema = z.object({
  data: lighthouseReportSchema,
});

export const phpVersionsListSchema = z.object({
  data: z.array(z.string()),
});

export const phpVersionSchema = z.object({
  version: z.string(),
  active: z.boolean().optional(),
});

export const phpVersionResponseSchema = z.object({
  data: phpVersionSchema,
});

export const fileEntrySchema = z
  .object({
    name: z.string().optional(),
    type: z.string().optional(),
  })
  .passthrough();

export const fileListSchema = z.object({
  data: z.array(fileEntrySchema),
});
