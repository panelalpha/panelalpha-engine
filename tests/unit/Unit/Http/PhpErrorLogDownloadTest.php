<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Domains\DomainLogFiles;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhpErrorLogDownloadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();
        (require base_path('database/migrations/2022_09_06_160855_create_domains_table.php'))->up();

        $this->root = sys_get_temp_dir() . '/pa-php-error-download-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/engine/users/alice/log/php', 0777, true);

        $this->app->instance(System::class, new class ($this->root) extends System {
            public function __construct(private string $root)
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

            public function webserver(): Webserver
            {
                return new class ($this) extends Webserver {
                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return new Process(['true']);
            }
        });

        $this->withoutMiddleware(Authenticate::class);

        /** @var User $user */
        $user = User::make([
            'username' => 'alice',
            'domain' => 'alice.test',
            'details' => ['template' => 'default', 'UID' => 1001, 'GID' => 1001],
        ]);
        $user->save();

        $domain = new DomainModel();
        $domain->user_id = $user->id;
        $domain->domain = 'a.example';
        $domain->type = 'main';
        $domain->setDetails(['document_root' => '/a.example/public_html']);
        $domain->save();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_regular_log_is_listed_and_served_through_the_account_stream(): void
    {
        $this->writeLog("one\ntwo\n");

        $listed = $this->getJson('/api/projects/alice/domains/a.example/log-files?all_webservers=1');
        $listed->assertOk();
        $entry = $this->entry($listed->json('data'));
        $this->assertNotNull($entry, 'php_error.log is missing from the listing');
        $this->assertSame(filesize($this->logPath()), $entry['size']);
        $this->assertSame(filemtime($this->logPath()), $entry['mtime']);

        $logFiles = DomainLogFiles::find(User::findByUsername('alice'), 'a.example', app(System::class));
        $this->assertNotNull($logFiles);
        $this->assertSame('sudophp://' . $this->logPath(), $logFiles->readablePath('php_error.log', true));
    }

    public function test_a_symlinked_log_is_not_listed_and_does_not_download(): void
    {
        file_put_contents($this->root . '/outside-secret.txt', "root secret\n");
        symlink($this->root . '/outside-secret.txt', $this->logPath());

        $listed = $this->getJson('/api/projects/alice/domains/a.example/log-files?all_webservers=1');
        $listed->assertOk();
        $this->assertNull($this->entry($listed->json('data')));

        $this->getJson('/api/projects/alice/domains/a.example/log-files/php_error.log?all_webservers=1')
            ->assertStatus(404);
    }

    public function test_a_hard_linked_log_is_not_listed_and_does_not_download(): void
    {
        $this->writeLog("one\n");
        link($this->logPath(), dirname($this->logPath()) . '/other.log');

        $listed = $this->getJson('/api/projects/alice/domains/a.example/log-files?all_webservers=1');
        $listed->assertOk();
        $this->assertNull($this->entry($listed->json('data')));

        $this->getJson('/api/projects/alice/domains/a.example/log-files/php_error.log?all_webservers=1')
            ->assertStatus(404);
    }

    /**
     * @param array<int, array<string, mixed>>|null $files
     * @return array<string, mixed>|null
     */
    private function entry(?array $files): ?array
    {
        foreach ($files ?? [] as $file) {
            if (($file['file'] ?? null) === 'php_error.log') {
                return $file;
            }
        }

        return null;
    }

    private function logPath(): string
    {
        return $this->root . '/engine/users/alice/log/php/php_error.log';
    }

    private function writeLog(string $contents): void
    {
        file_put_contents($this->logPath(), $contents);
        chown($this->logPath(), 1001);
        chgrp($this->logPath(), 1001);
    }
}
