<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use Tests\Support\TestNetwork;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\System\Project\Dind\HostCompile;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Strategy\PhpStrategy;
use Tests\TestCase;

/**
 * Pins what {@see PhpStrategy::apply()} builds, writes and logs: the base image it asks for, the
 * entrypoint stages, the host build, the account files and the compose file, per database answer.
 */
class PhpStrategyApplyTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_a_plain_php_app_with_no_account_ids_and_no_database(): void
    {
        $this->putFile('composer.json', '{"require":{"php":"^8.3"}}');
        $this->putFile('index.php', "<?php echo 1;\n");

        $this->apply([], false, DetectProjectStrategy::detect($this->dir), ['tag' => 'panelalpha/php:8.3-test', 'baked' => []]);

        $this->assertMatchesGolden('php-plain');
    }

    public function test_a_laravel_app_asking_for_mysql_gets_the_sidecar_the_wait_and_the_account_files(): void
    {
        $this->putFile('app/composer.json', '{"require":{"php":"^8.2","laravel/framework":"^11","ext-imagick":"*"}}');
        $this->putFile('app/artisan', "<?php\n");
        $this->putFile('app/public/index.php', "<?php\n");
        $this->putFile('app/config/database.php', "<?php return ['connections' => ['sqlite' => ['database' => database_path('database.sqlite')]]];\n");
        $this->putFile('.env', "DB_CONNECTION=mysql\nDB_HOST=".TestNetwork::LOOPBACK."\nDB_DATABASE=app\nDB_USERNAME=app\n");
        $decision = DetectProjectStrategy::detect($this->dir.'/app');
        $decision['app_root'] = '/app/';
        $decision['system_packages'] = ['libvips-dev'];
        $decision['frontend_build'] = 'npm run prod';

        $this->apply(
            ['UID' => 1001, 'GID' => 1001, 'home_dir' => $this->dir],
            true,
            $decision,
            ['tag' => 'panelalpha/php:8.3-x-imagick-test', 'baked' => ['imagick']]
        );

        $this->assertMatchesGolden('php-laravel-sidecar');
    }

    public function test_a_shipped_mysql_service_is_used_rather_than_a_second_one(): void
    {
        $this->putFile('composer.json', '{"require":{"php":"^8.2","laravel/framework":"^11"}}');
        $this->putFile('artisan', "<?php\n");
        $this->putFile('.env', "DB_CONNECTION=mysql\nDB_HOST=db\nDB_DATABASE=app\n");

        $this->apply(
            ['UID' => 1001, 'GID' => 1001, 'home_dir' => $this->dir],
            true,
            DetectProjectStrategy::detect($this->dir),
            ['tag' => null, 'baked' => []],
            ['services' => ['db' => ['image' => 'mariadb:11']], 'volumes' => ['dbdata' => []], 'env' => []]
        );

        $this->assertMatchesGolden('php-shipped-mysql');
    }

    public function test_a_symfony_skeleton_found_through_its_installed_tree_and_frontend_off(): void
    {
        $this->putFile('composer.json', '{"require":{"php":"^8.3","thelia/core":"^2"}}');
        $this->putFile('vendor/composer/installed.json', '{"packages":[{"name":"symfony/framework-bundle"}]}');
        $this->putFile('public/index.php', "<?php\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['frontend_build'] = false;
        $decision['platform'] = '';

        $this->apply(['UID' => 1001, 'GID' => 1001], false, $decision, ['tag' => 'panelalpha/php:8.3-test', 'baked' => []]);

        $this->assertMatchesGolden('php-symfony-installed');
    }

    public function test_the_account_database_is_pinned_rather_than_given_a_sidecar(): void
    {
        config()->set('env.USERS_DB_HOST', 'localhost');

        $this->record('decision', $this->decision(['host' => 'localhost', 'database' => 'acme_app']));

        $this->assertMatchesGolden('php-decision-account-db');
    }

    public function test_an_account_database_that_resolves_to_nothing_adds_no_pin(): void
    {
        config()->set('env.USERS_DB_HOST', TestNetwork::PRIVATE_172);

        $this->record('decision', $this->decision(['host' => TestNetwork::PRIVATE_172, 'database' => 'acme_app']));

        $this->assertMatchesGolden('php-decision-account-db-unpinned');
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $decision
     * @param  array{tag: ?string, baked: list<string>}  $base
     * @param  array{services: array<string, mixed>, volumes: array<string, mixed>, env: array<string, string>}|null  $sidecars
     */
    private function apply(array $details, bool $artisan, array $decision, array $base, ?array $sidecars = null): void
    {
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensurePhpBaseImage')->willReturnCallback(function (...$args) use ($base): array {
            $this->record('ensurePhpBaseImage', $args);

            return $base;
        });
        $host = $this->createStub(HostCompile::class);
        $host->method('runPhpBuild')->willReturnCallback(function (...$args): void {
            $this->record('runPhpBuild', $args);
        });
        $host->method('runForPhp')->willReturnCallback(function (...$args): bool {
            $this->record('runForPhp', $args);

            return false;
        });

        (new PhpStrategy($this->harnessDind($details, $sidecars, true, $inner, $host)))
            ->apply($this->dir, '1001:1001', $artisan, null, $decision);
    }

    /**
     * @param  array<string, mixed>  $db
     * @return array<string, mixed>
     */
    private function decision(array $db): array
    {
        return (new \ReflectionMethod(PhpStrategy::class, 'decision'))->invoke(
            new PhpStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001], null, true)),
            $db,
            false,
            'https://acme.example.test',
            false,
            true,
            new PhpBuild(composerJson: '{}', artisan: false, baseImage: 'panelalpha/php:8.3'),
            ['env' => ['FROM_MANIFEST' => '1'], 'docroot' => 'web']
        );
    }
}
