<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbeCheck;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\TargetChoice;
use PHPUnit\Framework\TestCase;

/**
 * The connection test answers ok with what it found, or the error_type of the
 * failure, and runs its extra checks only once the source has been found.
 */
class WordpressMigrationProbeTest extends TestCase
{
    public function test_a_wp_admin_source_answers_ok_with_url_login_url_rest_mode_and_version(): void
    {
        $answer = (new SourceProbe(fn () => $this->wordpress(), []))->run($this->wpAdmin('secret'));

        $this->assertSame([
            'ok' => true,
            'url' => 'https://example.com',
            'login_url' => 'https://example.com/wp-login.php',
            'rest_mode' => SourceSite::REST_WP_JSON,
            'wordpress_version' => '6.6.2',
        ], $answer);
    }

    public function test_a_wrong_password_answers_login_failed(): void
    {
        $http = $this->wordpress()->on('GET', 'https://example.com/wp-admin', new SourceHttpResponse('<html>login</html>', 200));

        $answer = (new SourceProbe(fn () => $http, []))->run($this->wpAdmin('wrong'));

        $this->assertFalse($answer['ok']);
        $this->assertSame(SourceCheckFailed::LOGIN_FAILED, $answer['error_type']);
        $this->assertNotSame('', $answer['message']);
    }

    public function test_a_certificate_failure_answers_tls(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', SourceCheckFailed::tls('SSL certificate problem: self-signed certificate'))
            ->on('GET', 'https://example.com', SourceCheckFailed::tls('SSL certificate problem: self-signed certificate'));

        $answer = (new SourceProbe(fn () => $http, []))->run($this->wpAdmin('secret'));

        $this->assertSame(['ok' => false, 'error_type' => 'tls', 'message' => 'SSL certificate problem: self-signed certificate'], $answer);
    }

    public function test_a_manual_plugin_source_answers_unauthorized_for_a_wrong_token(): void
    {
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com', ['wp-migrator/v1']))
            ->on('GET', 'https://example.com/wp-json/wp-migrator/v1/info?migration_id=panelalpha-token-check', RoutedSourceHttpClient::restError(401, 'rest_forbidden'));
        $access = new SourceAccess(url: 'https://example.com', method: WordpressMigration::METHOD_MANUAL_PLUGIN, sourceToken: str_repeat('ab', 32));

        $answer = (new SourceProbe(fn () => $http, []))->run($access);

        $this->assertSame(SourceCheckFailed::UNAUTHORIZED, $answer['error_type']);
    }

    public function test_extra_checks_add_fields_or_refuse_with_their_own_error_type(): void
    {
        $adds = new class implements SourceProbeCheck {
            public function check(SourceAccess $access, SourceSite $site, TargetChoice $choice): array
            {
                return ['domain' => parse_url($site->url, PHP_URL_HOST)];
            }
        };
        $refuses = new class implements SourceProbeCheck {
            public function check(SourceAccess $access, SourceSite $site, TargetChoice $choice): array
            {
                throw new SourceCheckFailed('domain_taken', 'example.com already belongs to a project');
            }
        };

        $added = (new SourceProbe(fn () => $this->wordpress(), [$adds]))->run($this->wpAdmin('secret'));
        $refused = (new SourceProbe(fn () => $this->wordpress(), [$adds, $refuses]))->run($this->wpAdmin('secret'));

        $this->assertSame('example.com', $added['domain']);
        $this->assertSame(['ok' => false, 'error_type' => 'domain_taken', 'message' => 'example.com already belongs to a project'], $refused);
    }

    public function test_every_internal_source_gets_the_same_answer_whatever_listens_there(): void
    {
        $dns = static fn (string $host): array => $host === 'sites-db' ? ['172.20.0.2'] : [];
        $policy = new SourceIpPolicy(new class extends EngineAddresses {
            public function ranges(): array
            {
                return ['93.184.216.40'];
            }
        });
        $probe = new SourceProbe(static fn (SourceAccess $access) => new CurlSourceHttpClient($access->pin(), $dns, $policy), []);

        $answers = array_map(
            static fn (string $url): array => $probe->run(new SourceAccess(url: $url, username: 'admin', password: 'secret')),
            ['http://172.18.0.5:5000/', 'http://10.0.0.1:8006/', 'http://sites-db:3306/', 'https://93.184.216.40/'],
        );

        $refused = ['ok' => false, 'error_type' => SourceCheckFailed::UNREACHABLE, 'message' => SourceFetchGuard::REFUSED];
        $this->assertSame([$refused, $refused, $refused, $refused], $answers);
    }

    private function wordpress(): RoutedSourceHttpClient
    {
        return (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/wp-login.php'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com'))
            ->on('POST', 'https://example.com/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/wp-admin', RoutedSourceHttpClient::admin())
            ->on('GET', 'https://example.com/', new SourceHttpResponse('<html><head><meta name="generator" content="WordPress 6.6.2" /></head></html>', 200));
    }

    private function wpAdmin(string $password): SourceAccess
    {
        return new SourceAccess(url: 'https://example.com', username: 'admin', password: $password);
    }
}
