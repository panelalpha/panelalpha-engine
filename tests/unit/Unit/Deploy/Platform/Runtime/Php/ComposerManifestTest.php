<?php

namespace Tests\Unit\Deploy\Platform\Runtime\Php;

use App\Lib\Deploy\Platform\Runtime\Php\ComposerManifest;
use PHPUnit\Framework\TestCase;

/**
 * What a project's composer files say: whether there is a lock, and which packages resolve and from where.
 */
class ComposerManifestTest extends TestCase
{
    public function test_the_files_are_kept_as_given_and_an_empty_lock_is_no_lock(): void
    {
        $manifest = new ComposerManifest('{"name":"acme/app"}', '');

        $this->assertSame('{"name":"acme/app"}', $manifest->json());
        $this->assertSame('', $manifest->lock());
        $this->assertFalse($manifest->hasLockfile());
        $this->assertFalse((new ComposerManifest(null, null))->hasLockfile());
        $this->assertTrue((new ComposerManifest(null, '{"packages":[]}'))->hasLockfile());
    }

    public function test_a_package_resolves_from_the_root_the_lock_or_the_installed_list(): void
    {
        $manifest = new ComposerManifest(
            '{"require":{"laravel/framework":"^11"}}',
            '{"packages":[{"name":"symfony/console"}]}'
        );

        $this->assertTrue($manifest->resolves('laravel/framework'));
        $this->assertTrue($manifest->resolves('symfony/console'));
        $this->assertTrue($manifest->resolves('guzzlehttp/guzzle', '{"packages":[{"name":"guzzlehttp/guzzle"}]}'));
        $this->assertTrue($manifest->resolves('monolog/monolog', '[{"name":"monolog/monolog"}]'));
        $this->assertFalse($manifest->resolves('doctrine/dbal', '{"packages":[{"name":"monolog/monolog"}]}'));
        $this->assertFalse($manifest->rootRequires('symfony/console'));
    }
}
