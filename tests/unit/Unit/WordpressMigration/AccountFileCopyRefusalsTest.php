<?php

namespace Tests\Unit\WordpressMigration;

use App\System;
use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A file copy into an account's home is refused before anything runs when its paths or owner are not what they must be.
 */
class AccountFileCopyRefusalsTest extends TestCase
{
    public function test_a_source_that_is_not_a_file_is_refused(): void
    {
        $this->assertRefused(
            fn (AccountFileCopy $files) => $files->place('/nonexistent/plugin.zip', '/home/alice/.panelalpha/plugin.zip', '/home/alice', '1001:1001'),
            'Source file not found: /nonexistent/plugin.zip',
        );
    }

    public function test_an_owner_that_is_not_uid_and_gid_is_refused(): void
    {
        $this->assertRefused(
            fn (AccountFileCopy $files) => $files->remove('/home/alice/.panelalpha/plugin.zip', '/home/alice', 'alice'),
            'chown must be uid:gid',
        );
    }

    public function test_the_filesystem_root_is_no_home(): void
    {
        $this->assertRefused(
            fn (AccountFileCopy $files) => $files->remove('/home/alice/plugin.zip', '/', '1001:1001'),
            'Home prefix is invalid.',
        );
    }

    public function test_a_relative_path_is_refused(): void
    {
        $this->assertRefused(
            fn (AccountFileCopy $files) => $files->remove('alice/plugin.zip', '/home/alice', '1001:1001'),
            'Path must be absolute: alice/plugin.zip',
        );
    }

    public function test_a_path_climbing_above_the_root_is_refused(): void
    {
        $this->assertRefused(
            fn (AccountFileCopy $files) => $files->remove('/../../plugin.zip', '/home/alice', '1001:1001'),
            'Path escapes root: /../../plugin.zip',
        );
    }

    private function assertRefused(callable $call, string $message): void
    {
        $system = $this->createMock(System::class);
        $system->expects($this->never())->method('exec');

        try {
            $call(new AccountFileCopy($system));
            $this->fail('the copy was not refused');
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
