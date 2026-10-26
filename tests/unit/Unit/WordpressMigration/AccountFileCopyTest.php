<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use App\System;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AccountFileCopy issues sudo argv under /home/<user> and refuses paths outside it.
 */
class AccountFileCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_place_runs_mkdir_cp_chown_chmod_under_home(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'wp-migrator-src-');
        $this->assertNotFalse($source);
        file_put_contents($source, 'zip');

        $calls = [];
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->times(6)
            ->andReturnUsing(function (array $cmd) use (&$calls): string {
                $calls[] = $cmd;

                return '';
            });

        $expectedSource = $source;
        try {
            (new AccountFileCopy($system))->place(
                $source,
                '/home/alice/.panelalpha/wordpress-migrator/1.zip',
                '/home/alice',
                '1001:1001',
            );
        } finally {
            @unlink($source);
        }

        $this->assertSame(
            ['sudo', 'mkdir', '-p', '--', '/home/alice/.panelalpha/wordpress-migrator'],
            $calls[0],
        );
        $this->assertSame(
            ['sudo', 'cp', '--no-dereference', '--', $expectedSource, '/home/alice/.panelalpha/wordpress-migrator/1.zip'],
            $calls[1],
        );
        $this->assertSame(['sudo', 'chown', '1001:1001', '--', '/home/alice/.panelalpha/wordpress-migrator'], $calls[2]);
        $this->assertSame(['sudo', 'chmod', '0755', '--', '/home/alice/.panelalpha/wordpress-migrator'], $calls[3]);
        $this->assertSame(
            ['sudo', 'chown', '1001:1001', '--', '/home/alice/.panelalpha/wordpress-migrator/1.zip'],
            $calls[4],
        );
        $this->assertSame(
            ['sudo', 'chmod', '0644', '--', '/home/alice/.panelalpha/wordpress-migrator/1.zip'],
            $calls[5],
        );
    }

    public function test_ensure_directory_runs_mkdir_chown_chmod_under_home(): void
    {
        $calls = [];
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->times(3)
            ->andReturnUsing(function (array $cmd) use (&$calls): string {
                $calls[] = $cmd;

                return '';
            });

        (new AccountFileCopy($system))->ensureDirectory(
            '/home/alice/public_html/blog',
            '/home/alice',
            '1001:1001',
        );

        $this->assertSame(
            ['sudo', 'mkdir', '-p', '--', '/home/alice/public_html/blog'],
            $calls[0],
        );
        $this->assertSame(['sudo', 'chown', '1001:1001', '--', '/home/alice/public_html/blog'], $calls[1]);
        $this->assertSame(['sudo', 'chmod', '0755', '--', '/home/alice/public_html/blog'], $calls[2]);
    }

    public function test_ensure_directory_takes_a_mode_and_refuses_a_non_octal_one(): void
    {
        $calls = [];
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->andReturnUsing(function (array $cmd) use (&$calls): string {
            $calls[] = $cmd;

            return '';
        });
        $files = new AccountFileCopy($system);

        $files->ensureDirectory('/home/alice/.panelalpha', '/home/alice', '1001:1001', '0700');
        $this->assertSame(['sudo', 'chmod', '0700', '--', '/home/alice/.panelalpha'], $calls[2]);

        $this->expectException(RuntimeException::class);
        $files->ensureDirectory('/home/alice/.panelalpha', '/home/alice', '1001:1001', 'a+rwx');
    }

    public function test_place_rejects_path_outside_home(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'wp-migrator-src-');
        $this->assertNotFalse($source);
        file_put_contents($source, 'zip');

        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the project home');

        try {
            (new AccountFileCopy($system))->place(
                $source,
                '/tmp/evil.zip',
                '/home/alice',
                '1001:1001',
            );
        } finally {
            @unlink($source);
        }
    }

    public function test_place_rejects_path_escaping_via_dotdot(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'wp-migrator-src-');
        $this->assertNotFalse($source);
        file_put_contents($source, 'zip');

        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->never();

        $this->expectException(RuntimeException::class);

        try {
            (new AccountFileCopy($system))->place(
                $source,
                '/home/alice/../bob/.panelalpha/x.zip',
                '/home/alice',
                '1001:1001',
            );
        } finally {
            @unlink($source);
        }
    }

    public function test_remove_uses_sudo(): void
    {
        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')
            ->once()
            ->with(['sudo', 'rm', '-f', '--', '/home/alice/.panelalpha/x.zip'])
            ->andReturn('');

        (new AccountFileCopy($system))->remove('/home/alice/.panelalpha/x.zip', '/home/alice');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }
}
