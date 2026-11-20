<?php

namespace Tests\Integration\Support;

use App\Support\RootConsoleOwnership;
use Tests\TestCase;

/**
 * The repair a root console run makes: what root left in storage and the bootstrap cache is
 * handed to the web user, links included but never what they point at.
 */
class RootConsoleOwnershipRepairTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        if (posix_geteuid() !== 0 || posix_getpwnam(RootConsoleOwnership::OWNER) === false) {
            $this->markTestSkipped('needs root and a www-data user to hand files to');
        }
        $this->base = sys_get_temp_dir().'/pa-root-repair-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/storage/framework/cache', 0775, true);
        mkdir($this->base.'/bootstrap/cache', 0775, true);
        mkdir($this->base.'/elsewhere');
        $this->app->setBasePath($this->base);
        $this->app->useStoragePath($this->base.'/storage');
    }

    protected function tearDown(): void
    {
        if (isset($this->base)) {
            exec('rm -rf '.escapeshellarg($this->base));
        }
        parent::tearDown();
    }

    public function test_root_owned_entries_go_to_the_web_user_and_link_targets_stay_roots(): void
    {
        $cached = $this->base.'/storage/framework/cache/key';
        $compiled = $this->base.'/bootstrap/cache/packages.php';
        $target = $this->base.'/elsewhere/secret';
        touch($cached);
        touch($compiled);
        touch($target);
        symlink($target, $this->base.'/storage/link');

        RootConsoleOwnership::repair();

        $uid = posix_getpwnam(RootConsoleOwnership::OWNER)['uid'];
        clearstatcache();
        $this->assertSame($uid, fileowner($cached));
        $this->assertSame($uid, fileowner($compiled));
        $this->assertSame($uid, fileowner($this->base.'/storage'));
        $this->assertSame($uid, lstat($this->base.'/storage/link')['uid']);
        $this->assertSame(0, fileowner($target));
    }
}
