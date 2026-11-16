import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import {
  modsecUnavailableReason,
  restoreModSec,
  snapshotModSec,
  type ModSecSnapshot,
} from '@/helpers/modsec-helpers';
import { uniqueId } from '@/helpers/random';
import { readPage, waitForPage } from '@/helpers/site-page';

const CUSTOM_RULESET = 'custom';
const RULE_ID = 1100042;

function denyRule(token: string, id = RULE_ID): string {
  return `SecRule REQUEST_URI "@contains ${token}" "id:${id},phase:1,deny,status:403,log,msg:'pa-test custom rule'"\n`;
}

/** A custom rule denying one unique URL is stored once parsed and refuses exactly that URL. */
test.describe('ModSecurity custom rules', () => {
  let snapshot: ModSecSnapshot;
  let previousRules: string;

  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');
    snapshot = await snapshotModSec(api);
    previousRules = (await api.getModSecurityCustomRules()).data.rules;
  });

  test.afterEach(async ({ api }) => {
    await api.setModSecurityCustomRulesRaw(previousRules === '' ? null : previousRules);
    await restoreModSec(api, snapshot);
  });

  test('a stored rule denies the request it matches and nothing else', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    test.setTimeout(240_000);
    const token = uniqueId('pa-test-deny-');
    const site = `https://${setupUser.domain}/`;

    await test.step('the rules are stored and answered back', async () => {
      const saved = await api.setModSecurityCustomRulesRaw(denyRule(token));
      await expectStatus(saved, 200, JSON.stringify(saved.body));
      const read = await api.getModSecurityCustomRules();
      expect(read.data.rules.trim()).toBe(denyRule(token).trim());
      expect(read.data.id_range).toEqual([1100000, 1199999]);
    });

    await test.step('the custom rule set is enforced', async () => {
      await api.enableModSecurityRuleset(CUSTOM_RULESET);
      await api.setModSecurityConfig({ mode: 'on' });
      expect((await api.getModSecurityCustomRules()).data.enabled).toBe(true);
    });

    await test.step('the matching URL is denied', async () => {
      await waitForPage(
        anonymousRequest,
        `${site}?${token}`,
        (answer) => answer.status === 403,
        'the custom rule never denied the matching URL'
      );
    });

    await test.step('the same page without the token is served', async () => {
      const page = await readPage(anonymousRequest, site);
      expect(page.status, page.body.slice(0, 200)).toBeLessThan(400);
    });

    await test.step('removing the rules lets the URL through again', async () => {
      await expectStatus(await api.setModSecurityCustomRulesRaw(null), 200, 'PUT null');
      expect((await api.getModSecurityCustomRules()).data.rules).toBe('');
      await waitForPage(
        anonymousRequest,
        `${site}?${token}`,
        (answer) => answer.status < 400,
        'the URL stayed denied after the rules were removed'
      );
    });
  });

  test('rules the engine cannot load are refused and the stored ones stay', async ({ api }) => {
    const kept = denyRule(uniqueId('pa-test-kept-'));
    await expectStatus(await api.setModSecurityCustomRulesRaw(kept), 200, 'store valid rules');

    for (const [label, rules] of [
      ['an id outside the custom range', denyRule('x', 900001)],
      ['a directive the webserver refuses', 'SecRuleNotADirective "x"\n'],
      ['a rule turning the engine off', 'SecRuleEngine Off\n'],
    ] as const) {
      await test.step(label, async () => {
        await expectStatus(await api.setModSecurityCustomRulesRaw(rules), 422, label);
      });
    }

    expect((await api.getModSecurityCustomRules()).data.rules.trim()).toBe(kept.trim());
  });
});
