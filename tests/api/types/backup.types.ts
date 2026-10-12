export type BackupDriver = 'local' | 's3' | 'ftp' | 'ftps' | 'sftp';

export interface BackupContainer {
  id: number;
  name: string;
  driver: BackupDriver;
  location: string;
  has_credentials: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface CreateBackupContainerRequest {
  name: string;
  driver: BackupDriver;
  location: string;
  credentials?: Record<string, unknown> | null;
}

export interface UpdateBackupContainerRequest {
  name?: string;
  driver?: BackupDriver;
  location?: string;
  credentials?: Record<string, unknown> | null;
}

export interface BackupItem {
  id: number;
  remote_path: string;
  size_bytes: number;
  details: {
    type?: 'files' | 'volume' | 'database';
    name?: string;
    scope?: string;
    sha256?: string;
    docker_name?: string;
  } | null;
}

export interface BackupRecord {
  id: number;
  username: string;
  container_id: number;
  kind?: 'manual' | 'scheduled';
  note?: string | null;
  contents?: { files: boolean; databases: boolean } | null;
  async_status: Record<string, unknown> | null;
  error: string | null;
  size_bytes?: number;
  created_at?: string;
  updated_at?: string;
  container?: BackupContainer;
  items?: BackupItem[];
}

export interface CreateProjectBackupRequest {
  container: string;
  files?: boolean;
  databases?: boolean;
  note?: string | null;
}

export interface RestoreBackupRequest {
  confirm: boolean;
  delete_existing_files?: boolean;
  only?: {
    files?: boolean;
    volumes?: string[];
    databases?: string[];
  };
  exclude?: {
    files?: boolean;
    volumes?: string[];
    databases?: string[];
  };
}

export interface BackupSchedule {
  container_id: number;
  cron: string;
  keep_last: number;
  files: boolean;
  databases: boolean;
  enabled: boolean;
  next_run_at: string | null;
  last_run_at: string | null;
  last_backup_id: number | null;
  last_error: string | null;
}

export interface SetBackupScheduleRequest {
  container: string;
  cron: string;
  keep_last?: number;
  files?: boolean;
  databases?: boolean;
  enabled?: boolean;
}
