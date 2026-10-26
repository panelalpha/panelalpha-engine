<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallSourcePluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use App\Models\WordpressMigration;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use ZipArchive;

/**
 * Fixture HTML wp-admin: successful login extracts the nonce; a bad pair
 * fails the step and does not call remote-sync.
 */
class WordpressMigrationLoginTest extends WordpressMigrationTestCase
{
    public function test_successful_login_extracts_ajax_nonce(): void
    {
        $http = new FakeSourceHttpClient();
        $http->pushHandler(function (string $method, string $url): SourceHttpResponse {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('wp-login.php', $url);

            return new SourceHttpResponse('<html>login ok</html>', 200);
        });
        $http->pushHandler(function (string $method, string $url): SourceHttpResponse {
            $this->assertSame('GET', $method);
            $this->assertStringContainsString('/wp-admin', $url);

            return new SourceHttpResponse(
                '<html><script>var settings={"_ajax_nonce":"abc123nonce"};</script></html>',
                200,
            );
        });

        $client = new SourceWordpressClient($http);
        $nonce = $client->logIn(new SourceSite('https://example.com/blog'), 'admin', 'secret-password');

        $this->assertSame('abc123nonce', $nonce);
        $this->assertSame('https://example.com/blog', $client->homeUrl());
        $this->assertTrue($client->hasAdminSession());
    }

    public function test_bad_credentials_fail_step_without_remote_sync(): void
    {
        Queue::fake();

        $http = new FakeSourceHttpClient();
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse('<html>login form</html>', 200));
        $http->pushHandler(fn (): SourceHttpResponse => new SourceHttpResponse(
            '<html><body>Invalid credentials</body></html>',
            200,
        ));

        $zip = $this->fakeZip();

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'wrong-password',
        );
        $migration->storeResolvedSource('https://example.com', 'https://example.com/wp-login.php', SourceSite::REST_WP_JSON);

        $step = new InstallSourcePluginStep(new SourceConnection(fn () => $http), $zip);

        try {
            $step->run($migration);
            $this->fail('Expected login failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('login failed', strtolower($e->getMessage()));
        }

        $this->assertFalse($http->calledUrlContaining('remote-sync'));
        $this->assertFalse($http->calledUrlContaining('wp-migrator'));

        $migration->refresh();
        $stepRow = collect($migration->steps)->firstWhere('name', WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $stepRow['status']);
    }

    private function fakeZip(): WordpressMigratorZip
    {
        return new class extends WordpressMigratorZip {
            public function installZipFor(int $migrationId, ?string $releaseUrl = null): string
            {
                $dir = sys_get_temp_dir() . '/wp-mig-login-zip-' . bin2hex(random_bytes(4));
                mkdir($dir, 0700, true);
                $path = $dir . '/wordpress-migrator-install.zip';
                $zip = new ZipArchive();
                $zip->open($path, ZipArchive::CREATE);
                $zip->addFromString('wordpress-migrator/wordpress-migrator.php', '<?php');
                $zip->close();

                return $path;
            }
        };
    }
}
