/* eslint-disable @typescript-eslint/no-explicit-any */

import {
  type ApiListResponse,
  type ApiResponse,
  type MySqlDatabase,
  type MySqlUser,
} from '@/types';
import { PhpApi } from './php.api';

export class MySqlApi extends PhpApi {
  async listMySqlDatabases(username: string): Promise<ApiListResponse<MySqlDatabase>> {
    const response = await this.api.get(`projects/${username}/mysql/databases`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createMySqlDatabase(username: string, name: string): Promise<ApiResponse<MySqlDatabase>> {
    const response = await this.api.post(`projects/${username}/mysql/databases`, {
      data: { name },
    });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deleteMySqlDatabase(username: string, name: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/mysql/databases/${name}`);
    await this.assertStatus(response, [200, 204]);
  }

  async listMySqlUsers(username: string): Promise<ApiListResponse<MySqlUser>> {
    const response = await this.api.get(`projects/${username}/mysql/users`);
    await this.assertStatus(response, 200);
    return response.json();
  }

  async createMySqlUser(
    username: string,
    name: string,
    password: string
  ): Promise<ApiResponse<MySqlUser>> {
    const response = await this.api.post(`projects/${username}/mysql/users`, {
      data: { name, password },
    });
    await this.assertStatus(response, [200, 201]);
    return response.json();
  }

  async deleteMySqlUser(username: string, name: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/mysql/users/${name}`);
    await this.assertStatus(response, [200, 204, 404]);
  }

  async getMySqlServerInfo(username: string): Promise<ApiResponse<any>> {
    const response = await this.api.get(`projects/${username}/mysql/server-info`);
    await this.assertOk(response);
    return response.json();
  }

  async getMySqlDatabase(username: string, database: string): Promise<ApiResponse<MySqlDatabase>> {
    const response = await this.api.get(`projects/${username}/mysql/databases/${database}`);
    await this.assertOk(response);
    return response.json();
  }

  async getMySqlUser(username: string, mysqlUser: string): Promise<ApiResponse<MySqlUser>> {
    const response = await this.api.get(`projects/${username}/mysql/users/${mysqlUser}`);
    await this.assertOk(response);
    return response.json();
  }

  async renameMySqlUser(
    username: string,
    oldName: string,
    newName: string
  ): Promise<ApiResponse<MySqlUser>> {
    const response = await this.api.put(`projects/${username}/mysql/users/${oldName}/rename`, {
      data: { name: newName },
    });
    await this.assertOk(response);
    return response.json();
  }

  async changeMySqlUserPassword(
    username: string,
    mysqlUser: string,
    password: string
  ): Promise<void> {
    const response = await this.api.put(
      `projects/${username}/mysql/users/${mysqlUser}/change-password`,
      {
        data: { password },
      }
    );
    await this.assertOk(response);
  }

  async getMySqlPrivileges(
    username: string,
    mysqlUser: string,
    database: string
  ): Promise<ApiResponse<any>> {
    const response = await this.api.get(
      `projects/${username}/mysql/privileges/${mysqlUser}/${database}`
    );
    await this.assertStatus(response, [200, 404]);
    if (response.status() === 200) {
      return response.json();
    }
    return { data: undefined } as ApiResponse<any>;
  }

  /**
   * Updates MySQL privileges
   * @param privileges - Array of privileges to grant (will be joined with commas)
   */
  async updateMySqlPrivileges(
    username: string,
    mysqlUser: string,
    database: string,
    privileges: string[]
  ): Promise<void> {
    const privilegesString = privileges.join(',');
    const response = await this.api.put(
      `projects/${username}/mysql/privileges/${mysqlUser}/${database}`,
      {
        data: { privileges: privilegesString },
      }
    );
    await this.assertStatus(response, [200, 204, 404]);
  }

  async deleteMySqlPrivileges(
    username: string,
    mysqlUser: string,
    database: string
  ): Promise<void> {
    const response = await this.api.delete(
      `projects/${username}/mysql/privileges/${mysqlUser}/${database}`
    );
    await this.assertStatus(response, [200, 204, 404]);
  }

  async createPhpMyAdminSsoToken(username: string): Promise<ApiResponse<{ url: string }>> {
    const response = await this.api.post(`projects/${username}/mysql/phpmyadmin-sso-token`);
    await this.assertOk(response);
    return response.json();
  }

  async usePhpMyAdminSsoToken(
    token: string
  ): Promise<ApiResponse<{ username: string; password: string }>> {
    const response = await this.api.put('mysql/phpmyadmin-sso-token', {
      data: { token },
    });
    await this.assertOk(response);
    return response.json();
  }

  /**
   * Grants database privileges (legacy)
   */
  async grantPrivileges(
    username: string,
    mysqlUser: string,
    database: string,
    privileges: string
  ): Promise<void> {
    const response = await this.api.put(
      `projects/${username}/mysql/privileges/${mysqlUser}/${database}`,
      {
        data: { privileges },
      }
    );
    await this.assertOk(response);
  }
}
