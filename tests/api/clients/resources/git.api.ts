/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiResponse,
  type GitChangeBranchRequest,
  type GitCommitsQuery,
  type GitConnectRequest,
  type GitPathRequest,
  type GitPullRequest,
  type GitRevertRequest,
  type GitStatus,
  type GitUpdateCredentialsRequest,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

function gitQuery(
  params: { path?: string; fetch?: boolean; branch?: string; limit?: number } = {}
): string {
  const query = new URLSearchParams();
  if (params.path !== undefined && params.path !== '') {
    query.set('path', params.path);
  }
  if (params.fetch === true) {
    query.set('fetch', '1');
  }
  if (params.branch !== undefined && params.branch !== '') {
    query.set('branch', params.branch);
  }
  if (params.limit !== undefined) {
    query.set('limit', String(params.limit));
  }
  const encoded = query.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

export class GitApi extends EngineApiBase {
  async gitStatus(
    username: string,
    options: { path?: string; fetch?: boolean } = {}
  ): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.get(`projects/${username}/git/status${gitQuery(options)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitStatusRaw(
    username: string,
    options: { path?: string; fetch?: boolean } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/git/status${gitQuery(options)}`);
    return this.rawCall(response);
  }

  async gitBranches(
    username: string,
    options: GitPathRequest = {}
  ): Promise<ApiResponse<unknown[]>> {
    const response = await this.api.get(`projects/${username}/git/branches${gitQuery(options)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitBranchesRaw(
    username: string,
    options: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/git/branches${gitQuery(options)}`);
    return this.rawCall(response);
  }

  async gitCommits(
    username: string,
    options: GitCommitsQuery = {}
  ): Promise<ApiResponse<unknown[]>> {
    const response = await this.api.get(`projects/${username}/git/commits${gitQuery(options)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitCommitsRaw(
    username: string,
    options: GitCommitsQuery = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/git/commits${gitQuery(options)}`);
    return this.rawCall(response);
  }

  async gitConnect(username: string, data: GitConnectRequest): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.post(`projects/${username}/git/connect`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitConnectRaw(
    username: string,
    data: Partial<GitConnectRequest>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/connect`, { data });
    return this.rawCall(response);
  }

  async gitDisconnect(
    username: string,
    data: GitPathRequest = {}
  ): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.post(`projects/${username}/git/disconnect`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitDisconnectRaw(
    username: string,
    data: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/disconnect`, { data });
    return this.rawCall(response);
  }

  async gitChangeBranch(
    username: string,
    data: GitChangeBranchRequest
  ): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.put(`projects/${username}/git/change-branch`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitChangeBranchRaw(
    username: string,
    data: Partial<GitChangeBranchRequest>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`projects/${username}/git/change-branch`, { data });
    return this.rawCall(response);
  }

  async gitUpdateCredentials(
    username: string,
    data: GitUpdateCredentialsRequest = {}
  ): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.put(`projects/${username}/git/update-credentials`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitPull(username: string, data: GitPullRequest = {}): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.post(`projects/${username}/git/pull`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitPullRaw(
    username: string,
    data: GitPullRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/pull`, { data });
    return this.rawCall(response);
  }

  async gitPush(username: string, data: GitPathRequest = {}): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.post(`projects/${username}/git/push`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitPushRaw(
    username: string,
    data: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/push`, { data });
    return this.rawCall(response);
  }

  async gitRevert(username: string, data: GitRevertRequest = {}): Promise<ApiResponse<GitStatus>> {
    const response = await this.api.post(`projects/${username}/git/revert`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async gitRevertRaw(
    username: string,
    data: GitRevertRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/revert`, { data });
    return this.rawCall(response);
  }

  async createDeployHookRaw(
    username: string,
    data: { path?: string; provider?: string } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/deploy-hook`, { data });
    return this.rawCall(response);
  }

  async getDeployHookRaw(
    username: string,
    options: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}/git/deploy-hook${gitQuery(options)}`);
    return this.rawCall(response);
  }

  async rotateDeployHookRaw(
    username: string,
    data: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/git/deploy-hook/rotate`, { data });
    return this.rawCall(response);
  }

  async deleteDeployHookRaw(
    username: string,
    options: GitPathRequest = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.delete(
      `projects/${username}/git/deploy-hook${gitQuery(options)}`
    );
    return this.rawCall(response);
  }
}
