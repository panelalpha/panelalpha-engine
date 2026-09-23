import { type ApiListResponse, type ApiResponse, type PhpVersion } from '@/types';
import { DomainsApi } from './domains.api';

export class PhpApi extends DomainsApi {
  async getAvailablePhpVersions(): Promise<ApiListResponse<PhpVersion>> {
    const response = await this.api.get('php/available-versions');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getDomainPhpVersion(domain: string): Promise<ApiResponse<PhpVersion>> {
    const response = await this.api.get(`domains/${domain}/php-version`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setDomainPhpVersion(domain: string, version: PhpVersion): Promise<void> {
    const response = await this.api.put(`domains/${domain}/php-version`, {
      data: { version },
    });
    await this.assertStatus(response, [200, 204]);
  }

  async getCustomIniSettings(
    username: string,
    version: PhpVersion
  ): Promise<ApiResponse<Record<string, string>>> {
    const response = await this.api.get(
      `projects/${username}/php/custom-ini-settings?php_version=${version}`
    );
    await this.assertOk(response);
    return response.json();
  }

  async setCustomIniSettings(
    username: string,
    version: PhpVersion,
    settings: Record<string, string>
  ): Promise<void> {
    const response = await this.api.put(`projects/${username}/php/custom-ini-settings`, {
      data: { php_version: version, settings },
    });
    await this.assertStatus(response, 204);
  }

  async getDomainPhpDirectives(domain: string): Promise<ApiResponse<Record<string, string>>> {
    const response = await this.api.get(`domains/${encodeURIComponent(domain)}/php-directives`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getDomainPhpDirectivesRaw(domain: string): Promise<{ status: number; body: unknown }> {
    const response = await this.api.get(`domains/${encodeURIComponent(domain)}/php-directives`);
    return this.rawCall(response);
  }

  async setDomainPhpDirectives(domain: string, settings: Record<string, string>): Promise<void> {
    const response = await this.api.put(`domains/${encodeURIComponent(domain)}/php-directives`, {
      data: { settings },
    });
    await this.assertStatus(response, 204);
  }

  async setDomainPhpDirectivesRaw(
    domain: string,
    settings: Record<string, string> | Record<string, unknown>
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.put(`domains/${encodeURIComponent(domain)}/php-directives`, {
      data: { settings },
    });
    return this.rawCall(response);
  }
}
