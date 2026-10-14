import {
  type ApiResponse,
  type InspectReport,
  type InspectSourceRequest,
  type SourceRefs,
  type SourceRefsRequest,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

export class InspectApi extends EngineApiBase {
  async inspectSource(data: InspectSourceRequest): Promise<ApiResponse<InspectReport>> {
    const response = await this.api.post('source/inspect', { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async inspectSourceRaw(
    data: Partial<InspectSourceRequest>,
    options: { timeout?: number } = {}
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.post('source/inspect', {
      data,
      ...(options.timeout !== undefined ? { timeout: options.timeout } : {}),
    });
    return this.rawCall(response);
  }

  async inspectProject(username: string): Promise<ApiResponse<InspectReport>> {
    const response = await this.api.get(`projects/${username}/inspect`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async inspectProjectRaw(username: string): Promise<{ status: number; body: unknown }> {
    const response = await this.api.get(`projects/${username}/inspect`);
    return this.rawCall(response);
  }

  async inspectProjectDeprecatedRaw(username: string): Promise<{ status: number; body: unknown }> {
    const response = await this.api.get(`projects/${username}/source-inspection`);
    return this.rawCall(response);
  }

  async refsSource(data: SourceRefsRequest): Promise<ApiResponse<SourceRefs>> {
    const response = await this.api.post('source/refs', { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async refsSourceRaw(
    data: Partial<SourceRefsRequest>
  ): Promise<{ status: number; body: unknown }> {
    const response = await this.api.post('source/refs', { data });
    return this.rawCall(response);
  }
}
