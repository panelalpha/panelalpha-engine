import type { EngineApi } from '@/clients/engine-api';
import { randomEmail, uniqueId } from '@/helpers/random';
import { registerSecret } from '@/helpers/secret-registry';
import type { UserCredentials } from '@/types';

export interface WordPressSite {
  url: string;
  title: string;
  adminUser: string;
  adminPassword: string;
}

/**
 * Installs WordPress in the project's main document root through the typed endpoints:
 * core download, wp-config from the project's own database, then the install.
 */
export async function installWordPressSite(
  api: EngineApi,
  user: UserCredentials
): Promise<WordPressSite> {
  const site: WordPressSite = {
    url: `https://${user.domain}`,
    title: uniqueId('Site '),
    adminUser: 'admin',
    adminPassword: uniqueId('Adm-'),
  };
  registerSecret(site.adminPassword);
  registerSecret(user.mysqlPassword);
  await api.wp(user.username, 'POST', 'core/download', {});
  await api.wp(user.username, 'POST', 'config', {
    dbname: user.database,
    dbuser: user.mysqlUsername,
    dbpass: user.mysqlPassword,
    dbhost: user.mysqlHost,
    dbprefix: 'wp_',
  });
  await api.wp(user.username, 'POST', 'core/install', {
    url: site.url,
    title: site.title,
    admin_user: site.adminUser,
    admin_email: randomEmail(),
    admin_password: site.adminPassword,
    skip_email: true,
  });
  return site;
}
