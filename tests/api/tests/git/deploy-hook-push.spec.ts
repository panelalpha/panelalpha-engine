import { createHmac, randomUUID } from 'node:crypto';
import { expect, test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { Timeouts } from '@/config/timeouts';
import { DEFAULT_DEPLOY_GIT_REPO } from '@/helpers/deploy-helpers';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { waitForPage } from '@/helpers/site-page';
import { skipUnless, skipUnlessResolvableSite } from '@/helpers/test-helpers';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';
import { deployHookCreateResponseSchema, deployHookShowResponseSchema } from '@/schemas';

/** Spoon-Knife's own front page. */
const UPSTREAM_TITLE = '<title>Spoon-Knife</title>';

interface Commit {
  hash: string;
}

async function headCommit(api: EngineApi, username: string): Promise<string> {
  const commits = (await api.gitCommits(username)).data as Commit[];
  return commits[0].hash;
}

function signature(secret: string, body: string): string {
  return `sha256=${createHmac('sha256', secret).update(body).digest('hex')}`;
}

/** The fixture cannot be pushed to, so the checkout is edited and a push restores the branch. */
test.describe('a deploy hook push', () => {
  test('a signed push to the tracked branch deploys the branch', async ({
    api,
    anonymousRequest,
    userFactory,
  }) => {
    test.setTimeout(Timeouts.deploy * 3);
    const user = await userFactory.createDeployedUser({
      git_repo: DEFAULT_DEPLOY_GIT_REPO,
      git_branch: 'main',
      tunnel: 'none',
    });
    skipUnless(user, 'DinD is not available on this engine.');
    skipUnlessResolvableSite(user.domain);
    const site = `https://${user.domain}/`;
    const head = await headCommit(api, user.username);
    const checkout = String((await api.gitStatus(user.username)).data.path);
    const edited = `<!doctype html><title>${uniqueId('local-edit-')}</title>`;

    await test.step('the deploy serves the branch file byte for byte', async () => {
      const page = await waitForPage(
        anonymousRequest,
        site,
        (answer) => answer.body.includes(UPSTREAM_TITLE),
        'the branch page was never served'
      );
      const cloned = await api.getFileContent(user.username, `/${checkout}/index.html`);
      expect(page.body, 'the site serves the cloned index.html unchanged').toBe(cloned);
    });

    await test.step('a local edit of the checkout is what the site serves', async () => {
      await api.putFileContents(user.username, `/${checkout}/index.html`, edited);
      expect((await api.gitStatus(user.username)).data.dirty, 'the checkout has the edit').toBe(
        true
      );
      await waitForPage(
        anonymousRequest,
        site,
        (page) => page.body.includes(edited),
        'the edited page was never served'
      );
    });

    const hook = await test.step('create a GitHub deploy hook', async () => {
      const created = await api.createDeployHookRaw(user.username, { provider: 'github' });
      await expectStatus(created, 201, 'POST git/deploy-hook');
      return validateParsedApiResponse(created.body, deployHookCreateResponseSchema).data;
    });

    await test.step('the signed push is queued', async () => {
      const body = JSON.stringify({ ref: 'refs/heads/main', before: head, after: head });
      const push = await anonymousRequest.post(hook.url, {
        data: body,
        headers: {
          'Content-Type': 'application/json',
          'X-GitHub-Event': 'push',
          'X-GitHub-Delivery': randomUUID(),
          'X-Hub-Signature-256': signature(hook.secret!, body),
        },
      });
      await expectStatus(push, 202, 'POST the hook');
      expect(await push.json()).toEqual({ outcome: 'queued' });
    });

    await test.step('the delivery deploys and is recorded so', async () => {
      let outcomes: string[] = [];
      await expect
        .poll(
          async () => {
            const shown = await api.getDeployHookRaw(user.username);
            await expectStatus(shown, 200, 'GET git/deploy-hook');
            const { deliveries } = validateParsedApiResponse(
              shown.body,
              deployHookShowResponseSchema
            ).data;
            outcomes = deliveries.map((delivery) => `${delivery.outcome}:${delivery.result}`);
            return outcomes.length === 1 && !outcomes[0].endsWith(':null');
          },
          { timeout: Timeouts.deploy, intervals: [5_000], message: 'the push never finished' }
        )
        .toBe(true);
      expect(['queued:deployed', 'queued:partial']).toContain(outcomes[0]);
    });

    await test.step('the branch is checked out and served again', async () => {
      const status = await api.gitStatus(user.username);
      expect(status.data.dirty, 'the pull replaced the local edit').toBe(false);
      expect(await headCommit(api, user.username)).toBe(head);
      await waitForPage(
        anonymousRequest,
        site,
        (page) => page.body.includes(UPSTREAM_TITLE) && !page.body.includes(edited),
        'the branch page did not come back after the push'
      );
    });

    await expectStatus(await api.deleteDeployHookRaw(user.username), [200, 204], 'DELETE hook');
  });
});
