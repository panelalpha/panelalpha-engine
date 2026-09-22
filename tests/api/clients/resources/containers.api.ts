/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiResponse,
  type ContainerCommandResult,
  type DeployLogSnapshot,
  type ProjectContainerAction,
  type ServiceContainerAction,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

function deployLogQuery(options: { offset?: number; buildTimings?: boolean } = {}): string {
  const query = new URLSearchParams();
  if (options.offset !== undefined) {
    query.set('offset', String(options.offset));
  }
  if (options.buildTimings === true) {
    query.set('build_timings', '1');
  }
  const encoded = query.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

export class ContainersApi extends EngineApiBase {
  async listContainers(username: string): Promise<ApiResponse<unknown[]>> {
    const response = await this.api.get(`projects/${username}/containers`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listContainersRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/containers`);
    return this.rawCall(response);
  }

  async runProjectContainerAction(
    username: string,
    action: ProjectContainerAction
  ): Promise<ContainerCommandResult> {
    const response = await this.api.post(`projects/${username}/containers/action`, {
      data: { action },
    });
    await this.assertStatus(response, [200, 500]);
    return response.json();
  }

  async runProjectContainerActionRaw(
    username: string,
    action: ProjectContainerAction
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/containers/action`, {
      data: { action },
    });
    return this.rawCall(response);
  }

  async runServiceContainerAction(
    username: string,
    service: string,
    action: ServiceContainerAction
  ): Promise<ContainerCommandResult> {
    const response = await this.api.post(`projects/${username}/containers/${service}/action`, {
      data: { action },
    });
    await this.assertStatus(response, [200, 422, 500]);
    return response.json();
  }

  async runServiceContainerActionRaw(
    username: string,
    service: string,
    action: ServiceContainerAction
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/containers/${service}/action`, {
      data: { action },
    });
    return this.rawCall(response);
  }

  async getContainerLogs(
    username: string,
    service: string,
    lines?: number
  ): Promise<ApiResponse<string>> {
    const url =
      lines === undefined
        ? `projects/${username}/containers/${service}/logs`
        : `projects/${username}/containers/${service}/logs?lines=${lines}`;
    const response = await this.api.get(url);
    await this.assertStatus(response, [200, 422]);
    return response.json();
  }

  async getContainerLogsRaw(
    username: string,
    service: string,
    lines?: number
  ): Promise<{ status: number; body: any }> {
    const url =
      lines === undefined
        ? `projects/${username}/containers/${service}/logs`
        : `projects/${username}/containers/${service}/logs?lines=${lines}`;
    const response = await this.api.get(url);
    return this.rawCall(response);
  }

  async getDeployLog(
    username: string,
    options: { offset?: number; buildTimings?: boolean } = {}
  ): Promise<ApiResponse<DeployLogSnapshot>> {
    const response = await this.api.get(
      `projects/${username}/deploy-log${deployLogQuery(options)}`
    );
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getDeployLogRaw(
    username: string,
    options: { offset?: number; buildTimings?: boolean } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(
      `projects/${username}/deploy-log${deployLogQuery(options)}`
    );
    return this.rawCall(response);
  }

  async cancelDeploy(username: string): Promise<ApiResponse<{ cancelled: boolean }>> {
    const response = await this.api.post(`projects/${username}/deploy-cancel`);
    await this.assertStatus(response, [200, 409]);
    return response.json();
  }

  async cancelDeployRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/deploy-cancel`);
    return this.rawCall(response);
  }
}
