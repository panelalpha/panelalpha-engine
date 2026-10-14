export type PhpVersion = string; // e.g., "8.1", "8.2", "7.4"

export interface PhpErrorLog {
  lines: string[];
  file: string | null;
  size: number;
  mtime: number | null;
  truncated: boolean;
}

export interface PhpConfig {
  version: PhpVersion;
  memory_limit: string;
  max_execution_time: number;
  upload_max_filesize: string;
  post_max_size: string;
}
