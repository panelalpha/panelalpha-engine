import type { Finding, Hint } from './finding.types';

export type InspectSourceType = 'git' | 'path' | 'project';

export interface InspectSourceRequest {
  source: string;
  type?: InspectSourceType;
  branch?: string;
  subdirectory?: string;
  git_token?: string;
  stages?: Record<string, unknown>;
  recipe?: string;
}

export interface SourceRefsRequest {
  repository: string;
  git_token?: string;
  limit?: number;
}

export interface SourceRefs {
  repository: string;
  default_branch: string | null;
  branches: string[];
  tags: string[];
  truncated: boolean;
}

export interface InspectCandidate {
  id: string;
  [key: string]: unknown;
}

export interface InspectApplication {
  strategy: string;
  verdict: 'deployable' | 'no_start_command' | 'placeholder' | 'not_deployable';
  deployable: boolean;
  label?: string;
  platform?: string | null;
  runtime?: string | null;
  /** Why it cannot be deployed as it stands, with the hints that act on it; null when it can. */
  issue?: Finding | null;
  candidates?: InspectCandidate[];
  [key: string]: unknown;
}

export interface InspectSource {
  type: string;
  [key: string]: unknown;
}

export interface InspectPortNote {
  port: number;
  reason: string;
  routable: boolean;
  service?: string;
  hints?: Hint[];
}

/** PortsReport: which port is proxied, which needs a rule, which was refused. */
export interface InspectPorts {
  primary: number | null;
  source: string | null;
  routed: number[];
  unrouted: InspectPortNote[];
  refused: InspectPortNote[];
  compose: number[];
  dockerfile_expose: number | null;
  /** `port_not_detected` when nothing names a port and a deploy would guess one. */
  issue?: Finding | null;
}

export interface InspectReport {
  source: InspectSource;
  application: InspectApplication;
  ports: InspectPorts;
  environment?: {
    files?: string[];
    variables?: string[];
    [key: string]: unknown;
  };
  deployment?: Record<string, unknown> | null;
  drift?: unknown;
  [key: string]: unknown;
}
