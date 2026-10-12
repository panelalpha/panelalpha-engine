<?php

namespace Tests\Unit\System\Project;

use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Services\Webserver;
use Tests\TestCase;

class PhpErrorLogListingTest extends TestCase
{
    public function test_the_all_webservers_listing_offers_the_php_error_log(): void
    {
        $system = $this->system();
        $path = $this->logPath($system);
        $this->writeOwnedLog($path, "one\ntwo\n");

        $entry = $this->entry($system);

        $this->assertNotNull($entry, 'the php error log is missing from the listing');
        $this->assertSame($path, $entry['path']);
        $this->assertSame(filesize($path), $entry['size']);
        $this->assertSame(filemtime($path), $entry['mtime']);
    }

    public function test_the_per_webserver_listing_stays_webserver_only(): void
    {
        $system = $this->system();
        $this->writeOwnedLog($this->logPath($system), "one\n");

        $names = array_column($this->domain($system)->listLogFiles(), 'file');

        $this->assertNotContains('php_error.log', $names);
    }

    public function test_a_symlinked_log_is_not_offered(): void
    {
        $system = $this->system();
        $path = $this->logPath($system);
        mkdir(dirname($path), 0777, true);
        file_put_contents($system->projectDirPath('alice') . '/outside-secret.txt', "root secret\n");
        symlink($system->projectDirPath('alice') . '/outside-secret.txt', $path);

        $this->assertNull($this->entry($system));
    }

    public function test_a_hard_linked_log_is_not_offered(): void
    {
        $system = $this->system();
        $path = $this->logPath($system);
        $this->writeOwnedLog($path, "one\n");
        link($path, dirname($path) . '/other.log');

        $this->assertNull($this->entry($system));
    }

    public function test_a_log_owned_by_another_user_is_not_offered(): void
    {
        $system = $this->system();
        $path = $this->logPath($system);
        $this->writeOwnedLog($path, "one\n");
        chown($path, posix_getuid());

        $this->assertNull($this->entry($system));
    }

    /**
     * @return array{file: string, path: string, mtime: int, size: int}|null
     */
    private function entry(System $system): ?array
    {
        foreach ($this->domain($system)->listWebserverLogFiles() as $file) {
            if ($file['file'] === 'php_error.log') {
                return $file;
            }
        }

        return null;
    }

    private function logPath(System $system): string
    {
        return $system->projectDirPath('alice') . '/log/php/php_error.log';
    }

    private function writeOwnedLog(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        chown($path, 1001);
        chgrp($path, 1001);
    }

    private function domain(System $system): Project\Domain
    {
        $user = new ModelsUser();
        $user->username = 'alice';
        $user->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $model = new DomainModel();
        $model->domain = 'a.test';

        return (new Project($system, $user))->domain($model);
    }

    private function system(): System
    {
        $root = sys_get_temp_dir() . '/pa-php-log-listing-' . bin2hex(random_bytes(4));
        $this->cleanup[] = $root;

        return new class ($root) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
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
        };
    }

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
        parent::tearDown();
    }
}
