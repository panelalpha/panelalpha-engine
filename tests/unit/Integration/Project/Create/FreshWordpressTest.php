<?php

namespace Tests\Integration\Project\Create;

use App\Lib\Project\ClassicAppType;
use App\Lib\Project\Create\FreshWordpress;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressInstaller;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A fresh WordPress on a php_hosting project's main domain: core downloaded into the document root, its own database
 * and config, then installed for the domain with the admin the caller chose.
 */
class FreshWordpressTest extends TestCase
{
    use InMemoryDatabase;

    private const SITE = [
        'title' => 'Shop',
        'admin_user' => 'boss',
        'admin_email' => 'boss@example.com',
        'admin_password' => 'long-enough',
        'locale' => 'de_DE',
        'version' => null,
    ];

    /** @var list<list<string>> */
    private array $wpCli = [];

    /** @var list<string> */
    private array $log = [];

    private WordpressInstaller&MockInterface $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->installer = Mockery::mock(WordpressInstaller::class);
        $classic = Mockery::mock(ClassicAppType::class);
        $classic->allows('recordInstall');
        $this->app->instance(ClassicAppType::class, $classic);
    }

    public function test_wordpress_is_downloaded_configured_and_installed_on_the_main_domain(): void
    {
        $user = $this->project();
        $root = '/home/shop/shop.example.test/public_html';
        $database = null;
        $password = null;
        $this->installer->expects('ensureDatabase')->withArgs(function (User $owner, string $name, string $dbUser, string $secret) use ($user, &$database, &$password): bool {
            $database = $name;
            $password = $secret;

            return $owner->is($user) && $dbUser === $name;
        });
        $this->installer->expects('writeConfig')->withArgs(static function (User $owner, string $path, string $name, string $dbUser, string $secret) use (&$database, &$password, $root): bool {
            return $path === $root && $name === $database && $dbUser === $database && $secret === $password;
        });

        (new FreshWordpress($this->installer))->install($user, self::SITE, $this->logLine(...));

        $this->assertMatchesRegularExpression('/^shop_wp[0-9a-f]{4}$/', (string) $database);
        $this->assertSame(24, strlen((string) $password));
        $this->assertSame(['core', 'download', '--locale=de_DE', '--path='.$root], $this->wpCli[0]);
        $this->assertSame([
            'core', 'install',
            '--url=https://shop.example.test',
            '--title=Shop',
            '--admin_user=boss',
            '--admin_email=boss@example.com',
            '--admin_password=long-enough',
            '--skip-email',
            '--path='.$root,
        ], $this->wpCli[1]);
        $this->assertSame([
            'Downloading WordPress core into /shop.example.test/public_html',
            "Creating the database {$database}",
            'Installing WordPress for https://shop.example.test',
            'WordPress installed',
        ], $this->log);
    }

    public function test_a_project_without_its_main_domain_row_is_refused_before_anything_runs(): void
    {
        $user = $this->project(withDomain: false);
        $this->installer->shouldNotReceive('ensureDatabase');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Project 'shop' has no main domain to install WordPress on.");

        (new FreshWordpress($this->installer))->install($user, self::SITE, $this->logLine(...));
    }

    private function project(bool $withDomain = true): User
    {
        $saved = $this->makeUser('shop', ['mysql_prefix' => 'shop_'], 'shop.example.test');
        if ($withDomain) {
            $this->makeMainDomain($saved, 'shop.example.test');
        }
        $files = Mockery::mock(FileManager::class);
        $files->allows('isFile')->andReturnFalse();
        $project = Mockery::mock(Project::class);
        $project->allows('homeDirPath')->andReturn('/home/shop/');
        $project->allows('resolvePath')->andReturnUsing(static fn (string $path): string => $path);
        $project->allows('fileManager')->andReturn($files);
        $project->allows('runWpCli')->andReturnUsing(function (array $args): array {
            $this->wpCli[] = $args;

            return ['exit_code' => 0, 'stdout' => 'Success.', 'stderr' => ''];
        });
        $user = new class ($project) extends User {
            protected $table = 'users';

            public function __construct(private ?Project $fake = null)
            {
                parent::__construct();
            }

            public function project(?System $system = null): Project
            {
                return $this->fake ?? parent::project($system);
            }
        };
        $user->setRawAttributes($saved->getAttributes(), true);
        $user->exists = true;

        return $user;
    }

    private function logLine(string $line): void
    {
        $this->log[] = $line;
    }
}
