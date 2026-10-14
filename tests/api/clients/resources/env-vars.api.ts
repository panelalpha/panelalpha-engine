/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiResponse,
  type EnvVarsStoreResult,
  type EnvVarsUpdateRequest,
  type EnvVarsView,
  type TaskSnapshot,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

export class EnvVarsApi extends EngineApiBase {
  async listEnvVars(
    username: string,
    options: { reveal?: boolean } = {}
  ): Promise<ApiResponse<EnvVarsView>> {
    const response = await this.api.get(this.path(username, options));
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listEnvVarsRaw(
    username: string,
    options: { reveal?: boolean } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(this.path(username, options));
    return this.rawCall(response);
  }

  async setEnvVars(
    username: string,
    data: EnvVarsUpdateRequest
  ): Promise<ApiResponse<TaskSnapshot | EnvVarsStoreResult>> {
    const response = await this.api.put(`projects/${username}/env-vars`, { data });
    await this.assertStatus(response, [200, 202]);
    return response.json();
  }

  async setEnvVarsRaw(
    username: string,
    data: Partial<EnvVarsUpdateRequest>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`projects/${username}/env-vars`, { data });
    return this.rawCall(response);
  }

  private path(username: string, options: { reveal?: boolean }): string {
    const suffix = options.reveal === true ? '?reveal=1' : '';
    return `projects/${username}/env-vars${suffix}`;
  }
}
