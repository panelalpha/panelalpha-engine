<?php

namespace Tests\Unit\WordpressMigration;

use App\Http\Controllers\WordpressMigrationController;
use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use ZipArchive;

/**
 * The method, test and plugin-package API: manual_plugin starts with a token
 * and no credentials, the test creates nothing, source_ip is refused unless
 * public, and the plugin package downloads from a signed link.
 */
class WordpressMigrationMethodsHttpTest extends WordpressMigrationTestCase
{
    use WithTargetTables;

    private const TOKEN = 'a3f1c2d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTargetTables();
        $this->withoutMiddleware();
        $this->app->instance(
            WordpressMigrationStoreRequest::SOURCE_RESOLVER,
            static fn (string $host): array => $host === 'example.com' ? ['93.184.216.34'] : [$host],
        );
        $this->app->instance(EngineAddresses::class, new class extends EngineAddresses {
            public function ranges(): array
            {
                return ['93.184.216.40'];
            }
        });
    }

    protected function tearDown(): void
    {
        $this->dropTargetTables();
        parent::tearDown();
    }

    public function test_manual_plugin_starts_with_a_token_and_no_credentials(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com',
            'method' => 'manual_plugin',
            'source_token' => self::TOKEN,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('method', 'manual_plugin');
        $response->assertJsonPath('username', null);
        $names = array_column($response->json('steps'), 'name');
        $this->assertContains(WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN, $names);
        $this->assertNotContains(WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN, $names);
        $this->assertStringNotContainsString(self::TOKEN, (string) $response->getContent());
        $this->assertSame(self::TOKEN, WordpressMigration::query()->sole()->secretsForWorker()['source_token']);
        Queue::assertPushed(MigrateWordpress::class, 1);
    }

    public function test_manual_plugin_without_a_token_and_wp_admin_without_a_password_are_refused(): void
    {
        $this->postJson('/api/wordpress/migrations', ['url' => 'https://example.com', 'method' => 'manual_plugin'])
            ->assertStatus(422)->assertJsonValidationErrors('source_token');
        $this->postJson('/api/wordpress/migrations', ['url' => 'https://example.com', 'method' => 'manual_plugin', 'source_token' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('source_token');
        $this->postJson('/api/wordpress/migrations', ['url' => 'https://example.com', 'username' => 'admin'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/wordpress/migrations', ['url' => 'https://example.com', 'method' => 'ftp'])
            ->assertStatus(422)->assertJsonValidationErrors('method');

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_wp_admin_start_keeps_login_url_and_source_ip(): void
    {
        Queue::fake();

        $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com',
            'login_url' => 'https://example.com/secret-login',
            'username' => 'admin',
            'password' => 'secret',
            'source_ip' => '93.184.216.34',
        ])->assertStatus(202)
            ->assertJsonPath('method', 'wp_admin')
            ->assertJsonPath('login_url', 'https://example.com/secret-login')
            ->assertJsonPath('source_ip', '93.184.216.34');
    }

    public function test_source_ip_that_is_not_public_or_is_the_engines_own_is_refused(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '172.17.0.2', '169.254.169.254', '::1', '93.184.216.40'] as $ip) {
            $this->postJson('/api/wordpress/migrations', [
                'url' => 'https://example.com',
                'username' => 'admin',
                'password' => 'secret',
                'source_ip' => $ip,
            ])->assertStatus(422)->assertJsonValidationErrors('source_ip');
        }

        $this->assertSame(0, WordpressMigration::query()->count());
    }

    public function test_with_source_ip_the_hosts_own_dns_is_not_consulted(): void
    {
        Queue::fake();
        $this->app->instance(WordpressMigrationStoreRequest::SOURCE_RESOLVER, static fn (): array => ['127.0.0.1']);

        $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'secret',
        ])->assertStatus(422);
        $this->postJson('/api/wordpress/migrations', [
            'url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'secret',
            'source_ip' => '93.184.216.34',
        ])->assertStatus(202);
    }

    public function test_the_connection_test_answers_200_and_creates_nothing(): void
    {
        Queue::fake();
        $http = (new RoutedSourceHttpClient())
            ->on('GET', 'https://example.com/wp-login.php', RoutedSourceHttpClient::loginPage('https://example.com', 'https://example.com/wp-login.php'))
            ->on('GET', 'https://example.com/wp-json/', RoutedSourceHttpClient::restIndex('https://example.com'))
            ->on('POST', 'https://example.com/wp-login.php', new SourceHttpResponse('ok', 200))
            ->on('GET', 'https://example.com/wp-admin', new SourceHttpResponse('<html>login</html>', 200));
        $this->app->instance(SourceProbe::class, new SourceProbe(fn () => $http));

        $this->postJson('/api/wordpress/migrations/test', [
            'url' => 'https://example.com',
            'username' => 'admin',
            'password' => 'wrong',
        ])->assertOk()->assertJsonPath('ok', false)->assertJsonPath('error_type', 'login_failed');

        $this->postJson('/api/wordpress/migrations/test', ['url' => 'https://example.com'])->assertStatus(422);

        $this->assertSame(0, WordpressMigration::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_plugin_package_is_downloaded_from_its_signed_link_with_the_token_inside(): void
    {
        $this->app->instance(WordpressMigratorZip::class, $this->fixtureZip());

        $package = $this->postJson('/api/wordpress/migrations/plugin-package')->assertOk();
        $token = $package->json('token');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $url = $package->json('download_url');
        $this->assertStringNotContainsString($token, $url);

        $download = $this->get($url);
        $download->assertOk();
        $config = $this->configInZip($download->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString("'token' => '" . $token . "'", $config);
    }

    public function test_the_download_link_is_signed_instead_of_authenticated(): void
    {
        $middleware = Route::getRoutes()->getByName(WordpressMigrationController::PLUGIN_PACKAGE_ROUTE)?->gatherMiddleware() ?? [];

        $this->assertContains('signed', $middleware);
        $this->assertNotContains('auth:api', $middleware);
    }

    private function fixtureZip(): WordpressMigratorZip
    {
        return new class extends WordpressMigratorZip {
            public function downloadAndRepack(?string $releaseUrl = null): string
            {
                $source = sys_get_temp_dir() . '/wp-migrator-fixture-' . bin2hex(random_bytes(4)) . '.zip';
                $zip = new ZipArchive();
                $zip->open($source, ZipArchive::CREATE);
                $zip->addFromString('panelalpha-wordpress-migrator-1.2.0/wordpress-migrator.php', "<?php\n");
                $zip->addFromString('panelalpha-wordpress-migrator-1.2.0/config.php', "<?php\n\nreturn [\n    'token' => '',\n    'mode' => 'source',\n];\n");
                $zip->close();

                $workDir = sys_get_temp_dir() . '/wp-migrator-' . bin2hex(random_bytes(4));
                mkdir($workDir, 0700, true);
                try {
                    return $this->repackFromTagArchive($source, $workDir);
                } finally {
                    @unlink($source);
                }
            }
        };
    }

    private function configInZip(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $config = (string) $zip->getFromName('wordpress-migrator/config.php');
        $zip->close();

        return $config;
    }
}
