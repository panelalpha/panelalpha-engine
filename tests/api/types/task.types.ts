export interface TaskLogLine {
  id: number;
  log: string;
  level?: string | null;
  stage?: string | null;
  created_at?: string;
}

export interface TaskLogPage {
  data: TaskLogLine[];
  meta?: {
    next_after_id?: number;
    task_status?: string;
  };
}

export interface TaskSnapshot {
  id: number;
  job_id?: string | null;
  queue?: string | null;
  job_type?: string | null;
  status: string;
  details?: unknown;
  username?: string | null;
  logs?: TaskLogLine[];
  next_after_id?: number;
  queued_at?: string | null;
  started_at?: string | null;
  completed_at?: string | null;
  failed_at?: string | null;
  cancelled_at?: string | null;
  [key: string]: unknown;
}

export interface TaskSummary {
  id: number;
  job_id?: string | null;
  queue?: string | null;
  job_type?: string | null;
  status: string;
  details?: unknown;
  username?: string | null;
  queued_at?: string | null;
  started_at?: string | null;
  completed_at?: string | null;
  failed_at?: string | null;
  cancelled_at?: string | null;
  action?: string | null;
  created_by_token_id?: number | null;
  [key: string]: unknown;
}

export interface TaskSummaryPage {
  data: TaskSummary[];
  meta?: {
    current_page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
    [key: string]: unknown;
  };
  links?: Record<string, string | null>;
}
