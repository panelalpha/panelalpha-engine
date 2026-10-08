import type { EngineApi } from '@/clients/engine-api';
import { registerSecret } from './secret-registry';

/** Registers the credentials and salts that a wp-config.php defines, and returns its content. */
export function registerWpConfigSecrets(content: string): string {
  const defines = /define\s*\(\s*['"](\w*(?:PASSWORD|KEY|SALT)\w*)['"]\s*,\s*(['"])(.*?)\2\s*\)/g;
  for (const match of content.matchAll(defines)) {
    registerSecret(match[3]);
  }
  return content;
}

/**
 * Points WP_HOME and WP_SITEURL at the www hostname, adding the constants when
 * wp-config.php does not define them.
 *
 * WordPress issues its own canonical redirect from whatever these say, so an
 * apex-to-www vhost redirect only holds if WordPress agrees the www host is
 * canonical — otherwise the two bounce requests back and forth.
 *
 * Returns whether the file was changed.
 */
export async function ensureWwwConfigInWpConfig(
  api: EngineApi,
  username: string,
  wpPath: string,
  wwwDomain: string
): Promise<boolean> {
  const wpConfigPath = `${wpPath}/wp-config.php`;
  const scheme = 'https';

  try {
    const content = registerWpConfigSecrets(await api.getFileContent(username, wpConfigPath));

    const wwwUrl = `${scheme}://${wwwDomain}`;
    const homeRegex = /define\s*\(\s*['"]WP_HOME['"]\s*,\s*['"][^'"]*['"]\s*\)/;
    const siteurlRegex = /define\s*\(\s*['"]WP_SITEURL['"]\s*,\s*['"][^'"]*['"]\s*\)/;

    const homeMatch = homeRegex.exec(content);
    const siteurlMatch = siteurlRegex.exec(content);

    const homeCorrect = homeMatch?.[0]?.includes(wwwUrl) ?? false;
    const siteurlCorrect = siteurlMatch?.[0]?.includes(wwwUrl) ?? false;

    if (homeCorrect && siteurlCorrect) {
      return false;
    }

    let updatedContent = content;

    if (!homeMatch) {
      updatedContent = updatedContent.replace(/(<\?php)/, `$1\ndefine('WP_HOME', '${wwwUrl}');`);
    } else if (!homeCorrect) {
      updatedContent = updatedContent.replace(homeRegex, `define('WP_HOME', '${wwwUrl}')`);
    }

    if (!siteurlMatch) {
      updatedContent = updatedContent.replace(
        /(define\s*\(\s*['"]WP_HOME['"][^)]*\);)/,
        `$1\ndefine('WP_SITEURL', '${wwwUrl}');`
      );
    } else if (!siteurlCorrect) {
      updatedContent = updatedContent.replace(siteurlRegex, `define('WP_SITEURL', '${wwwUrl}')`);
    }

    await api.putFileContents(username, wpConfigPath, updatedContent);
    return true;
  } catch {
    return false;
  }
}
