import { z } from 'zod';

export const inspectCandidateSchema = z
  .object({
    id: z.string(),
  })
  .passthrough();

export const inspectApplicationSchema = z
  .object({
    strategy: z.string(),
    deployable: z.boolean(),
    candidates: z.array(inspectCandidateSchema).optional(),
  })
  .passthrough();

export const inspectSourceSchema = z
  .object({
    type: z.string(),
  })
  .passthrough();

/** One compose port the engine will not proxy on its own. See PortsReport. */
export const inspectPortNoteSchema = z
  .object({
    port: z.number(),
    reason: z.string(),
    routable: z.boolean(),
  })
  .passthrough();

/**
 * `ports` on POST /source/inspect. An object, not a list: `routed` is the one
 * port the edge proxies, `unrouted` needs a proxy rule, `refused` must never
 * get one (a database published by compose).
 */
export const inspectPortsSchema = z
  .object({
    primary: z.number().nullable(),
    source: z.string().nullable(),
    routed: z.array(z.number()),
    unrouted: z.array(inspectPortNoteSchema),
    refused: z.array(inspectPortNoteSchema),
    compose: z.array(z.number()),
    dockerfile_expose: z.number().nullable(),
  })
  .passthrough();

export const inspectReportSchema = z
  .object({
    source: inspectSourceSchema,
    application: inspectApplicationSchema,
    ports: inspectPortsSchema,
  })
  .passthrough();

export const inspectReportResponseSchema = z.object({
  data: inspectReportSchema,
});

export const sshCommandResultSchema = z
  .object({
    exit_code: z.number(),
    stdout: z.string(),
    stderr: z.string(),
  })
  .passthrough();

export const sslConfigSchema = z
  .object({
    issuer: z.string(),
    sites_base_domain: z.string().nullable(),
    shared_zone: z.boolean(),
    acme_directory_url: z.union([z.string(), z.null()]).optional(),
    acme_email: z.union([z.string(), z.null()]).optional(),
  })
  .passthrough();

export const sslConfigResponseSchema = z.object({
  data: sslConfigSchema,
});

export const deployTimingsSchema = z
  .object({
    total_seconds: z.number().nullable().optional(),
    phases: z.array(z.object({ name: z.string(), seconds: z.number() }).passthrough()).optional(),
    build: z
      .object({
        cache_hit_ratio: z.number().nullable().optional(),
      })
      .passthrough()
      .optional(),
  })
  .passthrough();

export const deployLogSnapshotSchema = z
  .object({
    status: z.string(),
    next_offset: z.number(),
    timings: deployTimingsSchema.nullable().optional(),
  })
  .passthrough();

/**
 * One-word answers `GET /projects/{u}/app/health` and `project:deploy:check --json`
 * use for what answered on `/`. Keep in lockstep with CheckRunner / the operator
 * table on check-the-site.md.
 */
export const SERVING_WORDS = [
  'ok',
  'unknown',
  'placeholder',
  'no_application',
  'default_page',
  'framework_default',
  'missing_entry',
  'directory_listing',
  'error_page',
  'php_error',
  'php_source',
  'database_error',
  'dependency_unreachable',
  'misconfigured_host',
  'dev_server',
  'no_root_route',
] as const;

export const servingWordSchema = z.enum(SERVING_WORDS);

export const appHealthCheckSchema = z
  .object({
    id: z.string(),
    group: z.string(),
    status: z.enum(['pass', 'fail', 'skipped']),
    severity: z.enum(['error', 'warning', 'info']),
    title: z.string().nullable(),
    detail: z.string().nullable().optional(),
    fix: z.string().nullable().optional(),
    evidence: z.unknown().nullable().optional(),
  })
  .passthrough();

export const appHealthSchema = z
  .object({
    healthy: z.boolean().nullable(),
    serving: servingWordSchema,
    ports: z.array(z.unknown()).optional(),
    checks: z.array(appHealthCheckSchema),
  })
  .passthrough();
