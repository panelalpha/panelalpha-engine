/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiAccessAllowRequest,
  type ApiAccessEntry,
  type ApiAccessRemoval,
  type ApiAccessRotation,
  type ApiResponse,
  type EngineCertificateRequest,
  type Ipv4NatMap,
  type SslConfig,
  type SystemInfo,
} from '@/types';
import { describeAttempts, httpReason, recordAttempts } from '@/helpers/attempts';
import { ModSecurityApi } from './modsec.api';

export class SystemApi extends ModSecurityApi {
  async getSystemInfo(): Promise<ApiResponse<SystemInfo>> {
    const response = await this.api.get('system/info');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getIpv4NatMaps(): Promise<ApiResponse<Ipv4NatMap[]>> {
    const response = await this.api.get('system/ipv4-nat-maps');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async upsertIpv4NatMap(data: Ipv4NatMap): Promise<ApiResponse<Ipv4NatMap>> {
    const response = await this.api.put('system/ipv4-nat-maps', { data });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deleteIpv4NatMap(id: number): Promise<void> {
    const response = await this.api.delete(`system/ipv4-nat-maps/${id}`);
    await this.assertStatus(response, [200, 204]);
  }

  async rebuildIpv4NatMaps(options?: { replace_default_ipv4?: boolean }): Promise<
    ApiResponse<{
      maps: Ipv4NatMap[];
      default_ipv4_replaced?: boolean;
      default_ipv4_previous?: string | null;
      default_ipv4_current?: string | null;
    }>
  > {
    const response = await this.api.post('system/ipv4-nat-maps/rebuild', { data: options ?? {} });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async updateSystemNetworkConfig(payload: {
    default_ipv4?: string;
    default_ipv6?: string;
  }): Promise<ApiResponse<SystemInfo>> {
    const maxAttempts = 5;
    const retryDelayMs = 2000;

    const reasons: string[] = [];

    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
      const response = await this.api.put('system/network-config', { data: payload });
      if (response.ok()) {
        recordAttempts('PUT system/network-config', attempt, reasons);
        return response.json();
      }

      const status = response.status();
      const body = await this.parseJsonBody(response);
      const shouldRetry =
        status === 422 && this.isContainerRestartingError(body) && attempt < maxAttempts;

      reasons.push(httpReason(status, body));
      if (shouldRetry) {
        await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
        continue;
      }

      recordAttempts('PUT system/network-config', attempt, reasons);
      await this.assertStatus(response, 200);
    }

    throw new Error(
      `PUT /system/network-config did not return 200 (${describeAttempts(maxAttempts, reasons)})`
    );
  }

  async getCurrentMetrics(): Promise<ApiResponse<any>> {
    const response = await this.api.get('metrics/current');
    await this.assertOk(response);
    return response.json();
  }

  async getLast5MinutesMetrics(): Promise<ApiResponse<any>> {
    const response = await this.api.get('metrics/last-5-minutes');
    await this.assertOk(response);
    return response.json();
  }

  async getLastHourMetrics(): Promise<ApiResponse<any>> {
    const response = await this.api.get('metrics/last-hour');
    await this.assertOk(response);
    return response.json();
  }

  async getLast12HoursMetrics(): Promise<ApiResponse<any>> {
    const response = await this.api.get('metrics/last-12-hours');
    await this.assertOk(response);
    return response.json();
  }

  async getLastHourAverages(): Promise<
    ApiResponse<{ avg_cpu_percent: number; avg_ram_percent: number }>
  > {
    const response = await this.api.get('metrics/last-hour-averages');
    await this.assertOk(response);
    return response.json();
  }

  async updateSystem(payload: { license_key?: string | null } = {}): Promise<ApiResponse<any>> {
    const response = await this.api.put('system/update', { data: payload });
    await this.assertOk(response);
    return response.json();
  }

  async updateSystemRaw(
    payload: { license_key?: string | null | number } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put('system/update', { data: payload });
    return this.rawCall(response);
  }

  /** Destructive: switches the active webserver (Apache, LiteSpeed, etc.). */
  async changeWebserver(
    newWebserver: 'nginx' | 'nginx-proxy' | 'apache' | 'litespeed' | 'openlitespeed',
    options?: { serial_number?: string }
  ): Promise<ApiResponse<any>> {
    const data: { new_webserver: string; serial_number?: string } = {
      new_webserver: newWebserver,
    };
    const serialNumber = options?.serial_number?.trim();
    if (serialNumber) {
      data.serial_number = serialNumber;
    }

    const response = await this.api.put('system/change-webserver', {
      data,
    });
    await this.assertStatus(response, [200, 202]);
    return response.json();
  }

  async changeWebserverRaw(payload: {
    new_webserver: string;
    serial_number?: string;
  }): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put('system/change-webserver', {
      data: payload,
    });
    return this.rawCall(response);
  }

  async resetWebserverPanelPassword(): Promise<ApiResponse<{ new_password: string }>> {
    const response = await this.api.put('system/reset-webserver-panel-password');
    await this.assertOk(response);
    return response.json();
  }

  async updateWebserverConfig(config: { serial_number: string }): Promise<ApiResponse<any>> {
    const response = await this.api.put('system/webserver-config', {
      data: config,
    });
    await this.assertOk(response);
    return response.json();
  }

  async getSslConfig(): Promise<ApiResponse<SslConfig>> {
    const response = await this.api.get('system/ssl-config');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async updateSslConfig(
    payload: Partial<
      Pick<SslConfig, 'issuer' | 'sites_base_domain' | 'acme_directory_url' | 'acme_email'>
    >
  ): Promise<ApiResponse<SslConfig>> {
    const response = await this.api.put('system/ssl-config', { data: payload });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async updateSslConfigRaw(
    payload: Record<string, unknown>
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put('system/ssl-config', { data: payload });
    return this.rawCall(response);
  }

  async requestEngineCertificate(
    payload: EngineCertificateRequest = {}
  ): Promise<ApiResponse<unknown>> {
    const response = await this.api.put('system/engine-certificate', { data: payload });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async requestEngineCertificateRaw(
    payload: EngineCertificateRequest = {}
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put('system/engine-certificate', { data: payload });
    return this.rawCall(response);
  }

  async updateSystemNetworkConfigRaw(payload: {
    default_ipv4?: string;
    default_ipv6?: string;
  }): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put('system/network-config', { data: payload });
    return this.rawCall(response);
  }

  /** The projects allowed to call this engine's API. Panel-facing. */
  async listApiAccess(): Promise<ApiResponse<ApiAccessEntry[]>> {
    const response = await this.api.get('system/api-access');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listApiAccessRaw(): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.get('system/api-access'));
  }

  async allowApiAccess(
    project: string,
    payload: ApiAccessAllowRequest
  ): Promise<ApiResponse<ApiAccessEntry>> {
    const response = await this.api.put(`system/api-access/${project}`, { data: payload });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async allowApiAccessRaw(
    project: string,
    payload: Record<string, unknown>
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put(`system/api-access/${project}`, { data: payload });
    return this.rawCall(response);
  }

  async denyApiAccess(project: string): Promise<ApiResponse<ApiAccessRemoval>> {
    const response = await this.api.delete(`system/api-access/${project}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async denyApiAccessRaw(project: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.delete(`system/api-access/${project}`));
  }

  /** Replaces an allowed project's token; the apply that delivers it is asynchronous. */
  async rotateApiAccess(project: string): Promise<ApiAccessRotation> {
    const response = await this.api.post(`system/api-access/${project}/rotate`);
    await this.assertStatus(response, 202);
    return response.json();
  }

  async rotateApiAccessRaw(project: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.post(`system/api-access/${project}/rotate`));
  }
}
