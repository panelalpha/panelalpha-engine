import {
  type ApiResponse,
  type FirewallLogEntry,
  type FirewallLogQuery,
  type FirewallRule,
  type FirewallRuleRequest,
  type FirewallStatus,
  type TrustedAddress,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

/** The host firewall (`/firewall/*`), whichever provider the engine runs. */
export class FirewallApi extends EngineApiBase {
  async getFirewallStatus(): Promise<ApiResponse<FirewallStatus>> {
    const response = await this.api.get('firewall/status');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async enableFirewall(): Promise<void> {
    const response = await this.api.put('firewall/enable');
    await this.assertStatus(response, 200);
  }

  async disableFirewall(): Promise<void> {
    const response = await this.api.put('firewall/disable');
    await this.assertStatus(response, 200);
  }

  async reloadFirewall(): Promise<void> {
    const response = await this.api.put('firewall/reload');
    await this.assertStatus(response, 200);
  }

  async listFirewallRules(): Promise<ApiResponse<FirewallRule[]>> {
    const response = await this.api.get('firewall/rules');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createFirewallRule(rule: FirewallRuleRequest): Promise<ApiResponse<FirewallRule>> {
    const response = await this.api.post('firewall/rules', { data: rule });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createFirewallRuleRaw(data: unknown): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.post('firewall/rules', { data }));
  }

  /** The rule may come back under a new id: the id follows what the rule matches. */
  async updateFirewallRule(
    id: string,
    changes: Partial<FirewallRuleRequest>
  ): Promise<ApiResponse<FirewallRule>> {
    const response = await this.api.put(`firewall/rules/${id}`, { data: changes });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async updateFirewallRuleRaw(
    id: string,
    data: unknown
  ): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.put(`firewall/rules/${id}`, { data }));
  }

  async deleteFirewallRule(id: string): Promise<ApiResponse<FirewallRule>> {
    const response = await this.api.delete(`firewall/rules/${id}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async deleteFirewallRuleRaw(id: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.delete(`firewall/rules/${id}`));
  }

  async getFirewallLogs(query: FirewallLogQuery = {}): Promise<ApiResponse<FirewallLogEntry[]>> {
    const response = await this.api.get('firewall/logs', { params: { ...query } });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listTrustedAddresses(): Promise<ApiResponse<TrustedAddress[]>> {
    const response = await this.api.get('firewall/trusted');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async trustAddress(address: string, comment?: string): Promise<ApiResponse<TrustedAddress>> {
    const response = await this.api.post('firewall/trusted', { data: { address, comment } });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async trustAddressRaw(data: unknown): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.post('firewall/trusted', { data }));
  }

  async untrustAddressRaw(id: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.delete(`firewall/trusted/${id}`));
  }

  async getFirewallLogsRaw(
    query: Record<string, string>
  ): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.get('firewall/logs', { params: query }));
  }
}
