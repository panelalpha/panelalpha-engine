import { type OverlayPosition, type ProjectOverlayState, type SystemOverlayState } from '@/types';
import { EngineApiBase } from '../engine-api-base';

export class OverlayApi extends EngineApiBase {
  async getProjectOverlay(username: string): Promise<ProjectOverlayState> {
    const response = await this.api.get(`projects/${username}/overlay`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setProjectOverlay(
    username: string,
    data: { html?: string | null; position?: OverlayPosition; show_global?: boolean }
  ): Promise<ProjectOverlayState> {
    const response = await this.api.put(`projects/${username}/overlay`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setProjectOverlayRaw(
    username: string,
    data: Record<string, unknown>
  ): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.put(`projects/${username}/overlay`, { data }));
  }

  async deleteProjectOverlay(username: string): Promise<ProjectOverlayState> {
    const response = await this.api.delete(`projects/${username}/overlay`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getSystemOverlay(): Promise<SystemOverlayState> {
    const response = await this.api.get('system/overlay');
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setSystemOverlay(data: {
    html: string;
    position?: OverlayPosition;
  }): Promise<SystemOverlayState> {
    const response = await this.api.put('system/overlay', { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async setSystemOverlayRaw(
    data: Record<string, unknown>
  ): Promise<{ status: number; body: unknown }> {
    return this.rawCall(await this.api.put('system/overlay', { data }));
  }

  async deleteSystemOverlay(): Promise<SystemOverlayState> {
    const response = await this.api.delete('system/overlay');
    await this.assertStatus(response, 200);
    return response.json();
  }
}
