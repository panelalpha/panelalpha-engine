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

export interface InspectCandidate {
  id: string;
  [key: string]: unknown;
}

export interface InspectApplication {
  strategy: string;
  deployable: boolean;
  label?: string;
  platform?: string | null;
  runtime?: string | null;
  issue?: string | null;
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
  hint?: string;
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
