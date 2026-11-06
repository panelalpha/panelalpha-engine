import { expect, test } from '@/fixtures/test-options';
import type { APIRequestContext } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';

interface RotatedToken {
  token: string;
  token_id: number;
  abilities: string[];
  expires_at: string | null;
}

const TOKEN_LINE = /^\d+\|\S+$/;

function as(request: APIRequestContext, token: string) {
  const headers = { Authorization: `Bearer ${token}` };
  return {
    info: () => request.get('system/info', { headers }),
    rotate: () => request.post('tokens/current/rotate', { headers }),
    revoke: () => request.delete('tokens/current', { headers }),
  };
}

/** `tokens/current` acts only on its caller, so every call uses a token minted for the test. */
test.describe('the calling token', () => {
  test('rotate issues a working successor, and revoke ends a token at once', async ({
    anonymousRequest,
    hostExec,
  }) => {
    skipUnless(hostExec, 'pae-artisan is not reachable from this runner.');
    const minted = await hostExec.pae(['api:token:create', uniqueId('rotate-'), '--short']);
    expect(minted.exitCode, minted.stderr).toBe(0);
    const first = minted.stdout
      .split('\n')
      .map((line) => line.trim())
      .find((line) => TOKEN_LINE.test(line));
    skipUnless(first, 'api:token:create --short did not print "<id>|<token>".');

    const original = as(anonymousRequest, first);
    let successor: RotatedToken | undefined;

    try {
      await expectStatus(await original.info(), 200, 'the minted token works');

      successor = await test.step('rotate answers a new token that works', async () => {
        const response = await original.rotate();
        await expectStatus(response, 200, 'POST tokens/current/rotate');
        const { data } = (await response.json()) as { data: RotatedToken };
        expect(data.token).toMatch(TOKEN_LINE);
        expect(data.token).not.toBe(first);
        expect(data.token.startsWith(`${data.token_id}|`)).toBe(true);
        await expectStatus(await as(anonymousRequest, data.token).info(), 200, 'the successor');
        return data;
      });

      await test.step('a retried rotate answers the same successor', async () => {
        const response = await original.rotate();
        await expectStatus(response, 200, 'second rotate with the old token');
        const { data } = (await response.json()) as { data: RotatedToken };
        expect(data.token_id).toBe(successor!.token_id);
        expect(data.token).toBe(successor!.token);
      });

      await test.step('the rotated token keeps working for its grace period', async () => {
        await expectStatus(await original.info(), 200, 'the old token inside the grace');
      });

      await test.step('revoking the successor ends it and only it', async () => {
        const next = as(anonymousRequest, successor!.token);
        const revoked = await next.revoke();
        await expectStatus(revoked, 204);
        expect(await next.info().then((r) => r.status())).toBe(401);
        await expectStatus(await original.info(), 200, 'the old token is a different token');
      });

      await test.step('revoking the rotated token ends its grace', async () => {
        await expectStatus(await original.revoke(), 204, 'revoke the rotated token');
        expect(await original.info().then((r) => r.status())).toBe(401);
      });
    } finally {
      for (const token of [first, successor?.token]) {
        if (token !== undefined) {
          await as(anonymousRequest, token)
            .revoke()
            .catch(() => undefined);
        }
      }
    }
  });

  test('without a token there is nothing to rotate or revoke', async ({ anonymousRequest }) => {
    await expectStatus(await anonymousRequest.post('tokens/current/rotate'), 401, 'rotate');
    await expectStatus(await anonymousRequest.delete('tokens/current'), 401, 'revoke');
  });
});
