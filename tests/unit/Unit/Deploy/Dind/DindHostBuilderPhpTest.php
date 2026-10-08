<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\Lib\Deploy\Engine\EngineAccount;
use App\Lib\Deploy\Platform\Runtime\Php\PhpHostBuild;
use PHPUnit\Framework\TestCase;

/**
 * The host PHP build is a container on the *host* daemon with a customer's
 * repository mounted into it, so the hardening in this argv is the boundary,
 * not decoration.
 */
class DindHostBuilderPhpTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.3-apache-bookworm-pa9519f31e';

    private function account(): EngineAccount
    {
        return new EngineAccount('acme', '/home/acme', '1001:1001');
    }

    private function argv(string $appRoot = ''): array
    {
        return (new DindHostBuilder())->phpBuildArgv(
            $this->account(),
            self::IMAGE,
            'composer install --no-dev',
            $appRoot
        );
    }

    public function test_it_runs_as_the_account_and_gives_up_every_privilege(): void
    {
        $argv = $this->argv();
        $line = implode(' ', $argv);

        $this->assertStringContainsString('--user 1001:1001', $line);
        $this->assertStringContainsString('--security-opt no-new-privileges', $line);
        $this->assertStringContainsString('--cap-drop ALL', $line);
        $this->assertStringContainsString('--memory', $line);
        $this->assertStringContainsString('--pids-limit', $line);
        $this->assertNotContains('--privileged', $argv);
    }

    /**
     * Composer has to see the PHP and the extensions the application will
     * actually run on. Resolving in the composer image instead is what let a
     * package needing an absent extension install cleanly and then fail at
     * the first request.
     */
    public function test_it_builds_in_the_runtime_image(): void
    {
        $this->assertContains(self::IMAGE, $this->argv());
    }

    public function test_it_mounts_the_project_and_the_accounts_composer_cache(): void
    {
        $line = implode(' ', $this->argv());

        $this->assertStringContainsString('/home/acme/project:/app', $line);
        $this->assertStringContainsString(
            PhpHostBuild::cacheDirFor('acme') . ':' . PhpBaseImage::COMPOSER_CACHE_DIR,
            $line
        );
    }

    public function test_it_works_where_the_manifest_says_the_application_is(): void
    {
        $this->assertContains('/app', $this->argv());
        $this->assertContains('/app/phpBB', $this->argv('phpBB'));
    }

    public function test_a_project_directory_that_is_not_the_accounts_own_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DindHostBuilder())->phpBuildArgv(
            new EngineAccount('acme', '/srv/elsewhere', '1001:1001'),
            self::IMAGE,
            'composer install'
        );
    }

    public function test_an_image_reference_that_is_not_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DindHostBuilder())->phpBuildArgv($this->account(), 'php; rm -rf /', 'composer install');
    }

    public function test_a_build_with_nothing_to_run_is_refused_rather_than_run_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DindHostBuilder())->phpBuildArgv($this->account(), self::IMAGE, '   ');
    }

    /**
     * The image's entrypoint does pass an explicit command through, but a
     * build step should not depend on that indirection to be correct.
     */
    public function test_it_does_not_rely_on_the_images_entrypoint(): void
    {
        $argv = $this->argv();
        $i = array_search('--entrypoint', $argv, true);

        $this->assertNotFalse($i);
        $this->assertSame('sh', $argv[$i + 1]);
    }

    public function test_the_composer_cache_directory_is_created_for_the_account(): void
    {
        $line = implode(' ', (new DindHostBuilder())->prepareCacheArgv($this->account()));

        $this->assertStringContainsString(PhpHostBuild::cacheDirFor('acme'), $line);
        $this->assertStringContainsString('1001:1001', $line);
    }

    /**
     * The dependency step runs in the runtime image and shares the mount, so
     * it can resolve from the same require-dev-less manifest the other
     * Composer passes use. The variable is set on the container rather than
     * the script, so it survives the step's own shell.
     */
    public function test_a_runtime_manifest_is_exported_to_the_build_container(): void
    {
        $argv = (new DindHostBuilder())->phpBuildArgv(
            $this->account(),
            self::IMAGE,
            'composer install --no-dev',
            '',
            true,
            PhpHostBuild::RUNTIME_MANIFEST_FILE
        );

        $this->assertContains(
            PhpHostBuild::MANIFEST_ENV . '=' . PhpHostBuild::RUNTIME_MANIFEST_FILE,
            $argv
        );
    }

    public function test_without_a_runtime_manifest_the_build_reads_the_project_itself(): void
    {
        $line = implode(' ', $this->argv());

        $this->assertStringNotContainsString(PhpHostBuild::MANIFEST_ENV . '=', $line);
    }

    /**
     * A host that will not let the engine create the cache directory is no
     * reason to refuse the deploy — but the mount has to go with it. Docker
     * would create the missing path as root, and composer running as the
     * account would then stop on a permission error it cannot explain.
     */
    public function test_without_a_cache_the_mount_goes_too(): void
    {
        $argv = (new DindHostBuilder())->phpBuildArgv(
            $this->account(),
            self::IMAGE,
            'composer install',
            '',
            false
        );
        $line = implode(' ', $argv);

        $this->assertStringNotContainsString(PhpHostBuild::cacheDirFor('acme'), $line);
        $this->assertStringContainsString('/home/acme/project:/app', $line);
        $this->assertStringNotContainsString(
            'COMPOSER_CACHE_DIR=' . PhpBaseImage::COMPOSER_CACHE_DIR,
            $line,
            'composer must not be pointed at a cache directory that is not mounted'
        );
    }
}
