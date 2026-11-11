import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import { requireServing } from '@/helpers/app-health';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { randomUsername } from '@/helpers/random';
import { type EngineApi } from '@/clients/engine-api';

/** POST /projects with a typed `source`: one call, one task, deployed and asked if it serves. */

const SPOON_KNIFE_ZIP = 'https://github.com/octocat/Spoon-Knife/archive/refs/heads/main.zip';

type Fields = Record<string, unknown>;

interface Raw {
  status: number;
  body: unknown;
}

interface TaskAnswer {
  data: { job_type: string; details: Fields };
}

interface Refusal {
  problems: { field: string; code: string }[];
}

function taskAnswer(raw: Raw): TaskAnswer {
  return raw.body as TaskAnswer;
}

function problemsOf(raw: Raw): [string, string][] {
  return (raw.body as Refusal).problems.map((p) => [p.field, p.code]);
}

async function followCreate(api: EngineApi, created: Raw, what: string): Promise<Fields> {
  await expectStatus(created, 202, JSON.stringify(created.body));
  const task = await api.followDeployTask(created.body, what);

  return (task.details ?? {}) as Fields;
}

async function assertServes(api: EngineApi, name: string): Promise<void> {
  const health = await api.getAppHealthRaw(name);
  await expectStatus(health, 200, JSON.stringify(health.body));
  expect(requireServing(health.body).serving, `${name} serves its app`).toBe('ok');
}

async function sourceOf(api: EngineApi, name: string): Promise<Fields> {
  const shown = await api.getUserRaw(name);
  await expectStatus(shown, 200, JSON.stringify(shown.body));

  return (shown.body as { data: { source: Fields } }).data.source;
}

async function assertNotCreated(api: EngineApi, name: string): Promise<void> {
  await expectStatus(await api.getUserRaw(name), 404, `${name} must not exist`);
}

test.describe('typed project create', () => {
  test('the engine says it understands the typed body', async ({ api }) => {
    const info = await api.get('system/info');
    await expectStatus(info, 200);
    const body = (await info.json()) as { data: { features?: string[] } };
    expect(body.data.features, 'features').toContain('project_source');
  });

  test('a malformed typed body is refused and creates nothing', async ({ api }) => {
    const name = randomUsername();
    const refused = await api.createTypedProjectRaw({
      name,
      runtime: 'container',
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
      limits: { ram: 1 },
      source: { type: 'git', url: 'git@github.com:octocat/Spoon-Knife.git' },
    });

    await expectStatus(refused, 422, JSON.stringify(refused.body));
    expect(problemsOf(refused)).toEqual(
      expect.arrayContaining([
        ['git_repo', 'legacy_field'],
        ['limits.ram', 'unknown_field'],
        ['source.url', 'source_ssh_needs_deploy_key'],
      ])
    );
    await assertNotCreated(api, name);
  });

  test('a refusal from provisioning names the typed field', async ({ api }) => {
    const name = randomUsername();
    const refused = await api.createTypedProjectRaw({
      name,
      source: { type: 'git', url: DEFAULT_DEPLOY_GIT_REPO, branch: 'no-such-branch-xyz' },
    });

    await expectStatus(refused, 422, JSON.stringify(refused.body));
    expect(problemsOf(refused)[0]).toEqual(['source.branch', 'git_branch_not_found']);
    await assertNotCreated(api, name);
  });

  test(
    'an empty container project, then its git source set with PUT',
    { tag: ['@slow'] },
    async ({ api }) => {
      test.setTimeout(Timeouts.deploy * 3);
      const name = randomUsername();
      try {
        const empty = await followCreate(
          api,
          await api.createTypedProjectRaw({ name, domain: { tunnel: 'none' } }),
          'typed create, no source'
        );
        expect(empty.source_type).toBe('none');
        expect(empty.waiting_for_files, 'the project waits for files').toBe(true);
        expect(await sourceOf(api, name)).toEqual({ type: 'none' });

        const set = await api.setProjectSourceRaw(name, {
          source: { type: 'git', url: DEFAULT_DEPLOY_GIT_REPO, branch: 'main' },
          build: { env_vars: { TYPED_CREATE: 'yes' } },
        });
        await expectStatus(set, 202, JSON.stringify(set.body));
        expect(taskAnswer(set).data.details.action).toBe('attach_source');
        await api.followDeployTask(set.body, 'PUT /projects/{username}/source');

        expect(await sourceOf(api, name)).toMatchObject({
          type: 'git',
          url: DEFAULT_DEPLOY_GIT_REPO,
          branch: 'main',
          auth: 'none',
          rebuild: 'clone',
        });
        await assertServes(api, name);
      } finally {
        await api.deleteUserSafe(name);
      }
    }
  );

  test(
    'an empty container project, then an archive set with PUT',
    { tag: ['@slow'] },
    async ({ api }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const name = randomUsername();
      try {
        await followCreate(
          api,
          await api.createTypedProjectRaw({ name, domain: { tunnel: 'none' } }),
          'typed create, no source'
        );

        const set = await api.setProjectSourceRaw(name, {
          source: { type: 'archive', url: SPOON_KNIFE_ZIP },
        });
        await expectStatus(set, 202, JSON.stringify(set.body));
        expect(taskAnswer(set).data.details).toMatchObject({
          action: 'deploy_archive',
          source_type: 'archive',
          archive_url: SPOON_KNIFE_ZIP,
        });
        await api.followDeployTask(set.body, 'PUT /projects/{username}/source');

        expect(await sourceOf(api, name)).toEqual({ type: 'archive', url: SPOON_KNIFE_ZIP });
        await assertServes(api, name);
      } finally {
        await api.deleteUserSafe(name);
      }
    }
  );

  test('a git source in one call', { tag: ['@slow'] }, async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 2);
    const name = randomUsername();
    try {
      const details = await followCreate(
        api,
        await api.createTypedProjectRaw({
          name,
          domain: { tunnel: 'none' },
          limits: { memory: 512 },
          source: { type: 'git', url: DEFAULT_DEPLOY_GIT_REPO, branch: 'main' },
        }),
        'typed create, git'
      );
      expect(details.source_type).toBe('git');

      const shown = await api.getUser(name);
      expect(shown.data.details.template).toBe('dind');
      expect(shown.data.details.memory_limit).toBe(512);
      await assertServes(api, name);
    } finally {
      await api.deleteUserSafe(name);
    }
  });

  test(
    'an uploaded archive is deployed and the upload is gone',
    { tag: ['@slow'] },
    async ({ api }) => {
      test.setTimeout(Timeouts.deploy * 2);
      const zip = Buffer.from(await (await fetch(SPOON_KNIFE_ZIP)).arrayBuffer());
      const uploaded = await api.uploadArchiveRaw('spoon-knife.zip', zip);
      await expectStatus(uploaded, 201, JSON.stringify(uploaded.body));
      const upload = (uploaded.body as { data: { id: string; size: number } }).data;
      expect(upload.id).toMatch(/^upl_/);
      expect(upload.size).toBe(zip.length);

      const name = randomUsername();
      try {
        const details = await followCreate(
          api,
          await api.createTypedProjectRaw({
            name,
            domain: { tunnel: 'none' },
            source: { type: 'archive', upload_id: upload.id },
          }),
          'typed create, upload'
        );
        expect(details.source_type).toBe('archive');
        expect(details.upload_id).toBe(upload.id);
        await expectStatus(
          await api.getUploadRaw(upload.id),
          404,
          'the upload was moved into the project'
        );
        expect(await sourceOf(api, name)).toEqual({ type: 'archive', upload_id: upload.id });
        await assertServes(api, name);
      } finally {
        await api.deleteUserSafe(name);
        await api.deleteUploadRaw(upload.id);
      }
    }
  );

  test('an archive by URL is downloaded by the deploy', { tag: ['@slow'] }, async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 2);
    const name = randomUsername();
    try {
      const details = await followCreate(
        api,
        await api.createTypedProjectRaw({
          name,
          domain: { tunnel: 'none' },
          source: { type: 'archive', url: SPOON_KNIFE_ZIP },
        }),
        'typed create, archive URL'
      );
      expect(details.archive_url).toBe(SPOON_KNIFE_ZIP);
      await assertServes(api, name);
    } finally {
      await api.deleteUserSafe(name);
    }
  });

  test('a copy of a project is its own task', { tag: ['@slow'] }, async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 3);
    const original = randomUsername();
    const copy = randomUsername();
    try {
      await followCreate(
        api,
        await api.createTypedProjectRaw({
          name: original,
          domain: { tunnel: 'none' },
          source: { type: 'git', url: DEFAULT_DEPLOY_GIT_REPO, branch: 'main' },
        }),
        'typed create, original'
      );

      const created = await api.createTypedProjectRaw({
        name: copy,
        source: { type: 'project', from: original, copy_databases: false },
      });
      await expectStatus(created, 202, JSON.stringify(created.body));
      expect(taskAnswer(created).data.job_type).toContain('CopyProject');
      expect(taskAnswer(created).data.details).toMatchObject({
        action: 'copy',
        from: original,
        copy_databases: false,
      });
      await api.followDeployTask(created.body, 'typed create, copy');

      const shown = await api.getUser(copy);
      expect(shown.data.details.template).toBe('dind');
      expect((await sourceOf(api, copy)).type, 'a copy of a git project deploys from git').toBe(
        'git'
      );
    } finally {
      await api.deleteUserSafe(copy);
      await api.deleteUserSafe(original);
    }
  });

  test('a fresh WordPress on a php_hosting project', { tag: ['@slow'] }, async ({ api }) => {
    test.setTimeout(Timeouts.deploy * 2);
    const name = randomUsername();
    const password = 'typed-create-pass-1';
    try {
      const details = await followCreate(
        api,
        await api.createTypedProjectRaw({
          name,
          domain: { tunnel: 'none' },
          source: {
            type: 'wordpress',
            title: 'Typed create',
            admin: { user: 'typedadmin', email: 'typed@example.com', password },
          },
        }),
        'typed create, wordpress'
      );
      expect(details.source_type).toBe('wordpress');
      expect(JSON.stringify(details), 'the admin password never reaches the task').not.toContain(
        password
      );

      const shown = await api.getUser(name);
      expect(shown.data.details.template ?? null, 'php_hosting').toBeNull();
      expect(await sourceOf(api, name)).toEqual({ type: 'wordpress' });

      const core = await api.get(`projects/${name}/wp/core`);
      await expectStatus(core, 200);
      const status = (await core.json()) as { data: { installed: boolean } };
      expect(status.data.installed, 'WordPress is installed').toBe(true);
    } finally {
      await api.deleteUserSafe(name);
    }
  });

  test('an SSH remote whose deploy key is not on the repository creates nothing', async ({
    api,
  }) => {
    const minted = await api.createVaultDeployKeyRaw({ purpose: 'typed create spec' });
    await expectStatus(minted, 201, JSON.stringify(minted.body));
    const key = (minted.body as { data: { ref: string; public_key: string } }).data;
    expect(key.public_key).toMatch(/^ssh-ed25519 /);
    expect(JSON.stringify(minted.body), 'the private half never leaves').not.toContain('PRIVATE');

    const name = randomUsername();
    try {
      const refused = await api.createTypedProjectRaw({
        name,
        source: {
          type: 'git',
          url: 'git@github.com:octocat/Spoon-Knife.git',
          auth: { type: 'deploy_key', key: key.ref },
        },
      });

      await expectStatus(refused, 422, JSON.stringify(refused.body));
      expect(problemsOf(refused)[0]).toEqual(['source.auth.key', 'git_deploy_key_rejected']);
      await assertNotCreated(api, name);
    } finally {
      await api.deleteUserSafe(name);
      await api.delete(`vault/secrets/${key.ref}`);
    }
  });
});
