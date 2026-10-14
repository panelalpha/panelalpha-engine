export type ProjectContainerAction = 'up' | 'stop' | 'restart' | 'down' | 'pull';
export type ServiceContainerAction = 'start' | 'stop' | 'restart';

export interface ContainerCommandResult {
  stdout: string;
  stderr: string;
  exit_code: number;
}

export interface DeployTimingsPhase {
  name: string;
  seconds: number;
}

export interface DeployTimingsBuild {
  total_seconds: number;
  step_count: number;
  cached_steps: number;
  cache_hit_ratio: number | null;
  steps?: unknown[];
  slowest?: unknown[];
}

export interface DeployTimings {
  total_seconds: number | null;
  stages: unknown[];
  phases: DeployTimingsPhase[];
  timeline?: unknown[];
  compose?: unknown[];
  build?: DeployTimingsBuild;
}

export interface DeployLogSnapshot {
  id: string | null;
  status: string;
  stage: string | null;
  stages: unknown[];
  lines: unknown[];
  next_offset: number;
  error: string | null;
  started_at: string | null;
  finished_at: string | null;
  timings?: DeployTimings | null;
}

export interface AppHealthPort {
  port: number;
  scheme: string | null;
  status: string;
  http_code: number | null;
  time: number | null;
  detail: string;
}

export interface AppHealthCheck {
  id: string;
  group: string;
  status: 'pass' | 'fail' | 'skipped';
  severity: 'error' | 'warning' | 'info';
  title: string | null;
  detail?: string | null;
  fix?: string | null;
  evidence?: Record<string, unknown> | null;
}

export interface AppHealth {
  healthy: boolean | null;
  serving: string;
  ports: AppHealthPort[];
  checks: AppHealthCheck[];
}

export interface CreateAppUserRequest {
  login: string;
  email: string;
  password: string;
  role: string;
}

export interface InstallAppRequest {
  url: string;
  title: string;
  admin_user: string;
  admin_email: string;
  admin_password: string;
}
