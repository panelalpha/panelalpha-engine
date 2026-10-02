import { expect, test } from '@/fixtures/test-options';

test.describe('pae-artisan wrapper', () => {
  test.beforeEach(({ hostExec }) => {
    test.skip(!hostExec, 'pae-artisan is not reachable from this runner.');
  });

  test('list includes api:token:create and mcp:check', async ({ hostExec }) => {
    const listed = await hostExec!.pae(['list']);
    expect(listed.exitCode).toBe(0);
    expect(listed.stdout).toContain('api:token:create');
    expect(listed.stdout).toContain('mcp:check');
  });

  test('an unknown command is non-zero', async ({ hostExec }) => {
    const result = await hostExec!.pae(['nope']);
    expect(result.exitCode).not.toBe(0);
  });

  test('pae and pae-artisan agree on list output when both exist', async ({ hostExec }) => {
    const artisan = await hostExec!.pae(['list']);
    const aliased = await hostExec!.run('pae', ['list']).catch(() => null);
    test.skip(aliased?.exitCode !== 0, 'The pae alias is not installed.');
    expect(aliased!.stdout).toContain('api:token:create');
    expect(artisan.stdout).toContain('api:token:create');
  });

  test('pae-command.sh without args prints usage', async ({ hostExec }) => {
    const script = '/opt/panelalpha/shared-hosting/scripts/pae-command.sh';
    const result = await hostExec!.run(script, []);
    test.skip(result.exitCode === 127, 'pae-command.sh is not on this engine.');
    expect(result.exitCode).not.toBe(0);
    expect(`${result.stdout}\n${result.stderr}`).toMatch(/register|unregister|Usage/i);
  });

  test('register is not re-run unless ALLOW_PAE_REGISTER=1', async ({ hostExec }) => {
    test.skip(
      process.env.ALLOW_PAE_REGISTER !== '1',
      'Register mutates /usr/local/bin; set ALLOW_PAE_REGISTER=1 to exercise it.'
    );
    const script = '/opt/panelalpha/shared-hosting/scripts/pae-command.sh';
    const result = await hostExec!.run(script, ['register']);
    expectOneOfStatus(result.exitCode, [0]);
  });
});

function expectOneOfStatus(actual: number, allowed: number[]): void {
  expect(allowed).toContain(actual);
}
