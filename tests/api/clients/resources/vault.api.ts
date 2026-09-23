/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type CreateVaultSecretRequest,
  type CreatedVaultSecret,
  type DeletedVaultSecret,
  type VaultSecretEntry,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

/**
 * Secret vault: a one-time browser handoff of a secret the API caller must not
 * relay. `create` mints a `vault:<ref>` plus the URL of the form the customer
 * pastes into; every other call reports status only, never the secret.
 */
export class VaultApi extends EngineApiBase {
  async createVaultSecret(
    data: CreateVaultSecretRequest
  ): Promise<ApiResponse<CreatedVaultSecret>> {
    const response = await this.api.post('vault/secrets', { data });
    await this.assertStatus(response, 201);
    return response.json();
  }

  async createVaultSecretRaw(data: unknown): Promise<{ status: number; body: any }> {
    const response = await this.api.post('vault/secrets', { data });
    return this.rawCall(response);
  }

  async listVaultSecrets(type?: string): Promise<ApiListResponse<VaultSecretEntry>> {
    const response = await this.api.get(
      type ? `vault/secrets?type=${encodeURIComponent(type)}` : 'vault/secrets'
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listVaultSecretsRaw(type?: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(
      type ? `vault/secrets?type=${encodeURIComponent(type)}` : 'vault/secrets'
    );
    return this.rawCall(response);
  }

  /** `ref` is accepted both as `vault:<id>` and as the bare `<id>`. */
  async getVaultSecret(ref: string): Promise<ApiResponse<VaultSecretEntry>> {
    const response = await this.api.get(`vault/secrets/${encodeURIComponent(ref)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getVaultSecretRaw(ref: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`vault/secrets/${encodeURIComponent(ref)}`);
    return this.rawCall(response);
  }

  async deleteVaultSecret(ref: string): Promise<ApiResponse<DeletedVaultSecret>> {
    const response = await this.api.delete(`vault/secrets/${encodeURIComponent(ref)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async deleteVaultSecretRaw(ref: string): Promise<{ status: number; body: any }> {
    const response = await this.api.delete(`vault/secrets/${encodeURIComponent(ref)}`);
    return this.rawCall(response);
  }

  /** Best-effort teardown: a spec that already deleted the entry still passes. */
  async deleteVaultSecretSafe(ref: string): Promise<void> {
    await this.api.delete(`vault/secrets/${encodeURIComponent(ref)}`).catch(() => undefined);
  }
}
