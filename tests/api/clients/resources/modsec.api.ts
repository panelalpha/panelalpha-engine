/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type ModSecurityAuditLogEntry,
  type ModSecurityAuditLogFile,
  type ModSecurityConfig,
  type ModSecurityRuleset,
} from '@/types';
import { WpCliApi } from './wpcli.api';

export class ModSecurityApi extends WpCliApi {
  async getModSecurityConfig(): Promise<ApiResponse<ModSecurityConfig>> {
    const response = await this.api.get('modsec/mode');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setModSecurityConfig(config: Partial<ModSecurityConfig>): Promise<void> {
    const maxAttempts = 5;
    const retryDelayMs = 2000;

    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      const response = await this.api.put('modsec/mode', { data: config });
      if (response.ok()) {
        return;
      }

      const status = response.status();
      const body = await this.parseJsonBody(response);
      const shouldRetry =
        status === 422 && this.isContainerRestartingError(body) && attempt < maxAttempts;

      if (shouldRetry) {
        await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
        continue;
      }

      const url = response.url();
      const rawBody = JSON.stringify(body);
      throw new Error(
        `API call failed\n` +
          `  URL:      ${url}\n` +
          `  Expected: 2xx  —  Got: ${status}\n` +
          `  Response: ${rawBody}`
      );
    }
  }

  async setModSecurityModeRaw(mode: string): Promise<{ status: number; body: any }> {
    const response = await this.api.put('modsec/mode', { data: { mode } });
    return this.rawCall(response);
  }

  async listModSecurityRulesets(): Promise<ApiListResponse<ModSecurityRuleset>> {
    const response = await this.api.get('modsec/rulesets');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async enableModSecurityRuleset(rulesetName: string): Promise<void> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/enable`);
    await this.assertOk(response);
  }

  async enableModSecurityRulesetRaw(rulesetName: string): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/enable`);
    return this.rawCall(response);
  }

  async disableModSecurityRuleset(rulesetName: string): Promise<void> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/disable`);
    await this.assertOk(response);
  }

  async disableModSecurityRulesetRaw(rulesetName: string): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/disable`);
    return this.rawCall(response);
  }

  async toggleModSecurityConfigFiles(
    rulesetName: string,
    enable: string[] = [],
    disable: string[] = []
  ): Promise<void> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/config-files`, {
      data: { enable, disable },
    });
    await this.assertOk(response);
  }

  async toggleModSecurityConfigFilesRaw(
    rulesetName: string,
    data: { enable?: string[]; disable?: string[] }
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`modsec/rulesets/${rulesetName}/config-files`, { data });
    return this.rawCall(response);
  }

  async listModSecurityAuditLogFiles(): Promise<ApiListResponse<ModSecurityAuditLogFile>> {
    const response = await this.api.get('modsec/audit-log/files');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async downloadModSecurityAuditLog(filename: string): Promise<{ status: number; body: Buffer }> {
    const response = await this.api.get(`modsec/audit-log/files/${filename}`);
    return { status: response.status(), body: await response.body() };
  }

  async tailModSecurityAuditLog(
    filename: string
  ): Promise<{ status: number; data: ModSecurityAuditLogEntry[] }> {
    const response = await this.api.get(`modsec/audit-log/files/${filename}/tail`);
    if (response.status() === 200) {
      const body = await response.json();
      return { status: response.status(), data: body.data ?? [] };
    }
    return { status: response.status(), data: [] };
  }
}
