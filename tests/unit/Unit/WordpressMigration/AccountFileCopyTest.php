<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\AccountFileCopy;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AccountFileCopy writes under a home as the account, never through a
 * symlink the tenant planted, and refuses paths outside that home. The
 * commands run for real in a temporary tree, as the test user.
 */
class AccountFileCopyTest extends TestCase
{
    private string $root;

    private string $home;

    private string $victim;

    private string $account;

    private LocallyRunSystem $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/account-file-copy-'.bin2hex(random_bytes(6));
        $this->home = $this->root.'/home/alice';
        $this->victim = $this->root.'/home/bob/public_html';
        mkdir($this->home, 0755, true);
        mkdir($this->victim, 0750, true);
        chmod($this->victim, 0750);
        file_put_contents($this->victim.'/index.php', 'bob');
        $this->account = posix_getuid().':'.posix_getgid();
        $this->system = new LocallyRunSystem;
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx '.escapeshellarg($this->root).' && rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_place_writes_the_file_as_the_account_and_runs_nothing_as_root(): void
    {
        $this->makeDir('.panelalpha', 0700);
        $source = $this->source('zip');

        $this->files()->place($source, $this->home.'/.panelalpha/wordpress-migrator/1.zip', $this->home, $this->account);

        $staged = $this->home.'/.panelalpha/wordpress-migrator/1.zip';
        $this->assertSame('zip', file_get_contents($staged));
        $this->assertSame('0644', $this->mode($staged));
        $this->assertSame('0755', $this->mode(dirname($staged)));
        $this->assertSame('0700', $this->mode($this->home.'/.panelalpha'));
        $this->assertSame(['1.zip'], array_values(array_diff(scandir(dirname($staged)), ['.', '..'])));
        $this->assertSame([], $this->system->callsAs('root'));
        $this->assertCount(1, $this->system->callsAs('account'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function symlinkedDirectories(): array
    {
        return [
            'staging dir' => ['.panelalpha/wordpress-migrator', '.panelalpha/wordpress-migrator/1.zip'],
            'plugin dir' => [
                'example.com/public_html/wp-content/plugins/wordpress-migrator',
                'example.com/public_html/wp-content/plugins/wordpress-migrator/config.php',
            ],
            'dir further up' => [
                'example.com/public_html/wp-content',
                'example.com/public_html/wp-content/plugins/wordpress-migrator/config.php',
            ],
        ];
    }

    #[DataProvider('symlinkedDirectories')]
    public function test_place_refuses_a_symlinked_directory_and_leaves_its_target_alone(string $link, string $destination): void
    {
        $this->makeDir(dirname($link), 0755);
        symlink($this->victim, $this->home.'/'.$link);

        try {
            $this->files()->place($this->source('zip'), $this->home.'/'.$destination, $this->home, $this->account);
            $this->fail('A write through a symlinked directory must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is a symlink', $e->getMessage());
        }

        $this->assertVictimUntouched();
        $this->assertTrue(is_link($this->home.'/'.$link));
        $this->assertSame([], $this->system->callsAs('root'));
    }

    public function test_place_replaces_a_symlink_at_the_destination_instead_of_writing_through_it(): void
    {
        $this->makeDir('example.com/public_html/wp-content/plugins/wordpress-migrator', 0755);
        $config = $this->home.'/example.com/public_html/wp-content/plugins/wordpress-migrator/config.php';
        symlink($this->victim.'/index.php', $config);

        $this->files()->place($this->source('<?php return [];'), $config, $this->home, $this->account);

        $this->assertFalse(is_link($config));
        $this->assertSame('<?php return [];', file_get_contents($config));
        $this->assertVictimUntouched();
    }

    public function test_ensure_directory_creates_missing_directories_as_the_account(): void
    {
        $this->makeDir('example.com/public_html', 0755);

        $this->files()->ensureDirectory($this->home.'/example.com/public_html/blog/wp', $this->home, $this->account);

        $this->assertSame('0755', $this->mode($this->home.'/example.com/public_html/blog'));
        $this->assertSame('0755', $this->mode($this->home.'/example.com/public_html/blog/wp'));
        $this->assertSame([$this->home.'/example.com'], $this->rootPaths());
    }

    public function test_ensure_directory_refuses_a_symlinked_subdirectory_and_leaves_its_target_alone(): void
    {
        $this->makeDir('example.com/public_html', 0755);
        symlink($this->victim, $this->home.'/example.com/public_html/blog');

        $this->expectRefusal(fn () => $this->files()->ensureDirectory(
            $this->home.'/example.com/public_html/blog', $this->home, $this->account,
        ));

        $this->assertVictimUntouched();
    }

    public function test_ensure_directory_makes_a_missing_home_entry_as_root_with_its_mode_and_owner(): void
    {
        $this->files()->ensureDirectory($this->home.'/.panelalpha', $this->home, $this->account, '0700');

        $this->assertSame('0700', $this->mode($this->home.'/.panelalpha'));
        $root = $this->system->callsAs('root');
        $this->assertCount(1, $root);
        $this->assertSame(['sudo', 'sh', '-c'], array_slice($root[0], 0, 3));
        $this->assertSame(['sh', $this->home.'/.panelalpha', '0700', $this->account], array_slice($root[0], 4));
        $this->assertStringContainsString('chown -h "$3"', $root[0][3]);
        $this->assertStringNotContainsString('chmod', $root[0][3]);
    }

    public function test_ensure_directory_refuses_a_symlinked_home_entry(): void
    {
        symlink($this->victim, $this->home.'/.panelalpha');

        $this->expectRefusal(fn () => $this->files()->ensureDirectory(
            $this->home.'/.panelalpha', $this->home, $this->account, '0700',
        ));

        $this->assertVictimUntouched();
    }

    public function test_ensure_directory_refuses_a_non_octal_mode(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->files()->ensureDirectory($this->home.'/.panelalpha', $this->home, $this->account, 'a+rwx');
        } finally {
            $this->assertSame([], $this->system->calls);
        }
    }

    public function test_remove_deletes_the_file_and_ignores_a_missing_directory(): void
    {
        $this->makeDir('.panelalpha/wordpress-migrator', 0755);
        file_put_contents($this->home.'/.panelalpha/wordpress-migrator/1.zip', 'zip');

        $this->files()->remove($this->home.'/.panelalpha/wordpress-migrator/1.zip', $this->home, $this->account);
        $this->files()->remove($this->home.'/.panelalpha/gone/1.zip', $this->home, $this->account);

        $this->assertFileDoesNotExist($this->home.'/.panelalpha/wordpress-migrator/1.zip');
        $this->assertSame([], $this->system->callsAs('root'));
    }

    public function test_remove_refuses_a_symlinked_directory(): void
    {
        $this->makeDir('.panelalpha', 0700);
        symlink($this->victim, $this->home.'/.panelalpha/wordpress-migrator');

        $this->expectRefusal(fn () => $this->files()->remove(
            $this->home.'/.panelalpha/wordpress-migrator/index.php', $this->home, $this->account,
        ));

        $this->assertVictimUntouched();
    }

    public function test_place_rejects_path_outside_home(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the project home');

        try {
            $this->files()->place($this->source('zip'), '/tmp/evil.zip', $this->home, $this->account);
        } finally {
            $this->assertSame([], $this->system->calls);
        }
    }

    public function test_place_rejects_path_escaping_via_dotdot(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->files()->place($this->source('zip'), $this->home.'/../bob/.panelalpha/x.zip', $this->home, $this->account);
        } finally {
            $this->assertSame([], $this->system->calls);
        }
    }

    private function files(): AccountFileCopy
    {
        return new AccountFileCopy($this->system);
    }

    private function source(string $contents): string
    {
        $source = $this->root.'/source-'.bin2hex(random_bytes(4));
        file_put_contents($source, $contents);

        return $source;
    }

    private function makeDir(string $relative, int $mode): void
    {
        $dir = $this->home.'/'.$relative;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        chmod($dir, $mode);
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return sprintf('%04o', fileperms($path) & 07777);
    }

    /** @return list<string> the path each root command was given */
    private function rootPaths(): array
    {
        return array_map(static fn (array $argv): string => $argv[5], $this->system->callsAs('root'));
    }

    private function expectRefusal(callable $action): void
    {
        try {
            $action();
            $this->fail('The operation must be refused.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is a symlink', $e->getMessage());
        }
    }

    private function assertVictimUntouched(): void
    {
        $this->assertSame('0750', $this->mode($this->victim));
        $this->assertSame(['index.php'], array_values(array_diff(scandir($this->victim), ['.', '..'])));
        $this->assertSame('bob', file_get_contents($this->victim.'/index.php'));
    }
}
