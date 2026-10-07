/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type CreateUserRequest,
  type TaskSnapshot,
  type UpdateUserRequest,
  type User,
  type UserUsage,
} from '@/types';
import { type APIResponse, type TestInfo, test } from '@playwright/test';
import { Timeouts } from '@/config/timeouts';
import { delay } from '@/helpers/retry';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import { type ApiTransport } from '../api-transport';
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
    await this.assertOk(await deleteProject(this.api, username));
  }

  /**
   * Deletes a project in cleanup, where it may never have been created: a 404
   * is fine. Throws when the project is still there afterwards, so a cleanup
   * that did not happen is not silent.
   */
  async deleteUserSafe(username: string): Promise<number> {
    const response = await deleteProject(this.api, username);
    const status = response.status();
    if (response.ok() || status === 404) {
      return status;
    }
    if ((await this.api.get(`projects/${username}`)).status() === 404) {
      return status;
    }
    const body = await response.text().catch(() => '<unreadable body>');
    throw new Error(
      `Project ${username} is still on the engine: DELETE answered ${status} ${body}`
    );
  }

  /**
   * POST /projects/{username}/rebuild answers 202 with a task. Polls it until
   * it is terminal and throws unless it completed.
   */
  async rebuildUser(username: string, data: Record<string, unknown> = {}): Promise<TaskSnapshot> {
    const response = await this.api.post(`projects/${username}/rebuild`, { data });
    await this.assertStatus(response, 202);
    return this.followDeployTask(await response.json(), `POST /projects/${username}/rebuild`);
  }

  async rebuildUserRaw(
    username: string,
    data: Record<string, unknown> = {}
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`projects/${username}/rebuild`, { data });
    return this.rawCall(response);
  }

  /** Follows the task a 202 deploy answer named until it is terminal; throws unless it completed. */
  async followDeployTask(body: unknown, what: string): Promise<TaskSnapshot> {
    const taskId = taskIdFromBody(body);
    if (taskId === undefined) {
      throw new Error(`${what} returned 202 without a task id`);
    }
    const task = await waitForTask(
      {
        getTaskRaw: async (id) => this.rawCall(await this.api.get(`tasks/${id}`)),
      },
      taskId,
      { timeout: Timeouts.deploy }
    );
    if (task.status !== 'completed') {
      throw new Error(
        `${what} task ${taskId} ended ${task.status}: ${JSON.stringify(task.details)}`
      );
    }
    return task;
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

  /** 202 with a task, followed until it completed; see rebuildUser(). */
  async deployArchive(
    username: string,
    data: { zip_path: string; env_vars?: Record<string, string | null> }
  ): Promise<TaskSnapshot> {
    const response = await this.api.post(`projects/${username}/deploy-archive`, { data });
    await this.assertStatus(response, 202);
    return this.followDeployTask(
      await response.json(),
      `POST /projects/${username}/deploy-archive`
    );
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

/**
 * DELETE /projects/{username}, sent again while the engine answers 409 because
 * a job works on the project. The task the refusal names is cancelled, once;
 * a task still stopping, or a deploy or push without one, is waited out.
 */
async function deleteProject(api: ApiTransport, username: string): Promise<APIResponse> {
  let deadline: number | undefined;
  const cancelled = new Set<string>();
  for (;;) {
    const response = await api.delete(`projects/${username}`);
    if (response.status() !== 409 || (deadline !== undefined && Date.now() >= deadline)) {
      return response;
    }
    if (deadline === undefined) {
      deadline = Date.now() + Timeouts.projectDelete;
      makeRoomInTheTest(Timeouts.projectDelete + 15_000);
    }
    const { message } = (await response.json().catch(() => ({}))) as { message?: unknown };
    const task =
      typeof message === 'string' ? /\/tasks\/(\d+)\/cancel/.exec(message)?.[1] : undefined;
    if (task !== undefined && !cancelled.has(task)) {
      cancelled.add(task);
      await api.post(`tasks/${task}/cancel`);
    } else {
      await delay(2_000);
    }
  }
}

/**
 * Lengthens the running test by the retry budget, so a delete that never frees
 * up fails with its own message, not as the test's timeout. Outside a test
 * there is nothing to lengthen.
 */
function makeRoomInTheTest(ms: number): void {
  let info: TestInfo;
  try {
    info = test.info();
  } catch {
    return;
  }
  if (info.timeout > 0) {
    info.setTimeout(info.timeout + ms);
  }
}
