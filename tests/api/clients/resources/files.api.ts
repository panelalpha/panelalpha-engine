/* eslint-disable @typescript-eslint/no-explicit-any */

import * as fs from 'fs';
import * as path from 'path';
import { SystemApi } from './system.api';

export interface FileEntry {
  name: string;
  path: string;
  type: 'file' | 'dir' | 'link';
  size: number;
  mtime: number;
  mode: string;
  uid: number;
  gid: number;
  target: string | null;
  target_type: 'file' | 'dir' | 'missing' | null;
  has_subdirectories: boolean | null;
}

export interface FileListMeta {
  path: string;
  truncated: boolean;
  total: number;
}

export class FilesApi extends SystemApi {
  async fileExists(username: string, filePath: string): Promise<{ exists: boolean; path: string }> {
    const response = await this.api.get(
      `projects/${username}/files/exists?path=${encodeURIComponent(filePath)}`
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getFileStat(username: string, filePath: string): Promise<FileEntry> {
    const response = await this.api.get(
      `projects/${username}/files/stat?path=${encodeURIComponent(filePath)}`
    );
    await this.assertStatus(response, 200);
    const body = (await response.json()) as { data: FileEntry };
    return body.data;
  }

  async listFiles(
    username: string,
    dirPath: string,
    options: { showHidden?: boolean; limit?: number } = {}
  ): Promise<{ data: FileEntry[]; meta: FileListMeta }> {
    const query = new URLSearchParams({ path: dirPath });
    if (options.showHidden !== undefined) {
      query.set('show_hidden', options.showHidden ? '1' : '0');
    }
    if (options.limit !== undefined) {
      query.set('limit', String(options.limit));
    }
    const response = await this.api.get(`projects/${username}/files/list?${query.toString()}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listFilesRaw(
    username: string,
    params: { path?: string; show_hidden?: string | number | boolean; limit?: string | number }
  ): Promise<{ status: number; body: unknown }> {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined) {
        query.set(key, String(value));
      }
    }
    const response = await this.api.get(`projects/${username}/files/list?${query.toString()}`);
    return this.rawCall(response);
  }

  async getFileContent(username: string, filePath: string): Promise<string> {
    const response = await this.api.get(
      `projects/${username}/files/download?path=${encodeURIComponent(filePath)}`
    );
    await this.assertStatus(response, 200);
    return response.text();
  }

  async uploadFile(
    username: string,
    destinationDir: string,
    localPath: string,
    options: { filename?: string; mimeType?: string } = {}
  ): Promise<void> {
    const filename = options.filename ?? path.basename(localPath);
    const fileBuffer = fs.readFileSync(localPath);
    const multipart: Record<string, any> = {
      path: destinationDir,
      file: {
        name: filename,
        buffer: fileBuffer,
      },
    };

    if (options.mimeType) {
      multipart.file.mimeType = options.mimeType;
    }

    const response = await this.api.post(`projects/${username}/files/upload`, { multipart });
    await this.assertOk(response);
  }

  async putFileContents(username: string, filePath: string, content: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/files/put-contents`, {
      data: { path: filePath, contents: content },
    });
    await this.assertOk(response);
  }

  async createDirectory(username: string, dirPath: string, parents = false): Promise<void> {
    const response = await this.api.post(`projects/${username}/files/mkdir`, {
      data: { path: dirPath, parents },
    });
    await this.assertOk(response);
  }

  async moveFile(username: string, sourcePath: string, destPath: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/files/mv`, {
      data: { source_path: sourcePath, dest_path: destPath },
    });
    await this.assertOk(response);
  }

  async copyFile(username: string, sourcePath: string, destPath: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/files/cp`, {
      data: { source_path: sourcePath, dest_path: destPath },
    });
    await this.assertOk(response);
  }

  async removeFile(username: string, filePath: string, recursive = false): Promise<void> {
    const response = await this.api.delete(`projects/${username}/files/remove`, {
      data: { path: filePath, recursive },
    });
    await this.assertOk(response);
  }

  async zipFiles(
    username: string,
    zipPath: string,
    sourcePath: string,
    skipParents = false
  ): Promise<void> {
    const response = await this.api.post(`projects/${username}/files/zip`, {
      data: { zip_path: zipPath, path: sourcePath, skip_parents: skipParents },
    });
    await this.assertOk(response);
  }

  async unzipFile(username: string, zipPath: string, destPath: string): Promise<void> {
    const response = await this.api.post(`projects/${username}/files/unzip`, {
      data: { zip_path: zipPath, path: destPath },
    });
    await this.assertOk(response);
  }

  async moveDirectoryContents(
    username: string,
    sourcePath: string,
    destPath: string,
    override = true
  ): Promise<void> {
    const response = await this.api.post(`projects/${username}/files/move-contents`, {
      data: { source_path: sourcePath, dest_path: destPath, override },
    });
    await this.assertOk(response);
  }

  async moveDirectoryContentsRaw(
    username: string,
    data: { source_path?: string; dest_path?: string; override?: boolean }
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.post(`projects/${username}/files/move-contents`, { data });
    return this.rawCall(response);
  }

  async fetchFileRaw(
    username: string,
    data: { url?: string; path?: string; filename?: string }
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.post(`projects/${username}/files/fetch`, { data });
    return this.rawCall(response);
  }

  async chmodFile(username: string, filePath: string, mode: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/files/chmod`, {
      data: { path: filePath, mode },
    });
    await this.assertOk(response);
  }

  async chmodFileRaw(
    username: string,
    data: { path?: string; mode?: string }
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put(`projects/${username}/files/chmod`, { data });
    return this.rawCall(response);
  }

  async healthCheck(): Promise<{ status: number }> {
    const response = await this.api.get('');
    return { status: response.status() };
  }

  async testConnection(): Promise<{ success: boolean }> {
    const response = await this.api.get('test-connection');
    await this.assertOk(response);
    return response.json();
  }

  async uploadFileFromBuffer(
    username: string,
    destinationDir: string,
    filename: string,
    buffer: Buffer,
    mimeType = 'application/octet-stream'
  ): Promise<{ status: number }> {
    const multipart: Record<string, any> = {
      path: destinationDir,
      file: {
        name: filename,
        buffer: buffer,
        mimeType: mimeType,
      },
    };

    const response = await this.api.post(`projects/${username}/files/upload`, { multipart });
    return { status: response.status() };
  }
}
