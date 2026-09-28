<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\PythonBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Python and Ruby base images behave the same way apart from the runtime
 * they name and how many packages each is worth baking in.
 */
class RuntimeBaseImageTest extends TestCase
{
    /** @return array<string, array{class-string, string, int}> */
    public static function runtimes(): array
    {
        return [
            'python' => [PythonBaseImage::class, 'python:3.12-slim', 12],
            'ruby' => [RubyBaseImage::class, 'ruby:3.3-slim-bookworm', 8],
        ];
    }

    #[DataProvider('runtimes')]
    public function test_a_tag_names_the_source_tag_and_the_package_set(string $class, string $image, int $max): void
    {
        $this->assertSame($max, $class::MAX_PACKAGES);

        $tag = $class::tag($image, ['libpq-dev', 'build-essential']);
        [, $sourceTag] = explode(':', $image, 2);

        $this->assertSame(
            $class::repository() . ':' . $sourceTag . '-pa' . $class::fingerprint(['build-essential', 'libpq-dev']),
            $tag
        );
        // Order, case and repeats do not change the set.
        $this->assertSame($tag, $class::tag($image, ['BUILD-ESSENTIAL', 'libpq-dev', 'libpq-dev']));
    }

    #[DataProvider('runtimes')]
    public function test_no_tag_for_an_empty_or_oversized_package_set(string $class, string $image, int $max): void
    {
        $packages = array_map(static fn (int $i): string => "pkg{$i}", range(1, $max + 1));

        $this->assertNull($class::tag($image, []));
        $this->assertNotNull($class::tag($image, array_slice($packages, 0, $max)));
        $this->assertNull($class::tag($image, $packages));
    }

    #[DataProvider('runtimes')]
    public function test_no_tag_for_an_image_that_is_not_the_official_one(string $class, string $image, int $max): void
    {
        $this->assertNull($class::tag('ghcr.io/acme/' . $image, ['libpq-dev']));
    }

    public function test_package_names_that_could_reach_a_shell_are_dropped(): void
    {
        $this->assertSame(['libpq-dev'], PythonBaseImage::normalizePackages(['libpq-dev', 'x; rm -rf /', '-flag', 7]));
    }

    #[DataProvider('runtimes')]
    public function test_the_dockerfile_builds_from_the_given_image_with_the_packages(string $class, string $image, int $max): void
    {
        $dockerfile = (string) $class::dockerfile($image, ['libpq-dev', 'build-essential']);

        $this->assertStringContainsString($image, $dockerfile);
        $this->assertStringContainsString('build-essential libpq-dev', $dockerfile);
    }

    public function test_each_runtime_recognises_only_its_own_tags(): void
    {
        $python = (string) PythonBaseImage::tag('python:3.12-slim', ['libpq-dev']);
        $ruby = (string) RubyBaseImage::tag('ruby:3.3-slim-bookworm', ['libpq-dev']);

        $this->assertNull(RubyBaseImage::sourceImage($python));
        $this->assertNull(PythonBaseImage::sourceImage($ruby));
        $this->assertNull(PythonBaseImage::sourceImage('nginx:alpine'));
    }
}
