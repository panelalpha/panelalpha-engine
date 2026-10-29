<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Platform\Runtime\Php\PhpBuild;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\Strategy\PhpStrategy;
use Tests\TestCase;

/**
 * The extras handed on with the base image are the ones its tag was named
 * with, so a rebuild of a pruned base produces what the tag says it holds.
 */
class PhpStrategyBaseImageTest extends TestCase
{
    private const PLAIN = 'panelalpha/php:8.3-apache-bookworm-pa20260924';

    private const VARIANT = 'panelalpha/php:8.3-apache-bookworm-pa20260924-xdeadbeef';

    public function test_a_plain_base_in_place_of_a_deferred_variant_carries_no_extras(): void
    {
        $build = $this->build(['tag' => self::PLAIN, 'baked' => []]);

        $this->assertSame(self::PLAIN, $build->baseImage);
        $this->assertSame([], $build->baseImageExtras, 'the plain base would be rebuilt with extras it was not named for');
    }

    public function test_a_variant_carries_the_extras_it_was_named_with(): void
    {
        $build = $this->build(['tag' => self::VARIANT, 'baked' => ['tidy']]);

        $this->assertSame(self::VARIANT, $build->baseImage);
        $this->assertSame(['tidy'], $build->baseImageExtras);
    }

    /**
     * @param  array{tag: ?string, baked: list<string>}  $base  what ensurePhpBaseImage() answers
     */
    private function build(array $base): PhpBuild
    {
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensurePhpBaseImage')->willReturn($base);

        $files = $this->createStub(ProjectFiles::class);
        $files->method('readIn')->willReturnCallback(
            static fn (string $dir, string $name): ?string => $name === 'composer.json'
                ? (string) json_encode(['require' => ['php' => '^8.3', 'ext-tidy' => '*']])
                : null
        );

        $dind = $this->createStub(DindProject::class);
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('projectTree')->willReturn($files);

        return (new \ReflectionMethod(PhpStrategy::class, 'build'))
            ->invoke(new PhpStrategy($dind), '/home/alice/project', false, false);
    }
}
