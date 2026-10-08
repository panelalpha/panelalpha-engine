import { faker } from '@faker-js/faker';
import { type EngineApi } from '@/clients/engine-api';
import { type MySqlDatabase, type MySqlUser } from '@/types';
import { getSettings } from '@/config/settings';
import { registerSecret } from '@/helpers/secret-registry';

export interface MySqlCredentials {
  database: string;
  username: string;
  password: string;
  host: string;
}

export class MySqlFactory {
  private settings = getSettings();

  constructor(private api: EngineApi) {}

  async createDatabase(username: string, dbName?: string): Promise<string> {
    const name = dbName ?? `db_${faker.string.alphanumeric(8).toLowerCase()}`;
    await this.api.createMySqlDatabase(username, name);
    return `${username}_${name}`;
  }

  async createMySqlUser(
    username: string,
    mysqlUser?: string
  ): Promise<{ name: string; password: string }> {
    const name = mysqlUser ?? faker.string.alphanumeric(8).toLowerCase();
    const password = this.generatePassword();

    await this.api.createMySqlUser(username, name, password);

    return {
      name: `${username}_${name}`,
      password,
    };
  }

  async createFullSetup(username: string): Promise<MySqlCredentials> {
    const dbName = `db_${faker.string.alphanumeric(6).toLowerCase()}`;
    const mysqlUser = faker.string.alphanumeric(8).toLowerCase();
    const password = this.generatePassword();

    await this.api.createMySqlDatabase(username, dbName);
    const database = `${username}_${dbName}`;

    await this.api.createMySqlUser(username, mysqlUser, password);
    const mysqlUsername = `${username}_${mysqlUser}`;

    await this.api.grantPrivileges(
      username,
      mysqlUsername,
      database,
      this.settings.mysql.defaultPrivileges
    );

    return {
      database,
      username: mysqlUsername,
      password,
      host: this.settings.mysql.host,
    };
  }

  async deleteDatabase(username: string, dbName: string): Promise<void> {
    // Cleanup helper: the DB may already be gone (the test deleted it on
    // purpose). 404 is success; anything else is worth a note.
    const response = await this.api.delete(`projects/${username}/mysql/databases/${dbName}`);
    const status = response.status();
    if (status !== 200 && status !== 204 && status !== 404) {
      const body = await response.text().catch(() => '<unreadable body>');
      console.warn(`Failed to delete database ${dbName}: HTTP ${status} ${body}`);
    }
  }

  async deleteMySqlUser(username: string, mysqlUser: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/mysql/users/${mysqlUser}`);
    const status = response.status();
    if (status !== 200 && status !== 204 && status !== 404) {
      const body = await response.text().catch(() => '<unreadable body>');
      console.warn(`Failed to delete MySQL user ${mysqlUser}: HTTP ${status} ${body}`);
    }
  }

  async getDatabases(username: string): Promise<MySqlDatabase[]> {
    const response = await this.api.listMySqlDatabases(username);
    return response.data;
  }

  async getMySqlUsers(username: string): Promise<MySqlUser[]> {
    const response = await this.api.listMySqlUsers(username);
    return response.data;
  }

  private generatePassword(): string {
    const password = faker.internet.password({
      length: 16,
      memorable: false,
      pattern: /[A-Za-z0-9!@#$%^&*]/,
    });
    registerSecret(password);
    return password;
  }
}
