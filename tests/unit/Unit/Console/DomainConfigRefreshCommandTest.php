<?php

namespace Tests\Unit\Console;

use App\Models\Domain;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class DomainConfigRefreshCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const STALE_VHOST = "<VirtualHost *:80>\n    <FilesMatch \"\\.php$\">\n        SetHandler \"proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost\"\n    </FilesMatch>\n</VirtualHost>\n";

    private string $root;

    /** @var \ArrayObject<int, list<string>> */
    private \ArrayObject $commands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->commands = new \ArrayObject();

        $this->root = sys_get_temp_dir() . '/pa-domain-config-refresh-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/users', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_php_hosting_project_s_vhosts_are_written_again_and_its_apache_reloaded(): void
    {
        $this->app->instance(System::class, $this->fakeSystem('nginx-proxy'));
        $alice = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001], 'alice.test');
        $this->domain($alice->id, 'alice.test', ['php_version' => '8.3']);
        $this->domain($alice->id, 'shop.alice.test', ['php_version' => '8.3', 'ssl_disabled' => true]);
        $this->vhost('alice', 'alice.test', self::STALE_VHOST);
        $this->vhost('alice', 'shop.alice.test', self::STALE_VHOST);

        $exit = Artisan::call('projects:domain-config:refresh', ['--project' => 'alice']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString("Refreshing the domain config of 'alice'...", $output);
        $this->assertStringContainsString('2 domain config(s) written.', $output);
        $secure = $this->vhost('alice', 'alice.test');
        $this->assertSame(2, substr_count($secure, 'CGIPassAuth On'));
        $this->assertStringContainsString('SSLCertificateFile /etc/apache2/ssl-certs/alice.test.crt', $secure);
        $plain = $this->vhost('alice', 'shop.alice.test');
        $this->assertSame(1, substr_count($plain, 'CGIPassAuth On'));
        $this->assertStringContainsString('ServerName shop.alice.test', $plain);
        $this->assertStringNotContainsString('VirtualHost *:443', $plain);
    }

    public function test_only_apache_is_asked_for_its_state_and_reloaded(): void
    {
        $this->app->instance(System::class, $this->fakeSystem('nginx-proxy'));
        $alice = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001], 'alice.test');
        $this->domain($alice->id, 'alice.test', ['php_version' => '8.3']);
        $this->vhost('alice', 'alice.test', self::STALE_VHOST);

        Artisan::call('projects:domain-config:refresh', ['--project' => 'alice']);

        $container = Privileged::accountContainer('alice');
        $this->assertSame(
            [$container->exec(['service', 'apache2', 'status']), $container->exec(['service', 'apache2', 'reload'])],
            $this->commands->getArrayCopy()
        );
    }

    public function test_a_container_project_is_left_alone_without_failing_the_run(): void
    {
        $this->app->instance(System::class, $this->fakeSystem('nginx-proxy'));
        $bob = $this->makeUser('bob', ['template' => 'dind', 'UID' => 1002, 'GID' => 1002], 'bob.test');
        $this->domain($bob->id, 'bob.test', []);

        $exit = Artisan::call('projects:domain-config:refresh', ['--all' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Not a PHP hosting project; nothing to refresh.', $output);
        $this->assertSame([], $this->commands->getArrayCopy());
        $this->assertFileDoesNotExist($this->root . '/engine/users/bob/apache-sites/bob.test.conf');
    }

    public function test_a_project_the_host_webserver_serves_is_left_to_it(): void
    {
        $this->app->instance(System::class, $this->fakeSystem('apache'));
        $alice = $this->makeUser('alice', ['template' => 'default', 'UID' => 1001, 'GID' => 1001], 'alice.test');
        $this->domain($alice->id, 'alice.test', ['php_version' => '8.3']);

        $exit = Artisan::call('projects:domain-config:refresh', ['--project' => 'alice']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('served by the host webserver; nothing to refresh.', Artisan::output());
        $this->assertSame([], $this->commands->getArrayCopy());
    }

    public function test_the_update_runs_it_for_every_project_without_failing_on_it(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__, 4) . '/scripts/installer.sh');

        $this->assertMatchesRegularExpression(
            '~exec -T core php artisan projects:domain-config:refresh --all \|\|\s+echo_warning "[^"]+"~',
            $installer
        );
    }

    /** @param array<string, mixed> $details */
    private function domain(int $userId, string $name, array $details): void
    {
        Domain::create(['user_id' => $userId, 'domain' => $name, 'type' => 'addon', 'details' => $details]);
    }

    private function vhost(string $username, string $domain, ?string $contents = null): string
    {
        $path = $this->root . "/engine/users/{$username}/apache-sites/{$domain}.conf";
        if ($contents !== null) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        return (string) file_get_contents($path);
    }

    private function fakeSystem(string $webserver): System
    {
        return new class ($this->root, $webserver, $this->commands) extends System {
            /** @param \ArrayObject<int, list<string>> $commands */
            public function __construct(private string $root, private string $webserverSlug, private \ArrayObject $commands)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function templatesDirPath(): string
            {
                return dirname(__DIR__, 4) . '/templates';
            }

            public function webserver(): Webserver
            {
                return new class ($this, $this->webserverSlug) extends Webserver {
                    public function __construct(System $system, private string $slug)
                    {
                        parent::__construct($system);
                    }

                    public function getCurrentWebserver(): string
                    {
                        return $this->slug;
                    }
                };
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        file_put_contents($path, $contents);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = (array) $cmd;

                return in_array('status', (array) $cmd, true) ? 'apache2 is running' : '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = (array) $cmd;

                return new Process(['true']);
            }
        };
    }
}
