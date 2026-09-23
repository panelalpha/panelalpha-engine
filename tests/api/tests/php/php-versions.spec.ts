import { expect, test } from '@/fixtures/test-options';
import {
  HTTP_PROBE_SPACING_MS,
  PHP_VERSION_PROBE,
  RAPID_PROBE_SPACING_MS,
  majorMinor,
  probeRepeatedly,
  waitForServedPhpVersion,
} from '@/helpers/php-helpers';
import { rand } from '@/helpers/random';
import { phpVersionsListSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

/**
 * Switching a domain's PHP version has to change what the webserver actually
 * executes, not just what the API reports. Every test here writes a probe script
 * into the document root, drives the switch, and reads the version back over
 * HTTP.
 *
 * Each test restores the version it found — these run against the shared setup
 * user, and leaving it on a different runtime would change the ground under
 * every spec that follows.
 */
test.describe('PHP version switching', () => {
  test('the available versions are listed', async ({ api }) => {
    const listing = await api.getAvailablePhpVersions();
    validateParsedApiResponse(listing, phpVersionsListSchema);
    expect(listing.data.length).toBeGreaterThan(0);
  });

  test('a phpinfo script can be written to the document root', async ({ api, setupUser }) => {
    const path = `${setupUser.domain}/public_html/phpinfo.php`;
    await api.putFileContents(setupUser.username, path, '<?php phpinfo(); ?>');
    expect(await api.getFileContent(setupUser.username, path)).toContain('phpinfo');
  });

  test(
    'every available version is served after switching to it',
    { tag: ['@slow'] },
    async ({ api, anonymousRequest, settings, setupUser }) => {
      const versions = (await api.getAvailablePhpVersions()).data ?? [];
      test.skip(versions.length < 2, 'The engine offers fewer than two PHP versions.');

      const script = `phpinfo-test-${rand()}.php`;
      const original = (await api.getDomainPhpVersion(setupUser.domain)).data;

      await api.putFileContents(
        setupUser.username,
        `${setupUser.domain}/public_html/${script}`,
        PHP_VERSION_PROBE
      );

      try {
        for (const version of versions.filter((candidate) => candidate !== original)) {
          await test.step(`switch to PHP ${version}`, async () => {
            await api.setDomainPhpVersion(setupUser.domain, version);
            await waitForServedPhpVersion(
              api,
              anonymousRequest,
              setupUser.domain,
              script,
              version,
              settings.timing.phpExecutionDelay + 30_000
            );
          });
        }
      } finally {
        await api.setDomainPhpVersion(setupUser.domain, original);
        await api.removeFile(setupUser.username, `${setupUser.domain}/public_html/${script}`);
      }
    }
  );

  test('the switched version keeps being served across repeated requests', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const versions = (await api.getAvailablePhpVersions()).data ?? [];
    test.skip(versions.length < 2, 'The engine offers fewer than two PHP versions.');

    const script = `test-php-version-${rand()}.php`;
    const original = (await api.getDomainPhpVersion(setupUser.domain)).data;
    const target = versions.find((candidate) => candidate !== original) ?? versions[0];

    await api.putFileContents(
      setupUser.username,
      `${setupUser.domain}/public_html/${script}`,
      PHP_VERSION_PROBE
    );

    try {
      await api.setDomainPhpVersion(setupUser.domain, target);
      await waitForServedPhpVersion(
        api,
        anonymousRequest,
        setupUser.domain,
        script,
        target,
        settings.timing.propagationDelay + 30_000
      );

      const bodies = await probeRepeatedly(
        anonymousRequest,
        setupUser.domain,
        script,
        3,
        HTTP_PROBE_SPACING_MS
      );

      for (const body of bodies) {
        expect(body, 'a follow-up request fell back to the old runtime').toContain(
          majorMinor(target)
        );
      }
    } finally {
      await api.setDomainPhpVersion(setupUser.domain, original);
      await api.removeFile(setupUser.username, `${setupUser.domain}/public_html/${script}`);
    }
  });

  /**
   * Switching several times in quick succession used to leave stale lsphp
   * workers behind, so some requests were still answered by an older binary.
   */
  test('rapid successive switches leave no stale workers', async ({
    api,
    anonymousRequest,
    settings,
    setupUser,
  }) => {
    const versions = (await api.getAvailablePhpVersions()).data ?? [];
    test.skip(versions.length < 2, 'The engine offers fewer than two PHP versions.');

    const script = `rapid-php-test-${rand()}.php`;
    const original = (await api.getDomainPhpVersion(setupUser.domain)).data;
    const sequence = versions.slice(0, 3);
    const last = sequence[sequence.length - 1];

    await api.putFileContents(
      setupUser.username,
      `${setupUser.domain}/public_html/${script}`,
      PHP_VERSION_PROBE
    );

    try {
      for (const version of sequence) {
        await api.setDomainPhpVersion(setupUser.domain, version);
      }

      await waitForServedPhpVersion(
        api,
        anonymousRequest,
        setupUser.domain,
        script,
        last,
        settings.timing.phpExecutionDelay + 30_000
      );

      const bodies = await probeRepeatedly(
        anonymousRequest,
        setupUser.domain,
        script,
        5,
        RAPID_PROBE_SPACING_MS
      );

      for (const [index, body] of bodies.entries()) {
        expect(body, `probe ${index + 1} was answered by a stale worker`).toContain(
          majorMinor(last)
        );
      }
    } finally {
      await api.setDomainPhpVersion(setupUser.domain, original);
      await api.removeFile(setupUser.username, `${setupUser.domain}/public_html/${script}`);
    }
  });
});
