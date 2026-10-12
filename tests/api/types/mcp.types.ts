export interface McpToken {
  id: number;
  name: string;
  /** Project usernames the token may reach, or null when it is unscoped. */
  projects: string[] | null;
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
  plain_text_token?: string;
}

export interface McpActivityLog {
  id: number;
  token_id: number | null;
  token_name: string;
  /** The project the logged call named, or null when it named none. */
  project: string | null;
  tool_name: string;
  input: Record<string, unknown> | null;
  status: 'success' | 'error';
  error_message: string | null;
  created_at: string;
}

export interface CreateMcpActivityLogRequest {
  token_name: string;
  project?: string | null;
  tool_name: string;
  input?: Record<string, unknown> | null;
  status: 'success' | 'error';
  error_message?: string | null;
}
