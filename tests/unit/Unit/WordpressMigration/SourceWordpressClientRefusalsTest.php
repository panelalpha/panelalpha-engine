<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeSourceHttpClient;

/**
 * Where the source client stops: a blocked login, a REST index or plugin that does not answer, a wp-admin that
 * refuses the upload, a plugin that stays or is missing, and a transfer the plugin refuses.
 */
class SourceWordpressClientRefusalsTest extends TestCase
{
    private const HOME = 'https://shop.example.com';

    private const PLUGIN_LINK = '<a href="plugins.php?action=activate&amp;plugin=panelalpha-migrator%2Fpanelalpha-migrator.php&amp;_wpnonce=a2">Activate</a>';

    private FakeSourceHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeSourceHttpClient();
    }

    public function test_a_login_cloudflare_answers_is_a_cloudflare_block(): void
    {
        $this->answer('<html><body><form id="wsidchk-form"><input id="wsidchk" name="v"></form></body></html>');

        try {
            (new SourceWordpressClient($this->http))->logIn(new SourceSite(self::HOME), 'admin', 'secret');
            $this->fail('a Cloudflare challenge must stop the login');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::CLOUDFLARE, $e->errorType());
        }

        $this->assertSame([['POST', self::HOME.'/wp-login.php']], $this->requests());
    }

    public function test_an_admin_email_check_without_its_form_fields_is_not_answered(): void
    {
        $this->answer('<html><form action="'.self::HOME.'/wp-login.php?action=confirm_admin_email" method="post"></form></html>');
        $this->answer('');
        $this->answer('<html><script>var s={"_ajax_nonce":"n0nce42"};</script></html>');
        $client = new SourceWordpressClient($this->http);

        $nonce = $client->logIn(new SourceSite(self::HOME), 'admin', 'secret');

        $this->assertSame('n0nce42', $nonce);
        $this->assertTrue($client->hasAdminSession());
        $this->assertSame([
            ['POST', self::HOME.'/wp-login.php'],
            ['POST', self::HOME.'/wp-login.php'],
            ['GET', self::HOME.'/wp-admin'],
        ], $this->requests());
    }

    public function test_a_base_that_is_not_a_rest_index_has_none(): void
    {
        $this->answer('{"code":"rest_no_route"}', 404);

        $this->assertNull((new SourceWordpressClient($this->http))->restIndex(self::HOME, SourceSite::REST_WP_JSON));
    }

    public function test_a_base_answering_with_something_other_than_json_has_none(): void
    {
        $this->answer('<html>a theme page</html>');

        $this->assertNull((new SourceWordpressClient($this->http))->restIndex(self::HOME, SourceSite::REST_QUERY));
    }

    public function test_a_plugin_check_that_answers_ok_accepts_the_token(): void
    {
        $this->answer('{"migration":null}');
        $client = $this->clientFor();

        $client->assertPluginAcceptsToken('t0ken');

        $this->assertSame(['X-Migrator-Token' => 't0ken'], $this->http->calls[0]['headers']);
    }

    public function test_a_plugin_check_without_a_rest_error_is_plugin_not_found(): void
    {
        $this->answer('<html>Not Found</html>', 404);

        try {
            $this->clientFor()->assertPluginAcceptsToken('t0ken');
            $this->fail('a page that is not the plugin must not pass the token check');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::PLUGIN_NOT_FOUND, $e->errorType());
        }
    }

    public function test_a_plugin_check_answered_by_a_missing_route_is_plugin_not_found(): void
    {
        $this->answer('{"code":"rest_no_route","message":"No route"}', 404);

        try {
            $this->clientFor()->assertPluginAcceptsToken('t0ken');
            $this->fail('a missing route must not pass the token check');
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::PLUGIN_NOT_FOUND, $e->errorType());
        }
    }

    public function test_an_upload_page_without_a_nonce_stops_the_install(): void
    {
        $this->answer('<html></html>');
        $this->answer('<html></html>');
        $this->answer('<html><form></form></html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not retrieve nonce for plugin upload. Make sure you are logged in.');

        $this->loggedInClient()->installPluginFromZip('/nonexistent/wordpress-migrator.zip');
    }

    public function test_a_missing_zip_stops_the_install_before_the_upload(): void
    {
        $this->answer('<html></html>');
        $this->answer('<html></html>');
        $this->answer('<html><input name="_wpnonce" value="u9"></html>');

        try {
            $this->loggedInClient()->installPluginFromZip('/nonexistent/wordpress-migrator.zip');
            $this->fail('a missing zip must stop the install');
        } catch (RuntimeException $e) {
            $this->assertSame('Migrator install zip is missing: /nonexistent/wordpress-migrator.zip', $e->getMessage());
        }

        $this->assertCount(3, $this->http->calls);
    }

    public function test_a_newer_plugin_wp_admin_still_lists_stops_the_install(): void
    {
        $list = '<html>'.self::PLUGIN_LINK.'<script>var s={"_ajax_nonce":"aj4x"};</script></html>';
        $this->answer($list);
        $this->answer('1');
        $this->answer($list);

        try {
            $this->loggedInClient()->installPluginFromZip('/nonexistent/wordpress-migrator.zip');
            $this->fail('a panelalpha-migrator that stays must stop the install');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('The source site has a panelalpha-migrator plugin. The Engine removes it', $e->getMessage());
        }

        $this->assertSame('action=delete-plugin&plugin=panelalpha-migrator%2Fpanelalpha-migrator.php&slug=panelalpha-migrator&_ajax_nonce=aj4x', $this->http->calls[1]['body']);
    }

    public function test_activating_a_plugin_the_source_does_not_have_fails(): void
    {
        $this->answer('<html></html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Plugin "wordpress-migrator" is not installed on the source site');

        $this->loggedInClient()->activatePlugin();
    }

    public function test_wp_admin_calls_need_a_session(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source WordPress client is not logged in');

        $this->clientFor()->hasPlugin();
    }

    public function test_transfer_calls_need_a_resolved_site(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source WordPress site is not resolved');

        (new SourceWordpressClient($this->http))->processBatch('mig_1');
    }

    public function test_a_refused_htaccess_transfer_fails_with_the_plugins_message(): void
    {
        $this->answer('{"success":false,"message":"No .htaccess on the source"}');
        $client = $this->clientFor();
        $client->useSourceToken('t0ken');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No .htaccess on the source');

        $client->transferHtaccess('mig_1');
    }

    public function test_a_refused_htaccess_transfer_without_a_message_says_so(): void
    {
        $this->answer('{"success":false}');
        $client = $this->clientFor();
        $client->useSourceToken('t0ken');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to transfer .htaccess file.');

        $client->transferHtaccess('mig_1');
    }

    public function test_a_plugin_error_without_a_message_is_an_unknown_error(): void
    {
        $this->answer('{"code":"internal"}', 500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown error from migrator plugin');

        $this->clientFor()->stopMigration('mig_1', 't0ken');
    }

    private function clientFor(): SourceWordpressClient
    {
        $client = new SourceWordpressClient($this->http);
        $client->useSite(new SourceSite(self::HOME));

        return $client;
    }

    private function loggedInClient(): SourceWordpressClient
    {
        $client = $this->clientFor();
        (new \ReflectionProperty(SourceWordpressClient::class, 'adminSession'))->setValue($client, true);

        return $client;
    }

    private function answer(string $body, int $status = 200): void
    {
        $this->http->pushHandler(static fn (): SourceHttpResponse => new SourceHttpResponse($body, $status));
    }

    /** @return list<array{0: string, 1: string}> */
    private function requests(): array
    {
        return array_map(static fn (array $call): array => [$call['method'], $call['url']], $this->http->calls);
    }
}
