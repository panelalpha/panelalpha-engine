import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { randomEmail, randomPassword, randomUsername } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { Timeouts } from '@/config/timeouts';
import { assertDeploymentWarnings, assertMissingEntry, healthFromRaw } from '@/helpers/app-health';
import { waitForDeploy } from '@/helpers/deploy-helpers';

test.describe('app health and SSO on a deployed app', () => {
  test.setTimeout(Timeouts.deploy);

  test('app health reports whether the deployed app answers', async ({ api, userFactory }) => {
    const user = await userFactory.createDeployedUser();
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const health = await api.getAppHealthRaw(user.username);
    test.skip(
      [403, 404, 422].includes(health.status),
      'App health is not available for this deployed project.'
    );
    expect(health.status).toBe(200);
    const report = healthFromRaw(health.body);
    expect(typeof report.serving).toBe('string');

    const shown = await api.getUser(user.username);
    assertDeploymentWarnings(
      shown.data.details.deployment_status,
      shown.data.details.deployment_warnings
    );

    const stop = await api.runProjectContainerActionRaw(user.username, 'stop');
    expectOneOf(stop.status, [200, 500]);
    try {
      const after = await api.getAppHealthRaw(user.username);
      if (after.status === 200) {
        const stopped = healthFromRaw(after.body);
        expect(stopped.healthy === true).toBe(false);
        if (stopped.healthy === false) {
          expect(stopped.serving).toBe('unknown');
          expect(stopped.checks).toEqual([]);
        }
      } else {
        expect(after.status).not.toBe(200);
      }
    } finally {
      await api.runProjectContainerActionRaw(user.username, 'up');
    }
  });

  test('a static site that lost its front page is missing_entry', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    await api.createDirectory(user.username, '/site', true);
    await api.putFileContents(
      user.username,
      '/site/index.html',
      '<!doctype html><title>home</title>home\n'
    );
    await api.putFileContents(
      user.username,
      '/site/about.html',
      '<!doctype html><title>about</title>about\n'
    );
    await api.zipFiles(user.username, '/project/app.zip', '/site', true);
    const started = await api.deployArchiveRaw(user.username, { zip_path: '/project/app.zip' });
    expectOneOf(started.status, [200, 201]);
    await waitForDeploy(api, user.username);

    // A deploy never yields a static site without an entry: detection serves the first page it
    // finds when nothing is called index (HtmlSite::first). The check exists for a front page
    // that disappears afterwards, and the health endpoint probes the running site every time.
    await api.removeFile(user.username, '/project/index.html');

    const health = await api.getAppHealthRaw(user.username);
    test.skip(
      [403, 404, 422].includes(health.status),
      'App health is not available for this deployed project.'
    );
    expect(health.status).toBe(200);
    assertMissingEntry(healthFromRaw(health.body));
  });

  test('a PHP site whose front page is a 403 is missing_entry', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    await api.createDirectory(user.username, '/site/src', true);
    await api.putFileContents(user.username, '/site/src/Thing.php', '<?php echo 1;\n');
    await api.zipFiles(user.username, '/project/app.zip', '/site', true);
    const started = await api.deployArchiveRaw(user.username, { zip_path: '/project/app.zip' });
    expectOneOf(started.status, [200, 201]);
    const log = await waitForDeploy(api, user.username);
    test.skip(
      log.status === 'failed',
      'This engine could not deploy a PHP-plain archive (needed for the 403 case).'
    );

    const health = await api.getAppHealthRaw(user.username);
    test.skip(
      [403, 404, 422].includes(health.status),
      'App health is not available for this deployed project.'
    );
    expect(health.status).toBe(200);
    const report = healthFromRaw(health.body);
    assertMissingEntry(report);
    const failed = report.checks.find(
      (check) => check.id === 'entry-served' && check.status === 'fail'
    );
    expect(failed?.detail ?? '').toMatch(/index\.php|403|Forbidden|front page/i);

    const shown = await api.getUser(user.username);
    expect(shown.data.details.deployment_status).toBe('partial');
    assertDeploymentWarnings('partial', shown.data.details.deployment_warnings);
  });

  test('app info, roles and users work when the app exposes them', async ({
    api,
    setupDindUser,
    anonymousRequest,
  }) => {
    const info = await api.getAppInfoRaw(setupDindUser.username);
    test.skip(info.status !== 200, 'This deployed app does not expose app-user APIs.');

    const roles = await api.getAppRolesRaw(setupDindUser.username);
    expect(roles.status).toBe(200);
    const roleList = (roles.body as { data: string[] }).data;
    test.skip(!Array.isArray(roleList) || roleList.length === 0, 'The app reports no roles.');

    const login = randomUsername(10);
    const createdUser = await api.createAppUserRaw(setupDindUser.username, {
      login,
      email: randomEmail(),
      password: randomPassword(12),
      role: roleList[0],
    });
    test.skip(createdUser.status !== 201, 'Could not create an in-app user.');

    const userId = String((createdUser.body as { data: { id: string | number } }).data.id);
    try {
      const sso = await api.createAppUserSso(setupDindUser.username, userId);
      expect(sso.url.length).toBeGreaterThan(0);

      const token = new URL(sso.url).searchParams.get('token');
      skipUnless(token, 'SSO URL did not include a token query parameter.');

      const exchanged = await anonymousRequest.get(
        `projects/${setupDindUser.username}/app/sso-token?token=${encodeURIComponent(token)}`
      );
      expectOneOf(exchanged.status(), [200, 302, 303, 307, 308]);

      const tampered = await anonymousRequest.get(
        `projects/${setupDindUser.username}/app/sso-token?token=not-a-real-token`
      );
      expectOneOf(tampered.status(), [400, 401, 403, 404, 422]);
    } finally {
      await api.deleteAppUser(setupDindUser.username, userId).catch(() => undefined);
    }
  });

  test('an already-installed app skips a second install', async ({ api, setupDindUser }) => {
    const info = await api.getAppInfoRaw(setupDindUser.username);
    test.skip(info.status !== 200, 'No app info — install is not applicable.');
    const install = await api.installAppRaw(setupDindUser.username, {
      url: `https://${setupDindUser.domain}`,
      title: 'Already installed',
      admin_user: 'admin',
      admin_email: 'admin@example.test',
      admin_password: randomPassword(12),
    });
    expectOneOf(install.status, [204, 409, 422]);
  });
});
