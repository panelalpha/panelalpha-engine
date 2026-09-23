import {
  type ApiListResponse,
  type ApiResponse,
  type CreateDomainRequest,
  type Domain,
  type LogFile,
  type SslCertificate,
  type UpdateDomainRequest,
} from '@/types';
import { EximApi } from './exim.api';

export class DomainsApi extends EximApi {
  async listUserDomains(username: string): Promise<ApiListResponse<Domain>> {
    const response = await this.api.get(`projects/${username}/domains`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getDomain(username: string, domain: string): Promise<ApiResponse<Domain>> {
    const response = await this.api.get(`projects/${username}/domains/${domain}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createDomain(username: string, data: CreateDomainRequest): Promise<ApiResponse<Domain>> {
    const response = await this.api.post(`projects/${username}/domains`, { data });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async updateDomain(
    username: string,
    domain: string,
    data: UpdateDomainRequest
  ): Promise<ApiResponse<Domain>> {
    const response = await this.api.put(`projects/${username}/domains/${domain}`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async deleteDomain(username: string, domain: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/domains/${domain}`);
    if (response.status() === 404) {
      return;
    }
    await this.assertStatus(response, 200);
  }

  async getDomainGlobal(domain: string): Promise<ApiResponse<Domain>> {
    const response = await this.api.get(`domains/${domain}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listSslCertificates(username: string): Promise<ApiListResponse<SslCertificate>> {
    const response = await this.api.get(`projects/${username}/domains/installed-ssl-certs`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async installSslCertificate(
    username: string,
    domain: string,
    cert: string,
    key: string,
    ca = ''
  ): Promise<void> {
    const response = await this.api.put(`projects/${username}/domains/${domain}/install-ssl-cert`, {
      data: { cert, key, ca },
    });
    await this.assertStatus(response, [200, 400, 422]);
  }

  async listDomainLogFiles(
    username: string,
    domain: string,
    allWebservers = false
  ): Promise<ApiListResponse<LogFile>> {
    const url = allWebservers
      ? `projects/${username}/domains/${domain}/log-files?all_webservers=1`
      : `projects/${username}/domains/${domain}/log-files`;
    const response = await this.api.get(url, { timeout: 60_000 });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async downloadLogFile(
    username: string,
    domain: string,
    filename: string,
    allWebservers = false
  ): Promise<Buffer> {
    const suffix = allWebservers ? '?all_webservers=1' : '';
    const response = await this.api.get(
      `projects/${username}/domains/${domain}/log-files/${filename}${suffix}`
    );
    await this.assertStatus(response, [200, 404]);
    return response.body();
  }

  async getInstalledSslCertificate(
    username: string,
    domain: string
  ): Promise<ApiResponse<SslCertificate>> {
    const response = await this.api.get(
      `projects/${username}/domains/${domain}/installed-ssl-cert`
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async requestSslCertificate(
    username: string,
    domain: string,
    options: { staging?: boolean; dry_run?: boolean } = {}
  ): Promise<ApiResponse<unknown>> {
    const response = await this.api.post(
      `projects/${username}/domains/${domain}/request-ssl-cert`,
      {
        data: options,
      }
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async requestSslCertificateRaw(
    username: string,
    domain: string,
    options: { staging?: boolean; dry_run?: boolean } = {}
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.post(
      `projects/${username}/domains/${domain}/request-ssl-cert`,
      {
        data: options,
      }
    );
    return this.rawCall(response);
  }
}
