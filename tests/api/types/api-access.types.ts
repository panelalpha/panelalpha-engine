/**
 * A project's allowance to call the engine's API. `scope` is what its token may
 * act on: `self`, `all`, or the project usernames it names. The token itself is
 * never returned.
 */
import type { ApiResponse } from './api.types';

export interface ApiAccessEntry {
  project: string;
  scope: 'self' | 'all' | string[];
  mcp: boolean;
  token_id: number;
  allowed_at: string | null;
  /** A token an earlier replacement left valid because its apply failed; revoked by the next successful apply. */
  pending_revoke_token_id: number | null;
  warning: string | null;
}

/** The entry, and the apply task that carries the new token to the application. */
export interface ApiAccessRotation extends ApiResponse<ApiAccessEntry> {
  task_id: number;
}

export interface ApiAccessAllowRequest {
  scope: 'self' | 'all' | string[];
  mcp?: boolean;
}

export interface ApiAccessRemoval {
  project: string;
  removed: boolean;
}
