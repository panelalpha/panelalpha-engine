import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { expectStatus } from '@/helpers/expect-status';
import { skipUnless, skipUnlessResolvableSite } from '@/helpers/test-helpers';
import { fetchSite } from '@/helpers/webserver-helpers';
import {
  FIRST_TAG,
  IMAGE_ONLY_COMPOSE,
  IMAGE_TAG_VAR,
  NEXT_TAG,
  PAGE_TITLE,
  nginxVersions,
} from '@/test-data/static/image-only-compose';

/**
 * An application shipped as a published image deploys from a source that is
 * only a compose file: no build step and no git credentials. Changing the
 * image tag in the project's env vars, applied with a recreate, pulls the new
 * image and keeps the named volume.
 */
test.describe('image-only compose deploy', () => {
  test(
    'deploys without a build and a tag change keeps the volume',
    { tag: ['@slow'] },
    async ({ api, anonymousRequest, userFactory }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const user = await userFactory.createDindUser({ tunnel: 'none' });
      skipUnless(user, 'DinD is not available on this engine.');
      skipUnlessResolvableSite(user.domain);
      const name = user.username;
      const site = `https://${user.domain}/`;

      const page = async (): Promise<string> => {
        const response = await fetchSite(anonymousRequest, site);
        return response.status() === 200 ? await response.text() : `HTTP ${response.status()}`;
      };

      await test.step('deploy the compose file with the first tag', async () => {
        await api.setEnvVars(name, { env_vars: { [IMAGE_TAG_VAR]: FIRST_TAG }, apply: 'none' });
        await api.createDirectory(name, '/project/source', true);
        await api.putFileContents(name, '/project/source/docker-compose.yml', IMAGE_ONLY_COMPOSE);
        await api.zipFiles(name, '/project/source.zip', '/project/source', true);

        const started = await api.deployArchiveRaw(name, { zip_path: '/project/source.zip' });
        await expectStatus(started, 202, JSON.stringify(started.body));
        await api.followDeployTask(started.body, 'POST deploy-archive');
      });

      await test.step('nothing was built', async () => {
        const log = await api.getDeployLog(name, { buildTimings: true });
        const phases = (log.data.timings?.phases ?? []).map((phase) => phase.name);
        expect(phases, 'no build phase').not.toContain('build');
        expect(log.data.timings?.build?.step_count ?? 0, 'no BuildKit step').toBe(0);
      });

      const first =
        await test.step('the first image serves, and the volume records it', async () => {
          let body = '';
          await expect
            .poll(async () => (body = await page()), {
              timeout: 60_000,
              intervals: [2_000],
              message: `${site} never served the ${PAGE_TITLE} page`,
            })
            .toContain(`<title>${PAGE_TITLE}</title>`);
          const versions = nginxVersions(body);
          expect(versions.first, 'the volume recorded the first image').toMatch(/^1\.27\./);
          expect(versions.now, 'the first image runs').toBe(versions.first);

          return versions.first;
        });

      await test.step('a tag change with apply: recreate runs the new image', async () => {
        const started = await api.setEnvVarsRaw(name, {
          env_vars: { [IMAGE_TAG_VAR]: NEXT_TAG },
          apply: 'recreate',
        });
        await expectStatus(started, 202, JSON.stringify(started.body));
        await api.followDeployTask(started.body, 'PUT env-vars');

        await expect
          .poll(async () => nginxVersions(await page()).now ?? '', {
            timeout: 60_000,
            intervals: [2_000],
            message: `${site} never served the ${NEXT_TAG} image`,
          })
          .toMatch(/^1\.28\./);
      });

      await test.step('the volume survived the new image', async () => {
        expect(nginxVersions(await page()).first, 'the first image is still on record').toBe(first);
      });
    }
  );
});
