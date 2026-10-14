export interface FtpAccount {
  id: number;
  user_id: number;
  user: string;
  directory: string;
  details: unknown;
  created_at: string;
  updated_at: string;
  disk_usage_mb: number | null;
}

export interface SftpAccount {
  username: string;
  directory: string;
  auth_type: 'password' | 'key';
  created_at: string;
}

/** How many SFTP sessions a kill-sessions call found open. */
export interface SftpSessionsKilled {
  killed: number;
}

export interface CreateFtpAccountRequest {
  user: string;
  domain: string;
  password: string;
  directory?: string;
  unlimited_quota?: boolean;
  quota?: number;
}

export interface UpdateFtpAccountRequest {
  password?: string;
  unlimited_quota?: boolean;
  quota?: number;
}

export interface CreateSftpAccountRequest {
  username: string;
  auth_method: 'password' | 'public_key';
  password?: string;
  public_key?: string;
}

export interface UpdateSftpAccountRequest {
  auth_method: 'password' | 'public_key';
  password?: string;
  public_key?: string;
}
