import { expect, test } from '@/fixtures/test-options';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { stageFileForArtisan } from '@/helpers/host-exec';
import { Timeouts } from '@/config/timeouts';
import { healthFromRaw } from '@/helpers/app-health';

test.describe('pae-artisan command parity', () => {
  test.beforeEach(({ hostExec }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
  });

  test('system:version matches GET /system/info', async ({ hostExec, api }) => {
    const version = await hostExec!.pae(['system:version']);
    expect(version.exitCode).toBe(0);
    const info = (await api.getSystemInfo()).data.version;
    expect(version.stdout).toContain(info);
  });

  test('system:database:test exits 0', async ({ hostExec }) => {
    const result = await hostExec!.pae(['system:database:test']);
    expect(result.exitCode).toBe(0);
  });

  test('mcp:tool:list exits 0 and names a tool', async ({ hostExec }) => {
    const result = await hostExec!.pae(['mcp:tool:list']);
    expect(result.exitCode).toBe(0);
    expect(result.stdout).toMatch(/system_info|metrics_latest|project_list_summary/);
  });

  test('mcp:check accepts a fresh token and rejects garbage', async ({ hostExec }) => {
    const name = uniqueId('cli-check-');
    const created = await hostExec!.pae(['mcp:token:create', name, '--short', '--no-register']);
    test.skip(created.exitCode !== 0, 'Could not mint an MCP token via CLI.');
    const token = created.stdout
      .split('\n')
      .map((line) => line.trim())
      .find((line) => /^\d+\|\S+$/.test(line));
    skipUnless(token, 'mcp:token:create --short did not print a token.');
    const id = token.split('|')[0];

    try {
      const ok = await hostExec!.pae(['mcp:check', token]);
      expect(ok.exitCode).toBe(0);
      const bad = await hostExec!.pae(['mcp:check', 'not-a-token']);
      expect(bad.exitCode).not.toBe(0);
    } finally {
      await hostExec!.pae(['mcp:token:delete', id]);
    }
  });

  test('project:ssh, domain:list and limit:get agree with REST', async ({
    hostExec,
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const ssh = await hostExec!.pae(['project:ssh', user.username, 'echo pae', '--json']);
    expect(ssh.exitCode).toBe(0);
    expect(ssh.stdout).toMatch(/pae/);

    const viaRest = await api.runSshCommand(user.username, { command: 'echo pae' });
    expect(viaRest.stdout).toContain('pae');

    const domains = await hostExec!.pae(['domain:list', `--project=${user.username}`]);
    expect(domains.exitCode).toBe(0);
    expect(domains.stdout).toContain(user.domain);

    const shown = await api.getUser(user.username);
    const limits = await hostExec!.pae(['project:limit:get', `--project=${user.username}`]);
    expect(limits.exitCode).toBe(0);
    expect(limits.stdout).toContain(user.username);
    const disk = shown.data.details.disk_space_limit;
    if (disk === -1 || disk === undefined) {
      expect(limits.stdout).toMatch(/disk_space_limit:\s+no limit/);
    } else {
      expect(limits.stdout).toContain(String(disk));
    }
  });

  test('project:domain:log lists the same files as REST', async ({
    hostExec,
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const listed = await api.listDomainLogFiles(user.username, user.domain);
    const viaCli = await hostExec!.pae(['project:domain:log', user.username, user.domain]);
    expect(viaCli.exitCode).toBe(0);
    for (const entry of listed.data) {
      expect(viaCli.stdout).toContain(entry.file);
    }
  });

  test('project:file upload and download round-trip bytes', async ({
    hostExec,
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const destDir = `/${user.domain}/public_html`;
    const filename = 'cli-roundtrip.txt';
    const relative = `${destDir}/${filename}`;
    const downloadContents = `cli-down-${Date.now()}`;
    await api.putFileContents(user.username, relative, downloadContents);

    const downloaded = await hostExec!.pae([
      'project:file:download',
      user.username,
      `--path=${relative}`,
    ]);
    expect(downloaded.exitCode).toBe(0);
    expect(downloaded.stdout).toContain(downloadContents);

    const uploadContents = `cli-up-${Date.now()}`;
    const staged = await stageFileForArtisan(
      hostExec!,
      `pae-cli-${uniqueId('f')}.txt`,
      uploadContents
    );
    skipUnless(staged, 'Could not stage a file where artisan can read it.');
    const uploaded = await hostExec!.pae([
      'project:file:upload',
      user.username,
      staged,
      `--path=${destDir}`,
    ]);
    expect(uploaded.exitCode).toBe(0);
    const readBack = await api.getFileContent(
      user.username,
      `${destDir}/${staged.split('/').pop() ?? filename}`
    );
    expect(readBack).toContain(uploadContents);
  });

  test('project:deploy:log is readable for a DinD user', async ({ hostExec, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');
    const log = await hostExec!.pae(['project:deploy:log', user.username]);
    expect([0, 1]).toContain(log.exitCode);

    const check = await hostExec!.pae(['project:deploy:check', user.username, '--json']);
    expect([0, 1]).toContain(check.exitCode);
    if (check.stdout.trim().startsWith('{')) {
      const parsed = JSON.parse(check.stdout) as { serving?: unknown };
      if (typeof parsed.serving === 'string') {
        healthFromRaw({ data: parsed });
      }
    }
  });

  test('project:deploy:log and timings --json after a git deploy', async ({
    hostExec,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy);
    const user = await userFactory.createDeployedUser();
    skipUnless(user, 'Could not create a git-deployed user on this engine.');

    const log = await hostExec!.pae(['project:deploy:log', user.username]);
    expect(log.exitCode).toBe(0);
    expect(log.stdout).toMatch(/Status:/);

    const timings = await hostExec!.pae(['project:deploy:timings', user.username, '--json']);
    expect(timings.exitCode).toBe(0);
    const parsed = JSON.parse(timings.stdout) as {
      phases?: unknown;
      total_seconds?: number | null;
    };
    expect(Array.isArray(parsed.phases)).toBe(true);

    const check = await hostExec!.pae(['project:deploy:check', user.username, '--json']);
    expect([0, 1]).toContain(check.exitCode);
    expect(check.stdout.trim(), 'project:deploy:check --json must print the report').toMatch(/^\{/);
    const checkJson: unknown = JSON.parse(check.stdout);
    const report = healthFromRaw({ data: checkJson });
    expect(report.healthy === null || typeof report.healthy === 'boolean').toBe(true);
  });
});
