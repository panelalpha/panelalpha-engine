import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import { isOwaspConfigFileEnabled } from '@/helpers/modsec-config-apply';
import {
  NON_EXISTENT_RULESET,
  modSecMatchesSnapshot,
  modsecUnavailableReason,
  restoreModSec,
  snapshotModSec,
  type ModSecSnapshot,
} from '@/helpers/modsec-helpers';
import { rawResponseMessage } from '@/helpers/raw-response';
import { waitForCondition } from '@/helpers/retry';
import { expectStatus } from '@/helpers/expect-status';
import type { ModSecurityConfigFileEntry } from '@/types';

/** Toggle API wants the basename without the `.disabled` suffix. */
function configFileToggleName(entry: ModSecurityConfigFileEntry): string {
  const raw = typeof entry === 'string' ? entry : entry.file;
  return raw.replace(/\.disabled$/, '');
}

test.describe('ModSecurity rulesets', () => {
  let snapshot: ModSecSnapshot;

  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');

    snapshot = await snapshotModSec(api);
    test.skip(snapshot.rulesets.length === 0, 'This engine ships no ModSecurity rulesets.');
  });

  test.afterEach(async ({ api }) => {
    // beforeEach can skip before it takes the snapshot, and Playwright still runs this hook.
    if (!snapshot) {
      return;
    }

    const cleanup = new CleanupSteps();
    await cleanup.run('restore ModSecurity', () => restoreModSec(api, snapshot), {
      verify: () => modSecMatchesSnapshot(api, snapshot),
      describe: () => 'mode or a ruleset is not back to the snapshot',
    });
    await cleanup.finish();
  });

  test('every ruleset has a name and an enabled flag', async ({ api }) => {
    const { data } = await api.listModSecurityRulesets();
    expect(Array.isArray(data)).toBe(true);

    for (const ruleset of data) {
      expect(typeof ruleset.name).toBe('string');
      expect(typeof ruleset.enabled).toBe('boolean');
    }
  });

  test('a ruleset can be enabled and disabled', async ({ api }) => {
    const name = snapshot.rulesets[0].name;

    const stateOf = async () =>
      (await api.listModSecurityRulesets()).data.find((entry) => entry.name === name)?.enabled;

    const waitForState = async (expected: boolean) => {
      let last: boolean | undefined;
      await waitForCondition(
        async () => {
          last = await stateOf();
          return last === expected;
        },
        {
          timeout: 10_000,
          interval: 500,
          message: `${name} never reported as ${expected ? 'enabled' : 'disabled'}`,
          describeLast: () => `enabled=${String(last)}`,
        }
      );
    };

    await api.enableModSecurityRuleset(name);
    await waitForState(true);
    expect(await stateOf(), `${name} did not report as enabled`).toBe(true);

    await api.disableModSecurityRuleset(name);
    await waitForState(false);
    expect(await stateOf(), `${name} did not report as disabled`).toBe(false);
  });

  test('an individual config file can be toggled off and back on', async ({ api }) => {
    const name = snapshot.rulesets[0].name;
    const ruleset = (await api.listModSecurityRulesets()).data.find((entry) => entry.name === name);

    const configFiles = ruleset?.config_files ?? [];
    test.skip(configFiles.length === 0, `Ruleset ${name} exposes no config files.`);

    const targetName = configFileToggleName(configFiles[0]);
    const wasEnabled = isOwaspConfigFileEnabled(configFiles, targetName);

    // Toggle away from the current state, then back.
    await api.toggleModSecurityConfigFiles(
      name,
      wasEnabled ? [] : [targetName],
      wasEnabled ? [targetName] : []
    );
    await api.toggleModSecurityConfigFiles(
      name,
      wasEnabled ? [targetName] : [],
      wasEnabled ? [] : [targetName]
    );

    const after = (await api.listModSecurityRulesets()).data.find((entry) => entry.name === name);

    expect(
      isOwaspConfigFileEnabled(after?.config_files, targetName),
      `${targetName} did not return to its original state`
    ).toBe(wasEnabled);
  });

  const emptyToggles: [label: string, payload: { enable?: string[]; disable?: string[] }][] = [
    ['empty enable and disable arrays', { enable: [], disable: [] }],
    ['no payload at all', {}],
  ];

  for (const [label, payload] of emptyToggles) {
    test(`a config-file toggle with ${label} is accepted as a no-op`, async ({ api }) => {
      const name = snapshot.rulesets[0].name;
      await expectStatus(await api.toggleModSecurityConfigFilesRaw(name, payload), 200);
    });
  }

  test('concurrent enable and disable requests all succeed', async ({ api }) => {
    const name = snapshot.rulesets[0].name;

    const statuses = (
      await Promise.all([
        api.enableModSecurityRulesetRaw(name),
        api.disableModSecurityRulesetRaw(name),
        api.enableModSecurityRulesetRaw(name),
      ])
    ).map((response) => response.status);

    expect(statuses).toEqual([200, 200, 200]);
  });
});

test.describe('ModSecurity ruleset validation', () => {
  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');
  });

  const unknownRulesets = [
    NON_EXISTENT_RULESET,
    'fake_ruleset_abc',
    'test-ruleset-that-does-not-exist',
  ];

  for (const name of unknownRulesets) {
    test(`enabling and disabling "${name}" both return 404`, async ({ api }) => {
      const enabled = await api.enableModSecurityRulesetRaw(name);
      await expectStatus(enabled, 404);
      expect(rawResponseMessage(enabled) ?? '').toContain('not found');

      const disabled = await api.disableModSecurityRulesetRaw(name);
      await expectStatus(disabled, 404);
      expect(rawResponseMessage(disabled) ?? '').toContain('not found');
    });
  }

  test('toggling config files on an unknown ruleset returns 404', async ({ api }) => {
    const response = await api.toggleModSecurityConfigFilesRaw(NON_EXISTENT_RULESET, {
      enable: ['test.conf'],
    });
    await expectStatus(response, 404);
  });
});
