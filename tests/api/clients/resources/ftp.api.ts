import {
  type ApiListResponse,
  type ApiResponse,
  type CreateFtpAccountRequest,
  type FtpAccount,
  type UpdateFtpAccountRequest,
} from '@/types';
import { MySqlApi } from './mysql.api';

export class FtpApi extends MySqlApi {
  async listFtpAccounts(username: string): Promise<ApiListResponse<FtpAccount>> {
    const response = await this.api.get(`projects/${username}/ftp-accounts`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createFtpAccount(
    username: string,
    data: CreateFtpAccountRequest
  ): Promise<ApiResponse<FtpAccount>> {
    const response = await this.api.post(`projects/${username}/ftp-accounts`, { data });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deleteFtpAccount(username: string, ftpUsername: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/ftp-accounts/${ftpUsername}`);
    await this.assertOk(response);
  }

  async updateFtpAccount(
    username: string,
    ftpUsername: string,
    data: UpdateFtpAccountRequest
  ): Promise<ApiResponse<FtpAccount>> {
    const response = await this.api.put(`projects/${username}/ftp-accounts/${ftpUsername}`, {
      data,
    });
    await this.assertOk(response);
    return response.json();
  }
}
