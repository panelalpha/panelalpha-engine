/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type AppHealth,
  type CreateAppUserRequest,
  type InstallAppRequest,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

export class AppUsersApi extends EngineApiBase {
  async listAppUsers(username: string): Promise<ApiListResponse<Record<string, unknown>>> {
    const response = await this.api.get(`projects/${username}/app/users`);
    await this.assertStatus(response, [200, 403, 404, 422]);
    return response.json();
  }

  async listAppUsersRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/app/users`);
    return this.rawCall(response);
  }

  async createAppUser(
    username: string,
    data: CreateAppUserRequest
  ): Promise<ApiResponse<Record<string, unknown>>> {
    const response = await this.api.post(`projects/${username}/app/users`, { data });
    await this.assertStatus(response, 201);
    return response.json();
  }

  async createAppUserRaw(
    username: string,
    data: CreateAppUserRequest
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/app/users`, { data });
    return this.rawCall(response);
  }

  async deleteAppUser(username: string, userId: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/app/users/${userId}`);
    await this.assertStatus(response, 204);
  }

  async resetAppUserPassword(username: string, userId: string, password: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/app/users/${userId}/password`, {
      data: { password },
    });
    await this.assertStatus(response, 204);
  }

  async createAppUserSso(username: string, userId: string): Promise<{ url: string }> {
    const response = await this.api.post(`projects/${username}/app/users/${userId}/sso`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getAppSsoTokenRaw(
    username: string,
    token: string
  ): Promise<{ status: number; body: any; headers: Record<string, string> }> {
    const response = await this.api.get(
      `projects/${username}/app/sso-token?token=${encodeURIComponent(token)}`
    );
    const headers: Record<string, string> = {};
    for (const [key, value] of Object.entries(response.headers())) {
      headers[key] = value;
    }
    const body = await response.text().catch(() => '');
    return { status: response.status(), body, headers };
  }

  async getAppInfo(username: string): Promise<ApiResponse<Record<string, unknown>>> {
    const response = await this.api.get(`projects/${username}/app/info`);
    await this.assertStatus(response, [200, 403, 404, 422]);
    return response.json();
  }

  async getAppInfoRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/app/info`);
    return this.rawCall(response);
  }

  async getAppRoles(username: string): Promise<ApiResponse<string[]>> {
    const response = await this.api.get(`projects/${username}/app/roles`);
    await this.assertStatus(response, [200, 403, 404, 422]);
    return response.json();
  }

  async getAppRolesRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/app/roles`);
    return this.rawCall(response);
  }

  async installApp(username: string, data: InstallAppRequest): Promise<void> {
    const response = await this.api.post(`projects/${username}/app/install`, { data });
    await this.assertStatus(response, 204);
  }

  async installAppRaw(
    username: string,
    data: InstallAppRequest
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/app/install`, { data });
    return this.rawCall(response);
  }

  async getAppHealth(
    username: string,
    options: { timeout?: number; attempts?: number } = {}
  ): Promise<ApiResponse<AppHealth>> {
    const params = new URLSearchParams();
    if (options.timeout !== undefined) {
      params.set('timeout', String(options.timeout));
    }
    if (options.attempts !== undefined) {
      params.set('attempts', String(options.attempts));
    }
    const query = params.toString();
    const url = query
      ? `projects/${username}/app/health?${query}`
      : `projects/${username}/app/health`;
    const response = await this.api.get(url);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getAppHealthRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/app/health`);
    return this.rawCall(response);
  }
}
