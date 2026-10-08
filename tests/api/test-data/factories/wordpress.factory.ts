import { faker } from '@faker-js/faker';
import { type EngineApi } from '@/clients/engine-api';
import { type UserCredentials, type WordPressInstallation } from '@/types';
import { randomEmail } from '@/helpers/random';
import { registerSecret } from '@/helpers/secret-registry';

export class WordPressFactory {
  constructor(private api: EngineApi) {}

  async installWordPress(user: UserCredentials): Promise<WordPressInstallation> {
    const config = this.generateConfig(user);

    await this.downloadCore(user.username, config.path);

    await this.createConfig(user, config.path);

    await this.runInstall(user.username, config);

    return {
      url: config.url,
      wpPath: config.path,
      site_name: config.siteName,
      admin_username: config.adminUsername,
      admin_password: config.adminPassword,
      admin_email: config.adminEmail,
    };
  }

  async runWpCliCommand(
    username: string,
    args: string[]
  ): Promise<{ exit_code: number; stdout: string; stderr: string }> {
    return this.api.executeWpCliCommand(username, args);
  }

  async installPlugin(username: string, wpPath: string, pluginSlug: string): Promise<void> {
    await this.runWpCliCommand(username, [
      'plugin',
      'install',
      pluginSlug,
      `--path=${wpPath}`,
      '--activate',
    ]);
  }

  async installTheme(username: string, wpPath: string, themeSlug: string): Promise<void> {
    await this.runWpCliCommand(username, [
      'theme',
      'install',
      themeSlug,
      `--path=${wpPath}`,
      '--activate',
    ]);
  }

  async createPost(
    username: string,
    wpPath: string,
    title: string,
    content: string
  ): Promise<number> {
    const result = await this.runWpCliCommand(username, [
      'post',
      'create',
      `--post_title=${title}`,
      `--post_content=${content}`,
      '--post_status=publish',
      `--path=${wpPath}`,
      '--porcelain',
    ]);
    return parseInt(result.stdout.trim(), 10);
  }

  async createWpUser(
    username: string,
    wpPath: string,
    wpUsername: string,
    email: string,
    role = 'subscriber'
  ): Promise<void> {
    const password = faker.internet.password({ length: 12 });
    registerSecret(password);
    await this.runWpCliCommand(username, [
      'user',
      'create',
      wpUsername,
      email,
      `--role=${role}`,
      `--user_pass=${password}`,
      `--path=${wpPath}`,
    ]);
  }

  async getVersion(username: string, wpPath: string): Promise<string> {
    const result = await this.runWpCliCommand(username, ['core', 'version', `--path=${wpPath}`]);
    return result.stdout.trim();
  }

  private generateConfig(user: UserCredentials) {
    const adminPassword = faker.internet.password({ length: 16 });
    registerSecret(adminPassword);
    return {
      siteName: faker.company.name(),
      adminUsername: 'admin',
      adminPassword,
      adminEmail: randomEmail(),
      url: `https://${user.domain}`,
      path: `/home/${user.username}/${user.domain}/public_html`,
    };
  }

  private async downloadCore(username: string, path: string): Promise<void> {
    const result = await this.runWpCliCommand(username, ['core', 'download', `--path=${path}`]);
    if (result.exit_code !== 0) {
      throw new Error(`Failed to download WordPress core: ${result.stderr}`);
    }
  }

  private async createConfig(user: UserCredentials, path: string): Promise<void> {
    registerSecret(user.mysqlPassword);
    const result = await this.runWpCliCommand(user.username, [
      'config',
      'create',
      `--path=${path}`,
      `--dbhost=${user.mysqlHost}`,
      `--dbname=${user.database}`,
      `--dbuser=${user.mysqlUsername}`,
      `--dbpass=${user.mysqlPassword}`,
      '--dbprefix=wp_',
      '--locale=en_US',
    ]);
    if (result.exit_code !== 0) {
      throw new Error(`Failed to create wp-config.php: ${result.stderr}`);
    }
  }

  private async runInstall(
    username: string,
    config: ReturnType<typeof this.generateConfig>
  ): Promise<void> {
    const result = await this.runWpCliCommand(username, [
      'core',
      'install',
      `--path=${config.path}`,
      `--url=${config.url}`,
      `--title=${config.siteName}`,
      `--admin_user=${config.adminUsername}`,
      `--admin_password=${config.adminPassword}`,
      `--admin_email=${config.adminEmail}`,
      '--skip-email',
    ]);
    if (result.exit_code !== 0) {
      throw new Error(`Failed to install WordPress: ${result.stderr}`);
    }
  }
}
