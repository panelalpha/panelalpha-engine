import { faker } from '@faker-js/faker';
import * as fs from 'fs';
import { type EngineApi } from '@/clients/engine-api';
import { type FtpAccount, type SftpAccount } from '@/types';
import { getSettings } from '@/config/settings';
import { registerSecret } from '@/helpers/secret-registry';

export class FtpFactory {
  private settings = getSettings();

  constructor(private api: EngineApi) {}

  async createFtpAccount(
    username: string,
    domain: string,
    ftpUsername?: string
  ): Promise<FtpAccount & { password: string }> {
    const ftpUser = ftpUsername ?? `ftp${faker.string.alphanumeric(6).toLowerCase()}`;
    const password = this.generatePassword();

    await this.api.createFtpAccount(username, {
      user: ftpUser,
      domain,
      password,
    });

    return {
      id: 0,
      user_id: 0,
      user: `${ftpUser}@${domain}`,
      directory: '/',
      details: null,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      disk_usage_mb: null,
      password,
    };
  }

  async createMultipleFtpAccounts(
    username: string,
    domain: string,
    count: number
  ): Promise<(FtpAccount & { password: string })[]> {
    const accounts: (FtpAccount & { password: string })[] = [];

    for (let i = 0; i < count; i++) {
      const account = await this.createFtpAccount(username, domain);
      accounts.push(account);
    }

    return accounts;
  }

  async deleteFtpAccount(username: string, ftpUsername: string): Promise<void> {
    // Cleanup helper: the account may already be gone (the test deleted it on
    // purpose). 404 is success; anything else is worth a note.
    const response = await this.api.delete(`projects/${username}/ftp-accounts/${ftpUsername}`);
    const status = response.status();
    if (status !== 200 && status !== 204 && status !== 404) {
      const body = await response.text().catch(() => '<unreadable body>');
      console.warn(`Failed to delete FTP account ${ftpUsername}: HTTP ${status} ${body}`);
    }
  }

  async createSftpAccountWithPassword(
    username: string,
    sftpUsername?: string
  ): Promise<SftpAccount & { password: string }> {
    const sftpUser = this.ensureSftpPrefix(
      sftpUsername ?? `sftp${faker.string.alphanumeric(6).toLowerCase()}`,
      username
    );
    const password = this.generatePassword();

    await this.api.createSftpAccount(username, {
      username: sftpUser,
      auth_method: 'password',
      password,
    });

    return {
      username: sftpUser,
      directory: '',
      auth_type: 'password',
      created_at: new Date().toISOString(),
      password,
    };
  }

  async createSftpAccountWithKey(
    username: string,
    sftpUsername?: string
  ): Promise<SftpAccount & { publicKey: string }> {
    const sftpUser = this.ensureSftpPrefix(
      sftpUsername ?? `sftp${faker.string.alphanumeric(6).toLowerCase()}`,
      username
    );
    const publicKey = this.getSshPublicKey();

    await this.api.createSftpAccount(username, {
      username: sftpUser,
      auth_method: 'public_key',
      public_key: publicKey,
    });

    return {
      username: sftpUser,
      directory: '',
      auth_type: 'key',
      created_at: new Date().toISOString(),
      publicKey,
    };
  }

  async deleteSftpAccount(username: string, sftpUsername: string): Promise<void> {
    const response = await this.api.delete(`projects/${username}/sftp-accounts/${sftpUsername}`);
    const status = response.status();
    if (status !== 200 && status !== 204 && status !== 404) {
      const body = await response.text().catch(() => '<unreadable body>');
      console.warn(`Failed to delete SFTP account ${sftpUsername}: HTTP ${status} ${body}`);
    }
  }

  private generatePassword(): string {
    const password = faker.internet.password({
      length: 16,
      memorable: false,
      pattern: /[a-zA-Z0-9!@#$%]/,
    });
    registerSecret(password);
    return password;
  }

  private getSshPublicKey(): string {
    const keyPath = this.settings.getSshPublicKeyPath();

    if (!fs.existsSync(keyPath)) {
      throw new Error(
        `SSH public key file not found at ${keyPath}. ` + 'Please run SSH key generation first.'
      );
    }

    const publicKey = fs.readFileSync(keyPath, 'utf8').trim();
    const validTypes = ['ssh-rsa', 'ssh-ed25519', 'ecdsa-sha2-'];

    if (!validTypes.some((type) => publicKey.startsWith(type))) {
      throw new Error(
        'Invalid SSH public key format. Expected format: ssh-rsa, ssh-ed25519, or ecdsa-sha2-*'
      );
    }

    return publicKey;
  }

  private ensureSftpPrefix(username: string, systemUsername: string): string {
    const prefix = `${systemUsername}_`;
    return username.startsWith(prefix) ? username : `${prefix}${username}`;
  }
}
