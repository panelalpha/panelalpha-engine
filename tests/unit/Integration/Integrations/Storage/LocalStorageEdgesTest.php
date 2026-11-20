<?php

namespace Tests\Integration\Integrations\Storage;

use App\Integrations\Storage\Local;
use App\Models\BackupContainer;
use App\System\Privileged\Root;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The local backup store at its edges: keys and prefixes that would leave the root, objects
 * that are missing, a directory that only root could create, and root work that fails.
 */
class LocalStorageEdgesTest extends TestCase
{
    private string $root = '';

    private string $outside = '';

    private string $broker = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir().'/pa-local-edges-'.bin2hex(random_bytes(6));
        $this->root = $base.'/root';
        $this->outside = $base.'/outside';
        $this->broker = $base.'/broker';
        foreach ([$this->root, $this->outside, $this->broker] as $dir) {
            mkdir($dir, 0775, true);
        }
        $this->answerWith(null);
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->removeDir(dirname($this->root));
        parent::tearDown();
    }

    public function test_a_container_names_the_store_by_its_location(): void
    {
        $container = new BackupContainer;
        $container->location = $this->root;
        $this->write(Local::fromContainer($container), 'alice/1/a.bin', 'payload');

        $this->assertSame('payload', (string) file_get_contents($this->root.'/alice/1/a.bin'));
    }

    public function test_releasing_a_container_removes_its_empty_location(): void
    {
        mkdir($this->root.'/alice', 0775, true);
        $container = new BackupContainer;
        $container->location = $this->root;

        Local::releaseLocation($container);

        $this->assertDirectoryDoesNotExist($this->root);
    }

    public function test_dot_segments_in_a_key_are_dropped(): void
    {
        $storage = new Local($this->root);
        $this->write($storage, 'alice/./1//a.bin', 'x');

        $this->assertSame(realpath($this->root).'/alice/1/a.bin', $storage->path('alice/1/a.bin'));
        $this->assertTrue($storage->exists('/alice/1/a.bin/'));
    }

    public function test_an_empty_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid key.');

        (new Local($this->root))->exists('/');
    }

    public function test_a_missing_object_cannot_be_read_or_sized(): void
    {
        $storage = new Local($this->root);

        try {
            $storage->readStream('alice/none.bin');
            $this->fail('a missing object must not be read');
        } catch (RuntimeException $e) {
            $this->assertSame('Backup object not found: alice/none.bin', $e->getMessage());
        }

        $this->expectExceptionMessage('Backup object not found: alice/none.bin');
        $storage->size('alice/none.bin');
    }

    public function test_a_key_naming_a_directory_cannot_be_written(): void
    {
        mkdir($this->root.'/alice', 0775, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write backup object: alice');

        $this->write(new Local($this->root), 'alice', 'x');
    }

    public function test_a_key_through_a_link_out_of_the_root_is_refused(): void
    {
        symlink($this->outside, $this->root.'/escape');

        try {
            $this->write(new Local($this->root), 'escape/a.bin', 'x');
            $this->fail('a key resolving outside the root must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Key must stay inside the storage root.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->outside.'/a.bin');
    }

    public function test_a_prefix_through_a_link_out_of_the_root_is_refused(): void
    {
        symlink($this->outside, $this->root.'/escape');
        touch($this->outside.'/kept.bin');

        try {
            (new Local($this->root))->deletePrefix('escape');
            $this->fail('a prefix resolving outside the root must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Prefix must stay inside the storage root.', $e->getMessage());
        }

        $this->assertFileExists($this->outside.'/kept.bin');
        $this->assertFileDoesNotExist($this->brokerLog());
    }

    public function test_a_prefix_that_does_not_exist_asks_root_for_nothing(): void
    {
        (new Local($this->root))->deletePrefix('nobody/1');
        (new Local($this->root.'/missing'))->deletePrefix('nobody/1');

        $this->assertFileDoesNotExist($this->brokerLog());
    }

    public function test_a_top_level_prefix_leaves_no_parents_to_clean(): void
    {
        $storage = new Local($this->root);
        $this->write($storage, 'alice/1/a.bin', 'x');

        $storage->deletePrefix('alice');

        $this->assertDirectoryDoesNotExist($this->root.'/alice');
        $this->assertSame(['backup-store.removeTree'], $this->askedActions());
    }

    public function test_parents_that_cannot_be_removed_are_logged_and_the_delete_still_succeeds(): void
    {
        $this->answerWith('case "$1" in backup-store.removeEmptyParents) echo "rmdir: busy" >&2; exit 1;; esac');
        $storage = new Local($this->root);
        $this->write($storage, 'alice/1/a.bin', 'x');
        Log::spy();

        $storage->deletePrefix('alice/1');

        $this->assertFalse($storage->exists('alice/1/a.bin'));
        $this->assertDirectoryExists($this->root.'/alice');
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'Could not remove the empty backup directories above')
                && str_ends_with($message, 'rmdir: busy')
        );
    }

    public function test_an_empty_root_root_cannot_remove_is_logged_and_kept(): void
    {
        $this->answerWith('echo "rmdir: Operation not permitted" >&2; exit 1');
        mkdir($this->root.'/alice', 0775, true);
        Log::spy();

        (new Local($this->root))->removeEmptyRoot();

        $this->assertDirectoryExists($this->root.'/alice');
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'Could not remove the empty backup storage root')
                && str_ends_with($message, 'rmdir: Operation not permitted')
        );
    }

    public function test_a_directory_only_root_could_create_is_asked_for_and_handed_to_the_web_user(): void
    {
        $this->answerWith('exit 0');
        touch(dirname($this->root).'/file');
        $storeRoot = dirname($this->root).'/file/store';

        try {
            $this->write(new Local($storeRoot), 'alice/a.bin', 'x');
            $this->fail('a directory that still does not exist must fail the write');
        } catch (RuntimeException $e) {
            $this->assertSame("Could not create backup storage directory: {$storeRoot}/alice", $e->getMessage());
        }

        $this->assertSame([
            'backup-store.makeDirectories',
            'backup-store.giveToWebUser',
            'backup-store.openToWebUser',
            'backup-store.giveToWebUser',
            'backup-store.openToWebUser',
        ], $this->askedActions());
        $this->assertStringContainsString("backup-store.giveToWebUser -- {$storeRoot}\n", (string) file_get_contents($this->brokerLog()));
    }

    public function test_a_directory_root_refuses_to_create_names_why(): void
    {
        $this->answerWith('echo "mkdir: refused" >&2; exit 1');
        touch(dirname($this->root).'/file');
        $storeRoot = dirname($this->root).'/file/store';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Could not create backup storage directory {$storeRoot}/alice: mkdir: refused");

        $this->write(new Local($storeRoot), 'alice/a.bin', 'x');
    }

    public function test_a_store_root_with_parent_segments_is_never_handed_to_root(): void
    {
        touch(dirname($this->root).'/file');
        $storeRoot = dirname($this->root).'/file/x/../store';

        try {
            $this->write(new Local($storeRoot), 'a.bin', 'x');
            $this->fail('a root with .. must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Directory must stay inside the storage root.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->brokerLog());
    }

    public function test_a_system_directory_is_never_chowned(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to change ownership of /etc');

        $this->write(new Local('/etc'), 'hostname/a.bin', 'x');
    }

    private function write(Local $storage, string $key, string $content): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        try {
            $storage->put($key, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** Logs each request, runs $prelude, then answers with pa-root-local. */
    private function answerWith(?string $prelude): void
    {
        $client = $this->broker.'/pa-root';
        $log = escapeshellarg($this->brokerLog());
        $local = 'exec '.escapeshellarg(LocalRoot::client()).' "$@"';
        file_put_contents($client, "#!/bin/sh\necho \"\$@\" >> {$log}\n".($prelude ?? '')."\n{$local}\n");
        chmod($client, 0755);
        Root::answerWith($client);
    }

    private function brokerLog(): string
    {
        return $this->broker.'/requests.log';
    }

    /** @return list<string> */
    private function askedActions(): array
    {
        $lines = array_filter(explode("\n", (string) @file_get_contents($this->brokerLog())));

        return array_values(array_map(static fn (string $line): string => explode(' ', $line, 2)[0], $lines));
    }

    private function removeDir(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeDir($dir.'/'.$item);
            }
        }
        rmdir($dir);
    }
}
