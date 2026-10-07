import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@/fixtures/test-options';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { waitForCondition } from '@/helpers/retry';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';

const PLUGIN_ZIP = 'ioncube_test.zip';

/**
 * ionCube-encoded plugins are common in commercial WordPress add-ons, and the
 * loader is only present for some PHP builds. This installs a genuinely encoded
 * plugin on PHP 8.1 and checks the site still renders — an absent loader shows
 * up as a fatal error on every page, not as a failed install.
 */
test('an ionCube-encoded plugin runs on PHP 8.1', async ({
  api,
  anonymousRequest,
  settings,
  setupUser,
}) => {
  const reason = await wpCliUnavailableReason(api, setupUser);
  test.skip(Boolean(reason), reason ?? '');

  const localZip = path.join(settings.paths.staticDataDir, PLUGIN_ZIP);
  test.skip(!fs.existsSync(localZip), `${PLUGIN_ZIP} is not in test-data/static.`);

  const versions = (await api.getAvailablePhpVersions()).data ?? [];
  const target = versions.find((version) => /8[._]?1/.test(version));
  test.skip(!target, 'This engine offers no PHP 8.1.');

  const original = (await api.getDomainPhpVersion(setupUser.domain)).data;
  test.skip(!original, `Could not read the current PHP version for ${setupUser.domain}.`);

  // The home root is root-owned; the domain's document root is the user's to write.
  const remoteDir = getDomainBasePath(setupUser.domain);
  const remoteZip = `/home/${setupUser.username}${remoteDir}/${PLUGIN_ZIP}`;

  try {
    if (original !== target) {
      await api.setDomainPhpVersion(setupUser.domain, target!);
      await waitForCondition(
        async () => (await api.getDomainPhpVersion(setupUser.domain)).data === target,
        {
          timeout: settings.timing.propagationDelay + settings.timing.phpExecutionDelay + 5_000,
          interval: 1_000,
          message: `the domain never switched to PHP ${target}`,
        }
      );
    }

    await api.uploadFile(setupUser.username, remoteDir, localZip, { mimeType: 'application/zip' });
    expect((await api.fileExists(setupUser.username, remoteZip)).exists).toBe(true);

    const installed = await api.executeWpCliCommand(setupUser.username, [
      'plugin',
      'install',
      remoteZip,
      '--force',
      '--activate',
      wpPath(setupUser),
    ]);
    expect(installed.exit_code, installed.stderr).toBe(0);

    await test.step('the loader is present in the runtime', async () => {
      const loader = await api.executeWpCliCommand(setupUser.username, [
        'eval',
        'echo function_exists("ioncube_loader_version") ? ioncube_loader_version() : "missing";',
        wpPath(setupUser),
      ]);

      expect(loader.exit_code, loader.stderr).toBe(0);
      expect(loader.stdout.trim(), 'the ionCube loader is not loaded').not.toBe('missing');
    });

    await test.step('the site renders with the encoded plugin active', async () => {
      const marker = process.env.IONCUBE_TEST_MARKER;

      await waitForCondition(
        async () => {
          const response = await anonymousRequest.get(
            `https://${setupUser.domain}/?ioncube_test=1&t=${Date.now()}`,
            { ignoreHTTPSErrors: true, maxRedirects: 5 }
          );
          if (response.status() !== 200) {
            return false;
          }

          const body = await response.text();
          if (/ioncube php loader|critical error|fatal error|parse error/i.test(body)) {
            return false;
          }
          return marker ? body.includes(marker) : true;
        },
        {
          timeout: 30_000,
          interval: 2_000,
          message: 'the site never rendered cleanly with the ionCube plugin active',
        }
      );
    });
  } finally {
    await api.setDomainPhpVersion(setupUser.domain, original).catch(() => undefined);
    // The upload can end up root-owned on some stacks, so removal may fail.
    await api.removeFile(setupUser.username, remoteZip).catch(() => undefined);
  }
});
