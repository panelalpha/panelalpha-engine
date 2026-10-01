import { expect, test } from '@/fixtures/test-options';
import { Timeouts } from '@/config/timeouts';
import {
  CSF_DISABLE_DELAY_MS,
  CSF_PROPAGATION_DELAY_MS,
  CSF_RESTART_DELAY_MS,
  applyCsfToggle,
  readCsfState,
} from '@/helpers/csf-helpers';
import { expectOneOf } from '@/helpers/expect-one-of';
import { randomIpv4 } from '@/helpers/random';
import { delay, waitForCondition } from '@/helpers/retry';

const RULE_COMMENT = 'api-test rule';
const UPDATED_RULE_COMMENT = 'api-test rule, updated';

/**
 * CSF is an optional module. Every test starts by reading its state and skips
 * when it is absent, so "CSF is not installed" and "CSF is broken" stay
 * distinguishable — the original suite silently passed in both cases.
 */
test.describe('CSF firewall', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await readCsfState(api)), 'CSF is not installed on this engine.');
  });

  /** CSF must be left enabled: the rest of the suite runs against a firewalled host. */
  test.afterEach(async ({ api }) => {
    const state = await readCsfState(api);
    if (state && !state.enabled) {
      await applyCsfToggle(() => api.enableCsf());
    }
  });

  test('the status reports a version', { tag: ['@smoke'] }, async ({ api }) => {
    const state = await readCsfState(api);
    expect(state?.version).toBeTruthy();
    expect(typeof state?.enabled).toBe('boolean');
  });

  test('UI credentials are issued', async ({ api }) => {
    const { data } = await api.getCsfUiCredentials();
    expect(data.username).toBeTruthy();
    expect(data.password).toBeTruthy();
  });

  test('the rules listing has allow and deny groups', async ({ api }) => {
    const { data } = await api.getCsfRules();
    expect(data).toHaveProperty('allow');
    expect(data).toHaveProperty('deny');
  });

  test('a restart keeps the firewall up and its rules readable', async ({ api }) => {
    const before = await readCsfState(api);

    await applyCsfToggle(() => api.restartCsf());
    await delay(CSF_RESTART_DELAY_MS);

    const after = await readCsfState(api);
    expect(after?.enabled).toBe(true);
    expect(after?.version, 'the CSF version changed across a restart').toBe(before?.version);

    const { data: rules } = await api.getCsfRules();
    expect(rules).toHaveProperty('allow');
    expect(rules).toHaveProperty('deny');
  });

  test('the firewall comes back after being disabled', { tag: ['@slow'] }, async ({ api }) => {
    // Disabling flushes iptables and enabling reloads every rule; together they
    // routinely exceed the default per-test budget on a real host.
    test.setTimeout(Timeouts.csfDisableEnable);

    await applyCsfToggle(() => api.disableCsf());
    await delay(CSF_DISABLE_DELAY_MS);

    await waitForCondition(
      async () => {
        try {
          await api.enableCsf();
          return true;
        } catch (error) {
          // The API port can still be refusing connections while iptables reloads.
          return String(error).includes('ECONNRESET');
        }
      },
      { timeout: 60_000, interval: 10_000, message: 'CSF could not be enabled again' }
    );

    await delay(CSF_RESTART_DELAY_MS);
    expect((await readCsfState(api))?.enabled).toBe(true);
  });
});

test.describe('CSF rules', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await readCsfState(api)), 'CSF is not installed on this engine.');
  });

  /** Returns the allow rules, after giving CSF time to rewrite its files. */
  const allowRules = async (api: Parameters<typeof readCsfState>[0]) => {
    await delay(CSF_PROPAGATION_DELAY_MS);
    return (await api.getCsfRules()).data.allow ?? [];
  };

  test('a deny rule is added, edited and removed', async ({ api }) => {
    const target = randomIpv4();
    const added = await api.addCsfRule(target, 'deny', RULE_COMMENT);
    let lineMd5 = added.data.line_md5;

    const denyRules = async () => {
      await delay(CSF_PROPAGATION_DELAY_MS);
      return (await api.getCsfRules()).data.deny ?? [];
    };

    try {
      expect((await denyRules()).map((rule) => rule.target)).toContain(target);

      const edited = await api.editCsfRule(lineMd5, target, 'deny', UPDATED_RULE_COMMENT);
      const previousMd5 = lineMd5;
      lineMd5 = edited.data.line_md5;

      const afterEdit = await denyRules();
      expect(
        afterEdit.some((rule) => rule.comment?.includes(UPDATED_RULE_COMMENT)),
        'the updated deny comment is not in the rules listing'
      ).toBe(true);
      expect(afterEdit.map((rule) => rule.line_md5)).not.toContain(previousMd5);
      expect(afterEdit.map((rule) => rule.line_md5)).toContain(lineMd5);

      await api.removeCsfRule(lineMd5, 'deny');
      expect((await denyRules()).map((rule) => rule.line_md5)).not.toContain(lineMd5);
    } finally {
      await api.removeCsfRule(lineMd5, 'deny').catch(() => undefined);
    }
  });

  test('an allow rule is added, edited and removed', async ({ api }) => {
    const target = randomIpv4();
    const added = await api.addCsfRule(target, 'allow', RULE_COMMENT);
    let lineMd5 = added.data.line_md5;

    try {
      expect((await allowRules(api)).map((rule) => rule.target)).toContain(target);

      const edited = await api.editCsfRule(lineMd5, target, 'allow', UPDATED_RULE_COMMENT);
      const previousMd5 = lineMd5;
      lineMd5 = edited.data.line_md5;

      const afterEdit = await allowRules(api);
      expect(
        afterEdit.some((rule) => rule.comment?.includes(UPDATED_RULE_COMMENT)),
        'the updated comment is not in the rules listing'
      ).toBe(true);
      expect(
        afterEdit.map((rule) => rule.line_md5),
        'the pre-edit rule is still listed'
      ).not.toContain(previousMd5);
      expect(afterEdit.map((rule) => rule.line_md5)).toContain(lineMd5);

      await api.removeCsfRule(lineMd5, 'allow');
      expect((await allowRules(api)).map((rule) => rule.line_md5)).not.toContain(lineMd5);
    } finally {
      await api.removeCsfRule(lineMd5, 'allow').catch(() => undefined);
    }
  });

  test('an unknown rule type is refused', async ({ authedRequest }) => {
    const response = await authedRequest.post('csf/rules/invalid_type', {
      data: { target: '192.168.100.100' },
    });
    expectOneOf(response.status(), [400, 404, 422]);
  });

  test('a non-string target is refused', async ({ authedRequest }) => {
    const response = await authedRequest.post('csf/rules/allow', { data: { target: 1234567890 } });
    expect(response.status()).toBe(422);
  });

  test('deleting an unknown rule reports an error', async ({ authedRequest }) => {
    const response = await authedRequest.delete('csf/rules/allow/nonexistent123456789abcdef');
    expectOneOf(response.status(), [404, 422, 500]);
  });
});

test.describe('CSF enable and disable are idempotent', () => {
  test.beforeEach(async ({ api }) => {
    test.skip(!(await readCsfState(api)), 'CSF is not installed on this engine.');
  });

  test.afterEach(async ({ api }) => {
    const state = await readCsfState(api);
    if (state && !state.enabled) {
      await applyCsfToggle(() => api.enableCsf());
      await delay(CSF_RESTART_DELAY_MS);
    }
  });

  test('enabling an already-enabled firewall is accepted', async ({ api, authedRequest }) => {
    if (!(await readCsfState(api))?.enabled) {
      await applyCsfToggle(() => api.enableCsf());
      await delay(CSF_RESTART_DELAY_MS);
    }

    expectOneOf((await authedRequest.put('csf/enable')).status(), [200, 204]);
  });

  test(
    'disabling an already-disabled firewall is accepted',
    { tag: ['@slow'] },
    async ({ api, authedRequest }) => {
      test.setTimeout(Timeouts.csfDisableEnable);

      if ((await readCsfState(api))?.enabled) {
        await applyCsfToggle(() => api.disableCsf());
        await delay(CSF_DISABLE_DELAY_MS);
      }

      // The disable is queued and answered 202. 502 counts: the earlier disable
      // flushes iptables, which can momentarily drop the proxy-to-backend connection.
      expectOneOf((await authedRequest.put('csf/disable')).status(), [202, 502]);
    }
  );
});
