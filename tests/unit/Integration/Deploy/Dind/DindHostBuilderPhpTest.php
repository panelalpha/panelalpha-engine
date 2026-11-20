<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;
use Tests\Support\DindHostBuilderPhpFixtures;
use Tests\Support\LocalRoot;

/**
 * The host PHP build is a container on the *host* daemon with a customer's
 * repository mounted into it, so what this request mounts and the user it runs
 * as are the boundary, and the broker adds the rest of the sandbox.
 */
class DindHostBuilderPhpTest extends TestCase
{
    use DindHostBuilderPhpFixtures;

    public function test_it_runs_as_the_account_and_gives_up_every_privilege(): void
    {
        $request = (new DindHostBuilder)->phpBuildArgv($this->account(), self::IMAGE, 'composer install --no-dev');
        $build = LocalRoot::build($request);

        $this->assertSame('1001:1001', $build['user']);
        $this->assertSame('2g', $build['memory']);
        $this->assertSame([], LocalRoot::plain($request));
        $this->assertNotContains('--privileged', LocalRoot::values($request));
    }

    /**
     * The image's entrypoint does pass an explicit command through, but a
     * build step should not depend on that indirection to be correct.
     */
    public function test_it_does_not_rely_on_the_images_entrypoint(): void
    {
        $request = (new DindHostBuilder)->phpBuildArgv($this->account(), self::IMAGE, 'composer install --no-dev');

        $this->assertSame('docker.phpBuild', LocalRoot::action($request));
        $this->assertSame([], LocalRoot::plain($request));
    }

    public function test_the_composer_cache_directory_is_created_for_the_account(): void
    {
        $line = implode(' ', LocalRoot::plain((new DindHostBuilder)->prepareCacheArgv($this->account())));

        $this->assertStringContainsString(PhpHostBuild::cacheDirFor('acme'), $line);
        $this->assertStringContainsString('1001:1001', $line);
    }
}
