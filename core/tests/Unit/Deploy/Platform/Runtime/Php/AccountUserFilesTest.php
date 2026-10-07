<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\AccountUserFiles;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The PHP container runs as the bare account uid, which the base image does
 * not know: posix_getpwuid() returned false and Passbolt died on boot.
 */
class AccountUserFilesTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.2-apache-bookworm-pa20260924';

    private const PASSWD = "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n";

    private const GROUP = "root:x:0:\nwww-data:x:33:\n";

    /** @var list<list<string>> */
    private array $calls = [];

    private function files(?\Closure $run = null, ?Repository $cache = null): AccountUserFiles
    {
        $run ??= function (array $argv): string {
            $this->calls[] = $argv;

            return end($argv) === '/etc/passwd' ? self::PASSWD : self::GROUP;
        };

        return new AccountUserFiles($run, $cache ?? new Repository(new ArrayStore()));
    }

    public function test_the_account_uid_and_gid_are_appended_to_the_images_own_files(): void
    {
        $files = $this->files()->for(self::IMAGE, 1246, 1246);

        $this->assertSame(self::PASSWD . "app:x:1246:1246:app:/app:/usr/sbin/nologin\n", $files['passwd']);
        $this->assertSame(self::GROUP . "app:x:1246:\n", $files['group']);
    }

    public function test_the_files_are_read_from_the_host_image_without_pulling(): void
    {
        $this->files()->for(self::IMAGE, 1246, 1246);

        $this->assertSame(
            ['sudo', 'docker', 'run', '--rm', '--pull', 'never', '--network', 'none', '--entrypoint', 'cat', self::IMAGE, '/etc/passwd'],
            $this->calls[0]
        );
    }

    public function test_an_image_is_read_once_per_tag(): void
    {
        $cache = new Repository(new ArrayStore());
        $this->files(null, $cache)->for(self::IMAGE, 1246, 1246);
        $this->files(null, $cache)->for(self::IMAGE, 1300, 1300);

        $this->assertCount(2, $this->calls, 'passwd and group, once');
    }

    public function test_a_uid_the_image_already_has_needs_nothing(): void
    {
        $this->assertNull($this->files()->for(self::IMAGE, 33, 33));
        $this->assertNull(AccountUserFiles::passwdWith(self::PASSWD, 33, 33));
    }

    public function test_an_existing_group_is_kept_and_only_the_user_is_added(): void
    {
        $files = $this->files()->for(self::IMAGE, 1246, 33);

        $this->assertStringEndsWith("app:x:1246:33:app:/app:/usr/sbin/nologin\n", $files['passwd']);
        $this->assertSame(self::GROUP, $files['group']);
    }

    public function test_a_failed_read_mounts_nothing_and_is_not_cached(): void
    {
        $cache = new Repository(new ArrayStore());
        $failing = function (array $argv): string {
            throw new \RuntimeException('No such image');
        };

        $this->assertNull($this->files($failing, $cache)->for(self::IMAGE, 1246, 1246));
        $this->assertNotNull($this->files(null, $cache)->for(self::IMAGE, 1246, 1246));
    }

    public function test_an_unsafe_image_reference_is_never_run(): void
    {
        $this->assertNull($this->files()->for('--privileged', 1246, 1246));
        $this->assertSame([], $this->calls);
    }
}
