<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSiteResolver;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use PHPUnit\Framework\TestCase;

/**
 * WordPress answers a login that is due for its admin e-mail check with a
 * confirmation form. The connection test leaves it unanswered, so it changes
 * nothing on the source; the import still answers it.
 */
class WordpressMigrationAdminEmailCheckTest extends TestCase
{
    private const CONFIRM_URL = 'https://example.com/wp-login.php?action=confirm_admin_email';

    public function test_the_connection_test_logs_in_without_confirming_the_admin_email(): void
    {
        $http = $this->siteDueForTheEmailCheck();

        $answer = (new SourceProbe(fn () => $http, []))->run($this->access());

        $this->assertTrue($answer['ok']);
        $this->assertSame('https://example.com', $answer['url']);
        $this->assertSame(0, $http->count('POST', self::CONFIRM_URL));
        $this->assertSame(1, $http->count('POST', 'https://example.com/wp-login.php'));
        $this->assertSame(1, $http->count('GET', 'https://example.com/wp-admin'));
    }

    public function test_the_import_login_still_confirms_the_admin_email(): void
    {
        $http = $this->siteDueForTheEmailCheck();
        $client = new SourceWordpressClient($http);

        (new SourceSiteResolver($client))->logIn($this->access());

        $this->assertTrue($client->hasAdminSession());
        $this->assertSame(1, $http->count('POST', self::CONFIRM_URL));
    }

    private function siteDueForTheEmailCheck(): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient)
            ->on('GET', 'https://example.com/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/wp-login.php'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com'))
            ->on('POST', 'https://example.com/wp-login.php', new SourceHttpResponse($this->adminEmailCheckPage(), 200))
            ->on('POST', self::CONFIRM_URL, new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/wp-admin', RoutedSourceHttpClient::admin())
            ->on('GET', 'https://example.com/', new SourceHttpResponse('<html><head><meta name="generator" content="WordPress 6.6.2" /></head></html>', 200));
    }

    private function adminEmailCheckPage(): string
    {
        return '<html><body><form class="admin-email-confirm-form" method="post"'
            .' action="https://example.com/wp-login.php?action=confirm_admin_email&amp;wp_lang=en_US">'
            .'<input type="hidden" name="confirm_admin_email_nonce" value="0a1b2c3d4e" />'
            .'<input type="hidden" name="_wp_http_referer" value="/wp-login.php?action=confirm_admin_email" />'
            .'<input type="hidden" name="redirect_to" value="https://example.com/wp-admin/" />'
            .'<input type="submit" name="correct-admin-email" value="The email is correct" />'
            .'</form></body></html>';
    }

    private function access(): SourceAccess
    {
        return new SourceAccess(url: 'https://example.com', username: 'admin', password: 'secret');
    }
}
