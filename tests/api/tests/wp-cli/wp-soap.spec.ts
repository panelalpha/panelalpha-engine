import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { waitForWpCliReady } from '@/helpers/wp-cli-helpers';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';
import { fetchSite } from '@/helpers/webserver-helpers';

const SOAP_PLUGIN_SLUG = 'revolut-gateway-for-woocommerce';

const SOAP_CHECK_PHP = `<?php
header("Content-Type: text/plain");
if (!extension_loaded("soap") || !class_exists("SoapClient")) {
    http_response_code(500);
    echo "SoapClient missing";
} else {
    echo "SOAP_OK";
}
?>`;

/**
 * Several payment gateways need PHP's SOAP extension. It is loaded per PHP
 * build, so it has to hold for both the CLI binary WP-CLI uses and the one the
 * webserver serves requests with — the two are separate binaries.
 */
test('SoapClient is available to WP-CLI and over HTTP', async ({
  api,
  anonymousRequest,
  setupUser,
}) => {
  const reason = await wpCliUnavailableReason(api, setupUser);
  test.skip(Boolean(reason), reason ?? '');

  const installed = await api.executeWpCliCommand(setupUser.username, [
    'plugin',
    'install',
    SOAP_PLUGIN_SLUG,
    wpPath(setupUser),
  ]);
  // 1 means it was already installed.
  expectOneOf(installed.exit_code, [0, 1]);

  const checkPath = `${setupUser.domain}/public_html/soap-check.php`;

  const runCliCheck = () =>
    api.executeWpCliCommand(setupUser.username, [
      'eval',
      'if (!extension_loaded("soap") || !class_exists("SoapClient")) { fwrite(STDERR, "SoapClient missing"); exit(1); } echo "SOAP_OK";',
      wpPath(setupUser),
    ]);

  try {
    let cli = await runCliCheck();

    if (cli.exit_code !== 0) {
      // A container built before the extension was added picks it up on rebuild.
      await api.rebuildUser(setupUser.username);
      await waitForWpCliReady(api, setupUser.username, setupUser.wpPath);
      cli = await runCliCheck();
    }

    expect(cli.exit_code, `SoapClient is not available to the CLI: ${cli.stderr}`).toBe(0);
    expect(cli.stdout).toContain('SOAP_OK');

    await api.putFileContents(setupUser.username, checkPath, SOAP_CHECK_PHP);

    const response = await fetchSite(
      anonymousRequest,
      `https://${setupUser.domain}/soap-check.php?t=${Date.now()}`
    );
    const body = await response.text();

    expect(response.status(), `the HTTP SOAP check answered: ${body}`).toBe(200);
    expect(body).toContain('SOAP_OK');
  } finally {
    await api.removeFile(setupUser.username, checkPath).catch(() => undefined);
  }
});
