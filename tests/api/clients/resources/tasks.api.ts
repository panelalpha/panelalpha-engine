/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiResponse,
  type TaskLogPage,
  type TaskSnapshot,
  type TaskSummaryPage,
} from '@/types';
import { EngineApiBase } from '../engine-api-base';

function taskLogQuery(params?: {
  since?: string | number;
  after_id?: number;
  limit?: number;
}): string {
  const query = new URLSearchParams();
  if (params?.since !== undefined) {
    query.set('since', String(params.since));
  }
  if (params?.after_id !== undefined) {
    query.set('after_id', String(params.after_id));
  }
  if (params?.limit !== undefined) {
    query.set('limit', String(params.limit));
  }
  const suffix = query.toString();
  return suffix.length > 0 ? `?${suffix}` : '';
}

export interface TaskListQuery {
  username?: string;
  status?: string;
  action?: string;
  since?: string;
  scope?: 'mine' | 'all';
  per_page?: number;
  page?: number;
}

function taskListQuery(params: TaskListQuery): string {
  const query = new URLSearchParams();
  if (params.username !== undefined) {
    query.set('username', params.username);
  }
  if (params.status !== undefined) {
    query.set('status', params.status);
  }
  if (params.action !== undefined) {
    query.set('action', params.action);
  }
  if (params.since !== undefined) {
    query.set('since', params.since);
  }
  if (params.scope !== undefined) {
    query.set('scope', params.scope);
  }
  if (params.per_page !== undefined) {
    query.set('per_page', String(params.per_page));
  }
  if (params.page !== undefined) {
    query.set('page', String(params.page));
  }
  const suffix = query.toString();
  return suffix.length > 0 ? `?${suffix}` : '';
}

export class TasksApi extends EngineApiBase {
  async listTasks(params: TaskListQuery = {}): Promise<TaskSummaryPage> {
    const response = await this.api.get(`tasks${taskListQuery(params)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listTasksRaw(params: TaskListQuery = {}): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`tasks${taskListQuery(params)}`);
    return this.rawCall(response);
  }

  async getTask(id: number, afterId?: number): Promise<ApiResponse<TaskSnapshot>> {
    const suffix = afterId === undefined ? '' : `?after_id=${afterId}`;
    const response = await this.api.get(`tasks/${id}${suffix}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async getTaskRaw(id: number, afterId?: number): Promise<{ status: number; body: any }> {
    const suffix = afterId === undefined ? '' : `?after_id=${afterId}`;
    const response = await this.api.get(`tasks/${id}${suffix}`);
    return this.rawCall(response);
  }

  async listTaskLogs(
    id: number,
    params?: { since?: string | number; after_id?: number }
  ): Promise<TaskLogPage> {
    const response = await this.api.get(`tasks/${id}/logs${taskLogQuery(params)}`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async listTaskLogsRaw(
    id: number,
    params?: { since?: string | number; after_id?: number; limit?: number }
  ): Promise<{ status: number; body: any }> {
    const response = await this.api.get(`tasks/${id}/logs${taskLogQuery(params)}`);
    return this.rawCall(response);
  }

  async streamTaskLogsRaw(
    id: number,
    params?: { since?: string | number; after_id?: number }
  ): Promise<{ status: number; body: string }> {
    const response = await this.api.get(`tasks/${id}/logs/stream${taskLogQuery(params)}`);
    return { status: response.status(), body: await response.text() };
  }

  async cancelTask(id: number): Promise<ApiResponse<{ cancelled: boolean }>> {
    const response = await this.api.post(`tasks/${id}/cancel`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async cancelTaskRaw(id: number): Promise<{ status: number; body: any }> {
    const response = await this.api.post(`tasks/${id}/cancel`);
    return this.rawCall(response);
  }
}
