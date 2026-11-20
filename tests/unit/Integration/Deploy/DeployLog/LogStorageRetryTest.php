<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\LogStorage;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The second attempt every write makes after asking the broker to hand the directory to www-data, and what is
 * raised when that attempt fails too. The broker is a script that records each request and changes nothing.
 */
class LogStorageRetryTest extends TestCase
{
    private string $dir = '';

    private string $requests = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-storage-retry-'.bin2hex(random_bytes(8));
        mkdir($this->dir.'/broker', 0777, true);
        $this->requests = $this->dir.'/broker/requests';
        $broker = $this->dir.'/broker/pa-root';
        file_put_contents($broker, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($this->requests)."\n");
        chmod($broker, 0755);
        LocalRoot::answerWith($broker);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->rmrf($this->dir);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rmrf($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }

    /** @return list<string> */
    private function requests(): array
    {
        return is_file($this->requests) ? file($this->requests, FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_a_file_that_cannot_be_written_twice_names_itself(): void
    {
        $path = $this->dir.'/logs/deploy.log';
        mkdir($path, 0777, true);

        try {
            LogStorage::write($path, "line\n");
            $this->fail('A write into a directory must not pass');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not write deploy log file: '.$path, $e->getMessage());
        }

        $this->assertSame(['files.changeOwnerRecursively -- www-data:www-data '.$this->dir.'/logs'], $this->requests());
    }

    public function test_the_filesystem_root_is_never_handed_to_www_data(): void
    {
        try {
            LogStorage::write('/proc', "line\n");
            $this->fail('A write onto /proc must not pass');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('#^(Could not write deploy log file: /proc|Deploy log directory is not writable: /$)#', $e->getMessage());
        }

        $this->assertSame([], $this->requests());
    }

    public function test_a_pointer_that_cannot_replace_its_target_twice_names_it(): void
    {
        $path = $this->dir.'/logs/latest.json';
        mkdir($path.'/occupied', 0777, true);

        try {
            LogStorage::replace($path, '{}');
            $this->fail('Replacing a non-empty directory must not pass');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not write deploy latest pointer: '.$path, $e->getMessage());
        }

        $this->assertSame(['files.changeOwnerRecursively -- www-data:www-data '.$this->dir.'/logs'], $this->requests());
    }

    public function test_a_pointer_that_cannot_replace_its_target_leaves_no_temporary_file_behind(): void
    {
        $path = $this->dir.'/logs/latest.json';
        mkdir($path.'/occupied', 0777, true);

        try {
            LogStorage::replace($path, '{}');
            $this->fail('Replacing a non-empty directory must not pass');
        } catch (RuntimeException) {
        }

        $this->assertSame(['latest.json'], array_values(array_diff(scandir($this->dir.'/logs') ?: [], ['.', '..'])));
    }

    public function test_a_directory_that_cannot_be_created_twice_names_itself(): void
    {
        file_put_contents($this->dir.'/blocker', 'a file where a directory should be');
        $dir = $this->dir.'/blocker/deploy';

        try {
            LogStorage::ensureDirectory($dir);
            $this->fail('A directory under a file must not be created');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not create deploy log directory: '.$dir, $e->getMessage());
        }

        $this->assertSame(['files.changeOwnerRecursively -- www-data:www-data '.$this->dir.'/blocker'], $this->requests());
    }

    public function test_a_broker_that_cannot_be_started_is_logged_and_the_write_still_fails_on_its_own_terms(): void
    {
        LocalRoot::answerWith($this->dir.'/broker/pa-root'."\0");
        Log::spy();
        $path = $this->dir.'/logs/deploy.log';
        mkdir($path, 0777, true);

        try {
            LogStorage::write($path, "line\n");
            $this->fail('A write into a directory must not pass');
        } catch (RuntimeException $e) {
            $this->assertSame('Could not write deploy log file: '.$path, $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'DeployLogger: could not fix ownership'
                && $context['path'] === $this->dir.'/logs'
        );
    }
}
