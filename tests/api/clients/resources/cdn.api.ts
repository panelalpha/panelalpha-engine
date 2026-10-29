/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiResponse,
  type CdnPurgeRequest,
  type CdnPurgeResult,
  type CdnSecurityLevelRequest,
  type CdnSecurityLevelResult,
  type CdnStatus,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

export class CdnApi extends EngineApiBase {
  async getCdn(username: string, domain: string): Promise<ApiResponse<CdnStatus>> {
    const response = await this.api.get(`projects/${username}/domains/${domain}/cdn`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getCdnRaw(username: string, domain: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/domains/${domain}/cdn`);
    return this.rawCall(response);
  }

  async purgeCdn(
    username: string,
    domain: string,
    data: CdnPurgeRequest
  ): Promise<ApiResponse<CdnPurgeResult>> {
    const response = await this.api.post(`projects/${username}/domains/${domain}/cdn/purge`, {
      data,
    });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async purgeCdnRaw(
    username: string,
    domain: string,
    data: Partial<CdnPurgeRequest>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/domains/${domain}/cdn/purge`, {
      data,
    });
    return this.rawCall(response);
  }

  async setCdnSecurityLevel(
    username: string,
    domain: string,
    data: CdnSecurityLevelRequest
  ): Promise<ApiResponse<CdnSecurityLevelResult>> {
    const response = await this.api.put(
      `projects/${username}/domains/${domain}/cdn/security-level`,
      { data }
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setCdnSecurityLevelRaw(
    username: string,
    domain: string,
    data: Partial<CdnSecurityLevelRequest>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put(
      `projects/${username}/domains/${domain}/cdn/security-level`,
      { data }
    );
    return this.rawCall(response);
  }
}
