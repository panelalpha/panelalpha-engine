/* eslint-disable @typescript-eslint/no-explicit-any */

import { type APIResponse } from '@playwright/test';
import { EngineApiBase } from '../engine-api-base';

export type WpMethod = 'GET' | 'POST' | 'PUT' | 'DELETE';

/** WP-CLI operations under `projects/{username}/wp/`; `operation` is the path after `wp/`. */
export class WordPressApi extends EngineApiBase {
  async wpRaw(
    username: string,
    method: WpMethod,
    operation: string,
    data?: Record<string, unknown>
  ): Promise<{ status: number; body: any }> {
    return this.rawCall(await this.sendWp(username, method, operation, data));
  }

  /** {@link wpRaw}, asserting 200 and answering `data`. */
  async wp<T = any>(
    username: string,
    method: WpMethod,
    operation: string,
    data?: Record<string, unknown>
  ): Promise<T> {
    const response = await this.sendWp(username, method, operation, data);
    await this.assertStatus(response, 200);
    return ((await response.json()) as { data: T }).data;
  }

  private sendWp(
    username: string,
    method: WpMethod,
    operation: string,
    data?: Record<string, unknown>
  ): Promise<APIResponse> {
    const url = `projects/${username}/wp/${operation}`;
    const options = data === undefined ? {} : { data };
    switch (method) {
      case 'GET':
        return this.api.get(url);
      case 'POST':
        return this.api.post(url, options);
      case 'PUT':
        return this.api.put(url, options);
      case 'DELETE':
        return this.api.delete(url, options);
    }
  }
}
