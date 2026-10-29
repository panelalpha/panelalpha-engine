<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSiteResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use PHPUnit\Framework\TestCase;

/**
 * wp_admin lookup: login page candidates in order, the home read from the
 * login page and confirmed by the REST index, then one login.
 */
class WordpressMigrationWpAdminResolveTest extends TestCase
{
    public function test_a_custom_login_path_resolves_to_the_site_root_not_a_subdirectory(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/secret-login', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/secret-login'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com'))
            ->on('POST', 'https://example.com/secret-login', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/wp-admin', RoutedSourceHttpClient::admin());
        $client = new SourceWordpressClient($http);

        $site = (new SourceSiteResolver($client))->logIn($this->access('https://example.com', 'https://example.com/secret-login'));

        $this->assertSame('https://example.com', $site->url);
        $this->assertSame('https://example.com/secret-login', $site->loginUrl);
        $this->assertSame(SourceSite::REST_WP_JSON, $site->restMode);
        $this->assertSame('', SourceUrl::installSubdirectory($site->url));
        $this->assertTrue($client->hasAdminSession());
        $this->assertSame(1, $http->count('POST', 'https://example.com/secret-login'));
    }

    public function test_a_subdirectory_site_is_read_from_its_login_page_and_falls_back_to_rest_route(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/blog/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com/blog', 'https://example.com/blog/wp-login.php'))
            ->on('GET', 'https://example.com/blog?rest_route=/', RoutedSourceHttpClient::restIndex('https://example.com/blog'))
            ->on('POST', 'https://example.com/blog/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/blog/wp-admin', RoutedSourceHttpClient::admin());

        $site = (new SourceSiteResolver(new SourceWordpressClient($http)))->logIn($this->access('https://example.com/blog'));

        $this->assertSame('https://example.com/blog', $site->url);
        $this->assertSame(SourceSite::REST_QUERY, $site->restMode);
        $this->assertSame('blog', SourceUrl::installSubdirectory($site->url));
    }

    public function test_candidates_with_a_login_url_follow_the_documented_order(): void
    {
        $this->assertSame(
            [
                'https://example.com/blog/secret',
                'https://example.com/blog/wp-login.php',
                'https://example.com/blog/secret/wp-login.php',
                'https://example.com/wp-login.php',
            ],
            SourceSiteResolver::loginCandidates($this->access('https://example.com', 'https://example.com/blog/secret')),
        );
    }

    public function test_candidates_without_a_login_url_start_with_the_sites_own_wp_login(): void
    {
        $this->assertSame(
            [
                'https://example.com/blog/wp-login.php',
                'https://example.com/blog',
                'https://example.com/wp-login.php',
            ],
            SourceSiteResolver::loginCandidates($this->access('https://example.com/blog/')),
        );
    }

    public function test_a_home_the_rest_index_does_not_confirm_is_not_wordpress(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/wp-login.php'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://other.example'));

        $failure = $this->failureOf(fn () => (new SourceSiteResolver(new SourceWordpressClient($http)))->logIn($this->access('https://example.com')));

        $this->assertSame(SourceCheckFailed::NOT_WORDPRESS, $failure->errorType());
        $this->assertSame(0, $http->count('POST', 'https://example.com/wp-login.php'));
    }

    public function test_a_wrong_password_is_login_failed_even_when_other_candidates_are_not_wordpress(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/wp-login.php'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com'))
            ->on('POST', 'https://example.com/wp-login.php', new SourceHttpResponse('<html>wrong</html>', 200))
            ->on('GET', 'https://example.com/wp-admin', new SourceHttpResponse('<html>login form</html>', 200));

        $failure = $this->failureOf(fn () => (new SourceSiteResolver(new SourceWordpressClient($http)))->logIn($this->access('https://example.com')));

        $this->assertSame(SourceCheckFailed::LOGIN_FAILED, $failure->errorType());
        $this->assertSame(1, $http->count('POST', 'https://example.com/wp-login.php'));
    }

    public function test_a_cloudflare_challenge_is_reported_as_cloudflare(): void
    {
        $challenge = new SourceHttpResponse('<html><form id="wsidchk-form"><input id="wsidchk"></form></html>', 403);
        $http = (new RoutedSourceHttpClient())->on('GET', 'https://example.com/wp-login.php', $challenge);

        $failure = $this->failureOf(fn () => (new SourceSiteResolver(new SourceWordpressClient($http)))->logIn($this->access('https://example.com')));

        $this->assertSame(SourceCheckFailed::CLOUDFLARE, $failure->errorType());
    }

    public function test_a_source_that_cannot_be_reached_is_unreachable_and_tls_wins_over_it(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', SourceCheckFailed::tls('certificate has expired'))
            ->on('GET', 'https://example.com', SourceCheckFailed::unreachable('connection refused'));

        $failure = $this->failureOf(fn () => (new SourceSiteResolver(new SourceWordpressClient($http)))->logIn($this->access('https://example.com')));

        $this->assertSame(SourceCheckFailed::TLS, $failure->errorType());
        $this->assertStringContainsString('certificate has expired', $failure->getMessage());
    }

    private function access(string $url, ?string $loginUrl = null): SourceAccess
    {
        return new SourceAccess(url: $url, loginUrl: $loginUrl, username: 'admin', password: 'secret');
    }

    private function failureOf(callable $resolve): SourceCheckFailed
    {
        try {
            $resolve();
        } catch (SourceCheckFailed $e) {
            return $e;
        }

        $this->fail('Expected the lookup to fail');
    }
}
