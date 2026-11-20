<?php

namespace Tests\Integration\System\Project\Backup;

use App\Models\User;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\StagingDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The staging directory is made and emptied by the worker where it can, by root where it cannot, and never outside
 * the staging root.
 */
class StagingDirectoryRootFallbackTest extends TestCase
{
    private const ROOT = '/var/tmp/panelalpha-backup';

    /** @var list<list<string>> */
    public array $execs = [];

    private string $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = self::ROOT.'/cov-staging-'.bin2hex(random_bytes(5));
        mkdir($this->account, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->account);
        parent::tearDown();
    }

    public function test_an_existing_directory_is_left_as_it_is(): void
    {
        mkdir($this->account.'/5');

        $this->staging()->ensure($this->account.'/5/');

        $this->assertDirectoryExists($this->account.'/5');
        $this->assertSame([], $this->execs);
    }

    public function test_a_directory_the_worker_cannot_make_is_asked_of_root_and_fails_when_root_cannot_either(): void
    {
        file_put_contents($this->account.'/blocked', 'a file where the directory should be');
        $path = $this->account.'/blocked/5/';

        try {
            $this->staging()->ensure($path);
            $this->fail('a staging directory nobody could make must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to create staging directory: '.$path, $e->getMessage());
        }

        $this->assertSame([
            Privileged::files()->makeDirectories($this->account.'/blocked/5'),
            Privileged::files()->changeOwnerRecursively('www-data:www-data', $this->account.'/blocked/5'),
        ], $this->execs);
    }

    public function test_cleaning_a_directory_that_is_gone_does_nothing(): void
    {
        $this->staging()->clean($this->account.'/gone/');

        $this->assertSame([], $this->execs);
    }

    public function test_a_directory_the_worker_cannot_empty_is_removed_by_root(): void
    {
        mkdir($this->account.'/5/nested', 0755, true);
        file_put_contents($this->account.'/5/files.tar.gz', 'tar');

        $this->staging()->clean($this->account.'/5/');

        $this->assertFileDoesNotExist($this->account.'/5/files.tar.gz');
        $this->assertSame([Privileged::files()->removeTree($this->account.'/5')], $this->execs);
    }

    public function test_a_path_outside_the_staging_root_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid staging path: /var/tmp/elsewhere/5/');

        $this->staging()->ensure('/var/tmp/elsewhere/5/');
    }

    public function test_the_staging_root_itself_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid staging path: '.self::ROOT.'/');

        $this->staging()->ensure(self::ROOT.'/');
    }

    public function test_a_path_climbing_out_of_the_staging_root_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid staging path: '.self::ROOT.'/alice/../../etc/');

        $this->staging()->ensure(self::ROOT.'/alice/../../etc/');
    }

    private function staging(): StagingDirectory
    {
        $user = new User();
        $user->username = 'alice';
        $test = $this;
        $system = new class ($test) extends System {
            public function __construct(private readonly StagingDirectoryRootFallbackTest $test) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->execs[] = (array) $cmd;

                return '';
            }
        };

        return new StagingDirectory(new ProjectAggregate($system, $user));
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->remove($path.'/'.$entry);
        }
        rmdir($path);
    }
}
