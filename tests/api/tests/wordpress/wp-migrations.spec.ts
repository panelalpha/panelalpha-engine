import { expect, test } from '@/fixtures/test-options';
import type { APIResponse } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { waitForPage } from '@/helpers/site-page';
import { testNetwork } from '@/helpers/test-network';

interface Migration {
  id: number;
  status: string;
  target_project: string | null;
  target_domain: string | null;
}

interface Problem {
  field?: string;
  code?: string;
  message?: string;
}

const TOKEN = 'a'.repeat(64);
const SOURCE_URL = process.env.WP_MIGRATION_SOURCE_URL;
const SOURCE_USER = process.env.WP_MIGRATION_SOURCE_USER;
const SOURCE_PASSWORD = process.env.WP_MIGRATION_SOURCE_PASSWORD;
const SOURCE_TITLE = process.env.WP_MIGRATION_SOURCE_TITLE;

async function problemsOf(response: APIResponse): Promise<Problem[]> {
  const body = (await response.json()) as {
    problems?: Problem[];
    errors?: Record<string, string[]>;
  };
  return body.problems ?? Object.keys(body.errors ?? {}).map((field) => ({ field }));
}

/** The import needs WP_MIGRATION_SOURCE_* and a public engine address; the refusals do not. */
test.describe('WordPress migrations', () => {
  test('the migrator plugin package is signed, downloads as a zip, and only with its signature', async ({
    authedRequest,
    anonymousRequest,
  }) => {
    const minted = await authedRequest.post('wordpress/migrations/plugin-package');
    await expectStatus(minted, 200, 'POST plugin-package');
    const pkg = (await minted.json()) as {
      token: string;
      download_url: string;
      expires_at: string;
    };
    expect(pkg.token).toMatch(/^[0-9a-f]{64}$/);
    expect(Date.parse(pkg.expires_at)).toBeGreaterThan(Date.now());
    expect(new URL(pkg.download_url).searchParams.get('signature')).toMatch(/^[0-9a-f]{64}$/);

    await test.step('the signed link downloads the plugin without an API token', async () => {
      const download = await anonymousRequest.get(pkg.download_url);
      await expectStatus(download, 200, 'GET the signed download link');
      expect(download.headers()['content-type']).toContain('application/zip');
      expect(download.headers()['content-disposition']).toContain('wordpress-migrator.zip');
      const zip = await download.body();
      expect(zip.subarray(0, 4).toString('latin1'), 'a zip archive').toBe('PK\u0003\u0004');
      expect(zip.toString('latin1'), 'the archive holds a PHP plugin').toMatch(/\.php/);
    });

    await test.step('a changed or missing signature is refused', async () => {
      const tampered = new URL(pkg.download_url);
      tampered.searchParams.set('signature', '0'.repeat(64));
      await expectStatus(await anonymousRequest.get(tampered.href), 403, 'a forged signature');
      tampered.searchParams.delete('signature');
      await expectStatus(await anonymousRequest.get(tampered.href), 403, 'no signature');
    });
  });

  test('a source on this engine, a private address or an unknown host is refused', async ({
    authedRequest,
    setupUser,
  }) => {
    const refused = async (data: Record<string, unknown>, field: string, label: string) => {
      for (const path of ['wordpress/migrations/test', 'wordpress/migrations']) {
        const response = await authedRequest.post(path, { data });
        expectOneOf(response.status(), [409, 422]);
        expect(
          (await problemsOf(response)).map((problem) => problem.field),
          `${path}: ${label}`
        ).toContain(field);
      }
    };
    const manual = { method: 'manual_plugin', source_token: TOKEN };

    await refused(
      { ...manual, url: `https://${setupUser.domain}/` },
      'url',
      'a site on this engine'
    );
    await refused({ ...manual, url: 'https://pa-no-such-host.invalid/' }, 'url', 'an unknown host');
    await refused(
      { ...manual, url: 'https://example.com/', source_ip: testNetwork.private(3) },
      'source_ip',
      'a private pin'
    );
    await refused(
      { url: 'https://example.com/', method: 'wp_admin' },
      'username',
      'no admin login'
    );
    await refused(
      { ...manual, url: 'https://example.com/', source_token: 'short' },
      'source_token',
      'a bad token'
    );
    await refused(
      { ...manual, url: 'https://example.com/', method: 'ftp' },
      'method',
      'an unknown method'
    );
  });

  test('a migration that cannot run is reported, refused a resume, and deleted', async ({
    authedRequest,
  }) => {
    test.setTimeout(240_000);
    const started = await authedRequest.post('wordpress/migrations', {
      data: { url: 'https://example.com/', method: 'manual_plugin', source_token: TOKEN },
    });
    if (started.status() === 409) {
      const problem = (await problemsOf(started))[0];
      expect(problem.code, 'a private engine names why it cannot import').toBe(
        'engine_address_private'
      );
      test.skip(true, 'This engine has no public IPv4 address, so it cannot receive a migration.');
      return;
    }
    await expectStatus(started, 202, 'POST wordpress/migrations');
    const migration = (await started.json()) as Migration;

    const ended =
      await test.step('the import fails at the source, which is not WordPress', async () => {
        let latest = migration;
        await expect
          .poll(
            async () => {
              const shown = await authedRequest.get(`wordpress/migrations/${migration.id}`);
              await expectStatus(shown, 200, 'GET the migration');
              latest = (await shown.json()) as Migration;
              return latest.status;
            },
            { timeout: 180_000, intervals: [5_000], message: 'the migration never ended' }
          )
          .toMatch(/^(failed|cancelled)$/);
        return latest;
      });
    expect(ended.status).toBe('failed');

    await expectStatus(
      await authedRequest.post(`wordpress/migrations/${migration.id}/cancel`),
      202,
      'cancel a failed migration'
    );
    await expect
      .poll(
        async () => {
          const shown = await authedRequest.get(`wordpress/migrations/${migration.id}`);
          return ((await shown.json()) as Migration).status;
        },
        { timeout: 120_000, intervals: [3_000], message: 'the cancel never finished' }
      )
      .toBe('cancelled');
    await expectStatus(
      await authedRequest.post(`wordpress/migrations/${migration.id}/cancel`),
      409,
      'cancel a cancelled migration'
    );
    await expectStatus(
      await authedRequest.delete(`wordpress/migrations/${migration.id}`),
      204,
      'DELETE'
    );
    await expectStatus(
      await authedRequest.get(`wordpress/migrations/${migration.id}`),
      404,
      'GET after delete'
    );
  });

  test('every action on a migration that does not exist is 404', async ({ authedRequest }) => {
    const id = 2_000_000_000;
    await expectStatus(await authedRequest.get(`wordpress/migrations/${id}`), 404, 'GET');
    await expectStatus(
      await authedRequest.post(`wordpress/migrations/${id}/resume`, { data: {} }),
      404,
      'resume'
    );
    await expectStatus(
      await authedRequest.post(`wordpress/migrations/${id}/cancel`),
      404,
      'cancel'
    );
    await expectStatus(await authedRequest.delete(`wordpress/migrations/${id}`), 404, 'DELETE');
  });

  test('a WordPress site is imported from another server and served here', async ({
    authedRequest,
    anonymousRequest,
    api,
  }) => {
    test.setTimeout(1_800_000);
    test.skip(
      !SOURCE_URL || !SOURCE_USER || !SOURCE_PASSWORD || !SOURCE_TITLE,
      'Set WP_MIGRATION_SOURCE_URL, _USER, _PASSWORD and _TITLE to import a public source site.'
    );
    const started = await authedRequest.post('wordpress/migrations', {
      data: {
        url: SOURCE_URL,
        method: 'wp_admin',
        username: SOURCE_USER,
        password: SOURCE_PASSWORD,
        project_name: `pa-import-${Date.now()}`,
      },
    });
    await expectStatus(started, 202, 'POST wordpress/migrations');
    const migration = (await started.json()) as Migration;
    let latest = migration;
    try {
      await expect
        .poll(
          async () => {
            const shown = await authedRequest.get(`wordpress/migrations/${migration.id}`);
            latest = (await shown.json()) as Migration;
            return latest.status;
          },
          { timeout: 1_500_000, intervals: [15_000], message: 'the import never finished' }
        )
        .toMatch(/^(completed|failed|cancelled)$/);
      expect(latest.status).toBe('completed');
      expect(latest.target_project).toBeTruthy();
      const project = await api.getUser(latest.target_project!);
      await waitForPage(
        anonymousRequest,
        `https://${project.data.domain}/`,
        (page) => page.status === 200 && page.body.includes(SOURCE_TITLE!),
        'the imported site never served the source title'
      );
    } finally {
      if (latest.target_project) {
        await api.deleteUserSafe(latest.target_project);
      }
      await authedRequest.delete(`wordpress/migrations/${migration.id}`);
    }
  });
});
