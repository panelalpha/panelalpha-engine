import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { skipUnless } from '@/helpers/test-helpers';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import type { EnvVarEntry } from '@/types';

function entryFor(vars: EnvVarEntry[], key: string): EnvVarEntry | undefined {
  return vars.find((entry) => entry.key === key);
}

test.describe('env vars', () => {
  test('a DinD project lists the stored keys with masked values', async ({
    api,
    sharedEmptyDind,
  }) => {
    const user = await sharedEmptyDind.get();
    skipUnless(user, 'DinD is not available on this engine.');

    const masked = await api.listEnvVars(user.username);
    for (const entry of masked.data.vars) {
      expect(entry.value, `${entry.key} is masked`).toBeNull();
      expect(entry.masked, `${entry.key} is masked`).toBe(true);
    }
    expect(Array.isArray(masked.data.applied_keys), 'applied_keys is an array').toBe(true);

    const revealed = await api.listEnvVars(user.username, { reveal: true });
    expect(revealed.data.vars.length, 'the same keys either way').toBe(masked.data.vars.length);
    for (const entry of revealed.data.vars) {
      expect(entry.masked, `${entry.key} is revealed`).toBe(false);
    }
  });

  test('an unknown project is 404', async ({ api }) => {
    await expectStatus(await api.listEnvVarsRaw('nosuchuser999'), 404);
  });

  test('a PHP hosting project is refused with env_vars_dind_only', async ({ api, setupUser }) => {
    const response = await api.listEnvVarsRaw(setupUser.username);
    await expectStatus(response, 422);
    expect(
      (response.body as { problems?: { code?: string }[] }).problems?.[0]?.code,
      'the refusal names its code'
    ).toBe('env_vars_dind_only');
  });

  test('a deploy in flight answers 409 with its task; then a recreate applies the value', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const deploy = await api.rebuildUserRaw(user.username);
    await expectStatus(deploy, 202);
    const deployTaskId = taskIdFromBody(deploy.body);

    const busy = await api.setEnvVarsRaw(user.username, { env_vars: { FOO: 'bar' } });
    await expectStatus(busy, 409);
    expect((busy.body as { task_id?: number }).task_id, 'the busy deploy names its task').toBe(
      deployTaskId
    );
    await api.followDeployTask(deploy.body, 'POST rebuild');

    const started = await api.setEnvVarsRaw(user.username, { env_vars: { FOO: 'bar' } });
    await expectStatus(started, 202);
    const taskId = taskIdFromBody(started.body);
    expect(taskId, 'the apply names its task').toEqual(expect.any(Number));

    const task = await waitForTask(api, taskId ?? 0);
    expect(task.status, 'the apply completed').toBe('completed');
    expect(task.details, 'the task says what it applied').toMatchObject({
      action: 'env_apply',
      keys: ['FOO'],
    });

    const stored = await api.listEnvVars(user.username, { reveal: true });
    expect(entryFor(stored.data.vars, 'FOO'), 'the value reads back').toEqual({
      key: 'FOO',
      value: 'bar',
      masked: false,
    });
  });

  test('apply:none stores without a task, empty removes, replace keeps only the new map', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    const stored = await api.setEnvVars(user.username, {
      env_vars: { KEEP: 'k', DROP: 'd' },
      apply: 'none',
    });
    expect(stored.data, 'apply:none answers with the stored keys').toEqual({
      keys: ['KEEP', 'DROP'],
    });

    await api.setEnvVars(user.username, { env_vars: { DROP: '' }, apply: 'none' });
    const afterRemove = await api.listEnvVars(user.username, { reveal: true });
    expect(entryFor(afterRemove.data.vars, 'KEEP'), 'the untouched key stays').toMatchObject({
      value: 'k',
    });
    expect(entryFor(afterRemove.data.vars, 'DROP'), 'the removed key is gone').toBeUndefined();

    await api.setEnvVars(user.username, { env_vars: { ONLY: 'o' }, replace: true, apply: 'none' });
    const afterReplace = await api.listEnvVars(user.username, { reveal: true });
    expect(
      afterReplace.data.vars.map((entry) => entry.key),
      'replace leaves only the new map'
    ).toEqual(['ONLY']);

    const invalid = await api.setEnvVarsRaw(user.username, { env_vars: { '1BAD': 'x' } });
    await expectStatus(invalid, 422);
    expect(
      (invalid.body as { problems?: { code?: string }[] }).problems?.[0]?.code,
      'an invalid key names its code'
    ).toBe('env_vars_invalid_key');
  });

  test('an empty map with replace clears every key', async ({ api, userFactory }) => {
    const user = await userFactory.createDindUser();
    skipUnless(user, 'DinD is not available on this engine.');

    await api.setEnvVars(user.username, { env_vars: { KEEP: 'k' }, apply: 'none' });

    const cleared = await api.setEnvVars(user.username, {
      env_vars: {},
      replace: true,
      apply: 'none',
    });
    expect(cleared.data, 'the clear names no stored key').toEqual({ keys: [] });

    const listed = await api.listEnvVars(user.username, { reveal: true });
    expect(listed.data.vars, 'nothing is stored').toEqual([]);
  });
});
