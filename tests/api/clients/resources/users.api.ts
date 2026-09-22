/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type CreateUserRequest,
  type UpdateUserRequest,
  type User,
  type UserUsage,
} from '@/types';
import { Timeouts } from '@/config/timeouts';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { EngineApiBase } from '../engine-api-base';

/**
 * Project accounts. Paths are `/projects` (canonical). `/users` is a legacy
 * alias and is only exercised by the projects-alias spec.
 */
export class UsersApi extends EngineApiBase {
  async listUsers(perPage?: number): Promise<ApiListResponse<User>> {
    const url = perPage ? `projects?per_page=${perPage}` : 'projects';
    const response = await this.api.get(url);
    await this.assertStatus(response, 200);
    return response.json();
  }

  /**
   * Lists all users without asserting the response status
   */
  async listUsersRaw(): Promise<{ status: number; body: any }> {
    const response = await this.api.get('projects');
    return this.rawCall(response);
  }

  async listAllUsers(withDomainNames = false): Promise<ApiListResponse<User>> {
    const url = withDomainNames ? 'projects/all?with_domain_names=1' : 'projects/all';
    const response = await this.api.get(url);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getUser(username: string): Promise<ApiResponse<User>> {
    const response = await this.api.get(`projects/${username}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  /**
   * Gets user details by username (raw - no status expectation)
   */
  async getUserRaw(username: string): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`projects/${username}`);
    return this.rawCall(response);
  }

  /**
   * Canonical create: POST /projects returns 202 + a task. Polls until that
   * task is terminal, then returns GET /projects/{username}.
   */
  async createUser(data: CreateUserRequest): Promise<ApiResponse<User>> {
    const response = await this.api.post('projects', { data });
    await this.assertStatus(response, 202);
    const body: unknown = await response.json();
    const taskId = taskIdFromBody(body);
    if (taskId === undefined) {
      throw new Error('POST /projects returned 202 without a task id');
    }
    const task = await waitForTask(
      {
        getTaskRaw: async (id) => this.rawCall(await this.api.get(`tasks/${id}`)),
      },
      taskId,
      { timeout: Timeouts.deploy }
    );
    if (task.status !== 'completed') {
      throw new Error(`POST /projects task ${taskId} ended ${task.status}`);
    }
    const username = data.username || task.username;
    if (!username) {
      throw new Error('POST /projects task did not name a username');
    }
    return this.getUser(username);
  }

  /**
   * Creates a new user (raw - no status expectation)
   */
  async createUserRaw(data: CreateUserRequest): Promise<{ status: number; body: any }> {
    const response = await this.api.post('projects', { data });
    return this.rawCall(response);
  }

  async updateUser(username: string, data: UpdateUserRequest): Promise<ApiResponse<User>> {
    const response = await this.api.put(`projects/${username}`, { data });
    await this.assertStatus(response, 200);
    return response.json();
  }

  /**
   * Updates a user (raw - no status expectation)
   */
  async updateUserRaw(
    username: string,
    data: Partial<UpdateUserRequest> & Record<string, any>
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.put(`projects/${username}`, { data });
    return this.rawCall(response);
  }

  async deleteUser(username: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}`);
    await this.assertOk(response);
  }

  /**
   * Deletes a user (raw) ignoring status to ease cleanup flows
   */
  async deleteUserSafe(username: string): Promise<number> {
    const response = await this.api.delete(`projects/${username}`);
    return response.status();
  }

  async rebuildUser(username: string): Promise<void> {
    const response = await this.api.post(`projects/${username}/rebuild`);
    await this.assertStatus(response, 200);
  }

  async suspendUser(username: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/suspend`);
    await this.assertStatus(response, [200, 204]);
  }

  async unsuspendUser(username: string): Promise<void> {
    const response = await this.api.put(`projects/${username}/unsuspend`);
    await this.assertStatus(response, [200, 204]);
  }

  /**
   * Gets user usage statistics
   * Note: This endpoint returns data directly without wrapping in { data: ... }
   */
  async getUserUsage(username: string): Promise<UserUsage> {
    const response = await this.api.get(`projects/${username}/usage`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  /**
   * Verifies if a username is available
   * Returns { valid: true } if available, throws ValidationException if not
   */
  async verifyUsername(username: string): Promise<{ valid: boolean }> {
    const response = await this.api.post('projects/verify-new-username', { data: { username } });
    await this.assertStatus(response, 200);
    return response.json();
  }

  async cloneUser(
    username: string,
    data: { new_username?: string; domain?: string } = {}
  ): Promise<ApiResponse<User>> {
    const response = await this.api.post(`projects/${username}/clone`, { data });
    // A clone creates a user, so the engine answers 201; 200 is accepted too
    // rather than pinning the suite to one of two correct success codes.
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async cloneUserRaw(
    username: string,
    data: { new_username?: string; domain?: string } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/clone`, { data });
    return this.rawCall(response);
  }

  async deployArchive(
    username: string,
    data: { zip_path: string; env_vars?: Record<string, string | null> }
  ): Promise<ApiResponse<User>> {
    const response = await this.api.post(`projects/${username}/deploy-archive`, { data });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deployArchiveRaw(
    username: string,
    data: { zip_path?: string; env_vars?: Record<string, string | null> }
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/deploy-archive`, { data });
    return this.rawCall(response);
  }

  async createStaging(
    username: string,
    data: { new_username?: string; domain?: string } = {}
  ): Promise<ApiResponse<User>> {
    const response = await this.api.post(`projects/${username}/staging`, { data });
    await this.assertStatus(response, 202);
    return response.json();
  }

  async createStagingRaw(
    username: string,
    data: { new_username?: string; domain?: string } = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/staging`, { data });
    return this.rawCall(response);
  }

  async pushProject(username: string, target: string): Promise<ApiResponse<User>> {
    const response = await this.api.post(`projects/${username}/push`, { data: { target } });
    await this.assertStatus(response, 202);
    return response.json();
  }

  async pushProjectRaw(username: string, target: string): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/push`, { data: { target } });
    return this.rawCall(response);
  }

  async getProjectBandwidthRaw(
    username: string,
    query = ''
  ): Promise<{ status: number; body: any }> {
    const suffix = query === '' ? '' : `?${query}`;
    const response = await this.api.get(`projects/${username}/bandwidth${suffix}`);
    return this.rawCall(response);
  }

  async getDomainBandwidthRaw(
    username: string,
    domain: string,
    query = ''
  ): Promise<{ status: number; body: any }> {
    const suffix = query === '' ? '' : `?${query}`;
    const response = await this.api.get(
      `projects/${username}/domains/${encodeURIComponent(domain)}/bandwidth${suffix}`
    );
    return this.rawCall(response);
  }

  async getDomainVisitorsRaw(
    username: string,
    domain: string,
    query = ''
  ): Promise<{ status: number; body: any }> {
    const suffix = query === '' ? '' : `?${query}`;
    const response = await this.api.get(
      `projects/${username}/domains/${encodeURIComponent(domain)}/visitors${suffix}`
    );
    return this.rawCall(response);
  }

  async getDomainVisitorBreakdownRaw(
    username: string,
    domain: string,
    dimension: string,
    query = ''
  ): Promise<{ status: number; body: any }> {
    const suffix = query === '' ? '' : `?${query}`;
    const response = await this.api.get(
      `projects/${username}/domains/${encodeURIComponent(domain)}/visitors/${dimension}${suffix}`
    );
    return this.rawCall(response);
  }
}
