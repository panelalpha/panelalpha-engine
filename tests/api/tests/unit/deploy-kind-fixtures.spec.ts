import { expect, test } from '@/fixtures/test-options';
import { skipUnless } from '@/helpers/test-helpers';
import {
  fixtureIds,
  shippedAppIds,
  shippedPlatformIds,
  webDeployFixtures,
} from '@/test-data/static/deploy-kind-fixtures';

/**
 * The inspect/deploy kind catalogue is the live-engine counterpart of
 * ShippedManifestsTest: a new platform or app YAML that is not in the
 * fixtures will fail here instead of shipping undetected.
 */
test.describe('deploy-kind fixture catalogue', () => {
  test('covers every shipped platform', () => {
    const shipped = shippedPlatformIds();
    skipUnless(shipped, 'core/resources/platforms is not readable from this checkout.');
    expect(fixtureIds(), 'add a DeployKindFixture for the new platform').toEqual(
      expect.arrayContaining(shipped)
    );
  });

  test('covers every shipped app recipe', () => {
    const shipped = shippedAppIds();
    skipUnless(shipped, 'core/resources/apps is not readable from this checkout.');
    expect(fixtureIds(), 'add a DeployKindFixture for the new app recipe').toEqual(
      expect.arrayContaining(shipped)
    );
  });

  test('fixture ids are unique', () => {
    const ids = fixtureIds();
    expect(ids).toEqual([...new Set(ids)].sort());
  });

  test('web deploy fixtures are runnable frontends with a unique page marker', () => {
    const web = webDeployFixtures();
    expect(web.map((kind) => kind.id).sort()).toEqual([
      'angular',
      'astro',
      'astro-ssr',
      'bundler-spa',
      'compose',
      'cra',
      'django',
      'dockerfile',
      'dotnet',
      'express',
      'fastify',
      'go',
      'html',
      'java',
      'java-gradle',
      'nestjs',
      'nextjs',
      'nextjs-export',
      'node',
      'nuxt',
      'php',
      'php-plain',
      'python',
      'remix',
      'rust',
      'static',
      'sveltekit',
      'sveltekit-static',
      'tanstack-start',
      'vite',
    ]);
    const markers = web.map((kind) => kind.pageMarker);
    expect(markers.sort()).toEqual([...new Set(markers)].sort());
    for (const kind of web) {
      expect(
        Object.values(kind.files).join('\n'),
        `${kind.id} files must contain ${kind.pageMarker}`
      ).toContain(kind.pageMarker);
    }
  });
});
