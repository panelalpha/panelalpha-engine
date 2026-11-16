import { EngineApiBase } from '../engine-api-base';

/** Raw calls for the typed project create, its source, uploaded archives and vault deploy keys. */
export class ProjectSourceApi extends EngineApiBase {
  async createTypedProjectRaw(data: unknown): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.post('projects', { data }));
  }

  async setProjectSourceRaw(
    username: string,
    data: unknown
  ): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.put(`projects/${username}/source`, { data }));
  }

  async uploadArchiveRaw(
    filename: string,
    buffer: Buffer
  ): Promise<{ status: number; body: unknown }> {
    const multipart = { file: { name: filename, mimeType: 'application/octet-stream', buffer } };

    return this.rawCall(await this.api.post('uploads', { multipart }));
  }

  async getUploadRaw(id: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.get(`uploads/${id}`));
  }

  async deleteUploadRaw(id: string): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.delete(`uploads/${id}`));
  }

  async createVaultDeployKeyRaw(data: unknown = {}): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.post('vault/deploy-keys', { data }));
  }
}
