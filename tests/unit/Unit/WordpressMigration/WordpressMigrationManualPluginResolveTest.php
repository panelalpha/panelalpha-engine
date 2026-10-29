<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSiteResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use PHPUnit\Framework\TestCase;

/**
 * manual_plugin lookup: the wp-migrator/v1 namespace at the URL, its parent,
 * then the home a login page names; the token is checked by the plugin's own
 * /info permission check, with no wp-admin session and nothing posted.
 */
class WordpressMigrationManualPluginResolveTest extends TestCase
{
    private const TOKEN = 'a3f1c2d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const INFO = 'https://example.com/blog/wp-json/wp-migrator/v1/info?migration_id=panelalpha-token-check';

    public function test_the_plugin_is_found_at_the_url_and_accepts_the_token(): void
    {
        $http = $this->siteWithPlugin()
            ->on('GET', self::INFO, fn (array $headers) => ($headers['X-Migrator-Token'] ?? null) === self::TOKEN
                ? RoutedSourceHttpClient::restError(404, 'not_found')
                : RoutedSourceHttpClient::restError(401, 'rest_forbidden'));
        $client = new SourceWordpressClient($http);

        $site = (new SourceSiteResolver($client))->findPlugin($this->access('https://example.com/blog'));

        $this->assertSame('https://example.com/blog', $site->url);
        $this->assertNull($site->loginUrl);
        $this->assertSame(SourceSite::REST_WP_JSON, $site->restMode);
        $this->assertFalse($client->hasAdminSession());
        foreach ($http->calls as $call) {
            $this->assertSame('GET', $call['method']);
        }
    }

    public function test_a_wrong_token_is_unauthorized(): void
    {
        $http = $this->siteWithPlugin()->on('GET', self::INFO, RoutedSourceHttpClient::restError(401, 'rest_forbidden'));

        $failure = $this->failureOf($http, 'https://example.com/blog');

        $this->assertSame(SourceCheckFailed::UNAUTHORIZED, $failure->errorType());
    }

    public function test_a_site_without_the_plugin_is_plugin_not_found(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/blog/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com/blog'));

        $failure = $this->failureOf($http, 'https://example.com/blog');

        $this->assertSame(SourceCheckFailed::PLUGIN_NOT_FOUND, $failure->errorType());
    }

    public function test_a_post_url_finds_the_plugin_through_its_parent(): void
    {
        $http = $this->siteWithPlugin()
            ->on('GET', self::INFO, RoutedSourceHttpClient::restError(404, 'not_found'));

        $site = (new SourceSiteResolver(new SourceWordpressClient($http)))->findPlugin($this->access('https://example.com/blog/hello-world'));

        $this->assertSame('https://example.com/blog', $site->url);
    }

    public function test_the_home_from_the_login_page_is_tried_last(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/secret-login', RoutedSourceHttpClient::loginPage('https://example.com/blog', 'https://example.com/secret-login'))
            ->on('GET', 'https://example.com/blog/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com/blog', ['wp/v2', 'wp-migrator/v1']))
            ->on('GET', self::INFO, RoutedSourceHttpClient::restError(404, 'not_found'));

        $site = (new SourceSiteResolver(new SourceWordpressClient($http)))->findPlugin($this->access('https://example.com/secret-login'));

        $this->assertSame('https://example.com/blog', $site->url);
        $this->assertSame('https://example.com/secret-login', $site->loginUrl);
    }

    private function siteWithPlugin(): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/blog/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com/blog', ['wp/v2', 'wp-migrator/v1']));
    }

    private function access(string $url): SourceAccess
    {
        return new SourceAccess(url: $url, method: WordpressMigration::METHOD_MANUAL_PLUGIN, sourceToken: self::TOKEN);
    }

    private function failureOf(RoutedSourceHttpClient $http, string $url): SourceCheckFailed
    {
        try {
            (new SourceSiteResolver(new SourceWordpressClient($http)))->findPlugin($this->access($url));
        } catch (SourceCheckFailed $e) {
            return $e;
        }

        $this->fail('Expected the lookup to fail');
    }
}
