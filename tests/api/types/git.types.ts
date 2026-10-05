export type GitManagedBy = 'deploy' | 'site_git';

export interface GitStatus {
  path: string;
  path_key: string;
  managed_by: GitManagedBy;
  connected: boolean;
  connecting: boolean;
  connecting_since: string | null;
  repository_exists: boolean;
  [key: string]: unknown;
}

export interface GitConnectRequest {
  repo_url: string;
  branch: string;
  path?: string;
  token?: string | null;
  auth_type?: 'pat';
  repair?: boolean;
}

export interface GitPullRequest {
  path?: string;
  strategy?: 'ff' | 'force' | 'push_first';
}

export interface GitChangeBranchRequest {
  branch: string;
  path?: string;
}

export interface GitUpdateCredentialsRequest {
  path?: string;
  token?: string | null;
}

export interface GitPathRequest {
  path?: string;
}

export interface GitRevertRequest {
  path?: string;
  ref?: string;
}

export interface GitCommitsQuery {
  path?: string;
  branch?: string;
  limit?: number;
}
