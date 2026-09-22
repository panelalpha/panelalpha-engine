import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';
import { skipUnless } from '@/helpers/test-helpers';
import { getWebserverInfo, getWebserverRestartReadyMs } from '@/helpers/webserver-helpers';
import { McpSession, mcpResultText } from '@/helpers/mcp-helpers';
import { customIniSettingsResponseSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

const PROBE = "<?php echo 'MEMORY:' . ini_get('memory_limit'); ?>";
const DISTINCT_LIMIT = '137M';

function asIniMap(value: unknown): Record<string, string> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }
  const out: Record<string, string> = {};
  for (const [key, entry] of Object.entries(value)) {
    if (typeof entry === 'string' || typeof entry === 'number' || typeof entry === 'boolean') {
      out[key] = String(entry);
    }
  }
  return out;
}

test.describe('PHP custom INI settings', () => {
  test('account-level INI can be read, written and is served over HTTP', async ({
    api,
    anonymousRequest,
    authedRequest,
    setupUser,
  }) => {
    const version = (await api.getDomainPhpVersion(setupUser.domain)).data;
    skipUnless(version, 'the setup domain has no PHP version.');

    const listed = await authedRequest.get(
      `projects/${setupUser.username}/php/custom-ini-settings?php_version=${encodeURIComponent(version)}`
    );
    expect(listed.ok(), `GET custom-ini-settings answered HTTP ${listed.status()}`).toBe(true);
    const original = asIniMap(
      validateParsedApiResponse(await listed.json(), customIniSettingsResponseSchema).data
    );

    const wanted = original.memory_limit === DISTINCT_LIMIT ? '139M' : DISTINCT_LIMIT;
    const script = `ini-probe-${uniqueId()}.php`;
    const scriptPath = `${getDomainBasePath(setupUser.domain)}/${script}`;

    try {
      await api.setCustomIniSettings(setupUser.username, version, {
        ...original,
        memory_limit: wanted,
      });

      const after = validateParsedApiResponse(
        await api.getCustomIniSettings(setupUser.username, version),
        customIniSettingsResponseSchema
      );
      expect(String(after.data.memory_limit), 'GET did not echo the INI the PUT just wrote').toBe(
        wanted
      );

      await api.putFileContents(setupUser.username, scriptPath, PROBE);

      const { slug } = await getWebserverInfo(api);
      // custom.ini is read when the FPM master starts, not on the next request.
      await expect
        .poll(
          async () => {
            const response = await anonymousRequest.get(
              `https://${setupUser.domain}/${script}?t=${Date.now()}`,
              { ignoreHTTPSErrors: true }
            );
            const body = await response.text();
            if (response.ok() && body.includes(`MEMORY:${wanted}`)) {
              return 'served';
            }
            return `HTTP ${response.status()} ${body.slice(0, 180).replace(/\s+/g, ' ')}`;
          },
          {
            timeout: getWebserverRestartReadyMs(slug),
            intervals: [2_000],
            message: `${setupUser.domain} never served memory_limit=${wanted}`,
          }
        )
        .toBe('served');
    } finally {
      await api.setCustomIniSettings(setupUser.username, version, original).catch(() => undefined);
      await api.removeFile(setupUser.username, scriptPath).catch(() => undefined);
    }
  });

  test('an unknown PHP version is refused', async ({ authedRequest, setupUser }) => {
    const response = await authedRequest.get(
      `projects/${setupUser.username}/php/custom-ini-settings?php_version=1.0`
    );
    expectOneOf(response.status(), [400, 422], 'php_version=1.0 should not be accepted');
  });

  test('php_ini_get and php_ini_set work over MCP', async ({
    api,
    anonymousRequest,
    authedRequest,
    settings,
    setupUser,
  }) => {
    const version = (await api.getDomainPhpVersion(setupUser.domain)).data;
    skipUnless(version, 'the setup domain has no PHP version.');

    const listed = await authedRequest.get(
      `projects/${setupUser.username}/php/custom-ini-settings?php_version=${encodeURIComponent(version)}`
    );
    expect(listed.ok(), `GET custom-ini-settings answered HTTP ${listed.status()}`).toBe(true);
    const original = asIniMap(
      validateParsedApiResponse(await listed.json(), customIniSettingsResponseSchema).data
    );
    const wanted = original.memory_limit === DISTINCT_LIMIT ? '139M' : DISTINCT_LIMIT;

    const created = await api.createMcpToken(uniqueId('mcp-ini-'));
    const token = created.data.plain_text_token;
    skipUnless(token, 'createMcpToken did not return a plaintext token.');

    try {
      const session = await McpSession.open(anonymousRequest, settings.apiBaseUrl, token);
      const tools = new Set((await session.listTools()).map((tool) => tool.name));
      skipUnless(
        tools.has('php_ini_get') && tools.has('php_ini_set'),
        'this engine does not expose php_ini_get / php_ini_set.'
      );

      await session.callOk('php_ini_set', {
        name: setupUser.username,
        php_version: version,
        settings: { ...original, memory_limit: wanted },
      });

      const got = await session.callOk('php_ini_get', {
        name: setupUser.username,
        php_version: version,
      });
      expect(mcpResultText(got), 'php_ini_get did not mention the value just set').toContain(
        wanted
      );
    } finally {
      await api.setCustomIniSettings(setupUser.username, version, original).catch(() => undefined);
      await api.deleteMcpTokenSafe(created.data.id);
    }
  });
});
