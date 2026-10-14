import {
  type ApiListResponse,
  type ApiResponse,
  type CreateSftpAccountRequest,
  type SftpAccount,
  type SftpSessionsKilled,
  type UpdateSftpAccountRequest,
} from '@/types';
import { FtpApi } from './ftp.api';

export class SftpApi extends FtpApi {
  async listSftpAccounts(username: string): Promise<ApiListResponse<SftpAccount>> {
    const response = await this.api.get(`projects/${username}/sftp-accounts`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createSftpAccount(
    username: string,
    data: CreateSftpAccountRequest
  ): Promise<ApiResponse<SftpAccount>> {
    const response = await this.api.post(`projects/${username}/sftp-accounts`, { data });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deleteSftpAccount(username: string, sftpUsername: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/sftp-accounts/${sftpUsername}`);
    await this.assertStatus(response, 200);
  }

  async updateSftpAccount(
    username: string,
    sftpUsername: string,
    data: UpdateSftpAccountRequest
  ): Promise<ApiResponse<SftpAccount>> {
    const response = await this.api.put(`projects/${username}/sftp-accounts/${sftpUsername}`, {
      data,
    });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async killSftpSessions(
    username: string,
    sftpUsername: string
  ): Promise<ApiResponse<SftpSessionsKilled>> {
    const response = await this.api.post(
      `projects/${username}/sftp-accounts/${sftpUsername}/kill-sessions`
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async killAllSftpSessions(username: string): Promise<ApiResponse<SftpSessionsKilled>> {
    const response = await this.api.post(`projects/${username}/sftp-accounts/kill-sessions`);
    await this.assertStatus(response, 200);
    return response.json();
  }
}
