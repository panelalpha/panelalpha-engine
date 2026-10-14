<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\CacheManager\RubyBaseImage;
use App\Lib\Deploy\Platform\Runtime\GoRuntime;
use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\PhpRuntime;
use App\Lib\Deploy\Platform\Runtime\PythonRuntime;
use App\Lib\Deploy\Platform\Runtime\RustRuntime;
use App\Lib\Deploy\Platform\Runtime\RubyRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageCatalog;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use App\Lib\Deploy\Platform\Runtime\RuntimeImageSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue reads the real shipped `config/core/images.yaml` —
 * CONFIG_PATH is a private const with no injection seam, the same arrangement
 * {@see \Tests\Unit\Deploy\CacheManager\ImageCatalogTest} works within.
 *
 * So the assertions here are about *rules that must hold whatever the config
 * says*, not about the values it currently holds. A host is expected to edit
 * this file; a test that pins its contents would fail for the operator who
 * did exactly what the file invites.
 *
 * The class has no Laravel dependencies, so this extends the plain PHPUnit
 * TestCase — no app boot required.
 */
class RuntimeImageCatalogTest extends TestCase
{
    // -------------------------------------------------------------------------
    // The invariant that matters most: wiring PHP to the catalogue must not
    // have moved any image anybody is already running.
    // -------------------------------------------------------------------------

    /**
     * Every minor still resolves to the tag it resolved to before the
     * catalogue existed.
     *
     * This is the whole risk of the change. `PhpBaseImage::tag()` only
     * recognises a plain official `php:<tag>`, and its fingerprint is what
     * decides whether a host rebuilds — so a tag that shifted by one
     * character would orphan every shared base image on every host at once
     * and make each of them recompile ~25 extensions from source.
     */
    public function test_php_minors_resolve_to_the_same_tags_as_the_compiled_in_variant(): void
    {
        foreach (PhpRuntime::minors() as $minor) {
            $this->assertSame(
                "php:{$minor}-" . PhpRuntime::IMAGE_VARIANT,
                PhpRuntime::imageTag($minor),
                "the catalogue moved the tag for PHP {$minor}"
            );
        }
    }

    public function test_php_tags_are_still_recognised_as_shared_base_sources(): void
    {
        foreach (PhpRuntime::minors() as $minor) {
            $upstream = PhpRuntime::imageTag($minor);
            $tag = PhpBaseImage::tag($upstream);

            $this->assertNotNull($tag, "{$upstream} is no longer a buildable base source");
            $this->assertSame($upstream, PhpBaseImage::sourceImage((string) $tag));
        }
    }

    // -------------------------------------------------------------------------
    // What the shipped config promises
    // -------------------------------------------------------------------------

    public function test_php_is_described_and_its_default_is_one_of_its_versions(): void
    {
        $this->assertTrue(RuntimeImageCatalog::has('php'));

        $versions = RuntimeImageCatalog::versions('php');
        $this->assertNotEmpty($versions);
        $this->assertContains(RuntimeImageCatalog::defaultVersion('php'), $versions);
    }

    public function test_php_versions_are_ordered_oldest_first(): void
    {
        // Load-bearing, not cosmetic: requirementFor() takes the first minor
        // satisfying every constraint, so a reordered list silently hands
        // projects a PHP they were never tested against.
        $versions = RuntimeImageCatalog::versions('php');
        $sorted = $versions;
        usort($sorted, 'version_compare');

        $this->assertSame($sorted, $versions);
    }

    public function test_php_resolves_to_a_built_runnable_image(): void
    {
        $spec = RuntimeImageCatalog::spec('php', PhpRuntime::defaultMinor());

        $this->assertInstanceOf(RuntimeImageSpec::class, $spec);
        $this->assertTrue($spec->isBuilt(), 'PHP runs a base image the engine builds itself');
        $this->assertTrue($spec->runnable, 'the PHP base is the runtime, not an optimisation over it');
        $this->assertSame(PhpBaseImage::repository(), $spec->repository);
    }

    public function test_the_version_placeholder_is_substituted(): void
    {
        $spec = RuntimeImageCatalog::spec('php', '8.1');

        $this->assertNotNull($spec);
        $this->assertStringContainsString('8.1', $spec->from);
        $this->assertStringNotContainsString('{version}', $spec->from);
    }

    public function test_spec_token_matches_the_requirement_it_came_from(): void
    {
        $requirement = PhpRuntime::requirementFor(json_encode(['require' => ['php' => '^8.2']]));
        $spec = RuntimeImageCatalog::spec('php', $requirement->version);

        $this->assertNotNull($spec);
        $this->assertSame($requirement->token(), $spec->token());
    }

    // -------------------------------------------------------------------------
    // Degradation. A host edits this file; nothing it can write may break a
    // deploy, so every bad shape has to answer null rather than throw.
    // -------------------------------------------------------------------------

    public function test_an_undescribed_runtime_answers_nothing_rather_than_guessing(): void
    {
        $this->assertFalse(RuntimeImageCatalog::has('brainfuck'));
        $this->assertNull(RuntimeImageCatalog::spec('brainfuck', '1.0'));
        $this->assertNull(RuntimeImageCatalog::defaultVersion('brainfuck'));
        $this->assertSame([], RuntimeImageCatalog::versions('brainfuck'));
    }

    /**
     * Every runtime the engine has is described here.
     *
     * This started as a list of the ones that were *not* yet wired, and failed
     * on each migration step until the list emptied. Stated the other way
     * round it keeps working: a runtime added to the engine and forgotten here
     * falls back to its own constants silently, and an operator editing this
     * file for it would see nothing happen.
     */
    public function test_every_runtime_the_engine_knows_is_described(): void
    {
        foreach (array_keys(RuntimeRegistry::all()) as $runtime) {
            $this->assertTrue(
                RuntimeImageCatalog::has($runtime),
                "{$runtime} is a runtime the engine resolves but the catalogue does not describe"
            );
        }
    }

    /**
     * The rule behind that one, stated so it maintains itself: whatever the
     * config declares, the runtime class has to be the thing answering it.
     *
     * A runtime listed here but reading its own constants is the failure this
     * whole file is designed against — an operator edits a version set, the
     * deploy ignores it, and nothing anywhere says so.
     *
     * @return array<string, array{0: string}>
     */
    public static function wiredRuntimes(): array
    {
        return [
            'php' => ['php'],
            'ruby' => ['ruby'],
            'python' => ['python'],
            'go' => ['go'],
            'rust' => ['rust'],
            'node' => ['node'],
            'java' => ['java'],
        ];
    }

    /**
     * What each runtime class answers, however it happens to spell the
     * accessor. The spellings differ because the runtimes do — Python counts
     * minors, Rust has one version and no choice to make.
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function answersFor(string $runtime): array
    {
        return match ($runtime) {
            'php' => [PhpRuntime::minors(), PhpRuntime::defaultMinor()],
            'ruby' => [RubyRuntime::minors(), RubyRuntime::defaultMinor()],
            'python' => [PythonRuntime::versions(), PythonRuntime::defaultVersion()],
            'go' => [GoRuntime::minors(), GoRuntime::defaultMinor()],
            'rust' => [(new RustRuntime())->supportedVersions(), RustRuntime::VERSION],
            'node' => [NodeRuntime::majors(), NodeRuntime::defaultMajor()],
            'java' => [JavaRuntime::toolchains(), JavaRuntime::defaultToolchain()],
        };
    }

    #[DataProvider('wiredRuntimes')]
    public function test_a_catalogued_runtime_answers_from_the_catalogue(string $runtime): void
    {
        $this->assertTrue(RuntimeImageCatalog::has($runtime), "{$runtime} should be described");

        $declared = RuntimeImageCatalog::versions($runtime);
        $this->assertNotEmpty($declared, "{$runtime} declares no versions");

        [$answered, $default] = $this->answersFor($runtime);

        $this->assertSame($declared, $answered, "{$runtime} ignores the versions the config declares");
        $this->assertSame(
            RuntimeImageCatalog::defaultVersion($runtime),
            $default,
            "{$runtime} ignores the default the config declares"
        );
        $this->assertContains($default, $declared);
    }

    #[DataProvider('wiredRuntimes')]
    public function test_every_declared_version_resolves_to_an_image(string $runtime): void
    {
        foreach (RuntimeImageCatalog::versions($runtime) as $version) {
            $spec = RuntimeImageCatalog::spec($runtime, $version);

            $this->assertNotNull($spec, "{$runtime} {$version} resolves to no image");
            $this->assertStringNotContainsString('{version}', $spec->from);
            $this->assertMatchesRegularExpression('#^[a-z0-9][a-z0-9._/-]*:[a-z0-9][a-z0-9._-]*$#i', $spec->from);
        }
    }

    /**
     * Which runtimes the engine builds an image for, and which run what the
     * registry publishes.
     *
     * The three that build all do it for the same reason — a stock image
     * cannot compile what their ecosystem installs from source — and the ones
     * that do not, do not need to: Go and Rust produce static binaries, Java
     * builds in an image that already carries a JDK, and Node's native
     * addons ship prebuilt for the platforms the engine runs.
     */
    #[DataProvider('wiredRuntimes')]
    public function test_the_right_runtimes_declare_a_build(string $runtime): void
    {
        $spec = RuntimeImageCatalog::spec($runtime, RuntimeImageCatalog::versions($runtime)[0]);
        $expected = in_array($runtime, ['php', 'ruby', 'python'], true);

        $this->assertSame($expected, $spec?->isBuilt(), "{$runtime}'s build declaration is wrong");
    }

    /**
     * Which bases a deploy waits for, and which it can start without.
     *
     * PHP and Python wait; Ruby does not. The dividing line is not how
     * important the image is but whether anything else installs the same
     * thing: Ruby's per-project Dockerfile carries its apt packages, so the
     * stock image is a complete answer, while PHP has no per-project
     * Dockerfile at all and Python's headers exist nowhere else. Deferring
     * Python was measured as a first deploy that failed and a second that
     * worked.
     */
    #[DataProvider('wiredRuntimes')]
    public function test_the_right_bases_are_declared_runnable(string $runtime): void
    {
        $spec = RuntimeImageCatalog::spec($runtime, RuntimeImageCatalog::versions($runtime)[0]);
        $expected = in_array($runtime, ['php', 'python'], true);

        $this->assertSame($expected, (bool) $spec?->runnable, "{$runtime}'s runnable flag is wrong");
    }

    /**
     * The flag has to be readable without inventing a version, because that is
     * how provisioning asks it — and a flag nothing reads is the failure this
     * whole file is written against.
     */
    public function test_the_runnable_flag_is_readable_per_runtime(): void
    {
        $this->assertTrue(RuntimeImageCatalog::runnable('php'));
        $this->assertTrue(RuntimeImageCatalog::runnable('python'));
        $this->assertFalse(RuntimeImageCatalog::runnable('ruby'));
        // A pull-only runtime has no build block, so it cannot be runnable.
        $this->assertFalse(RuntimeImageCatalog::runnable('go'));
        $this->assertFalse(RuntimeImageCatalog::runnable('nonexistent'));
    }

    /**
     * Java's versions do not share an image template, so each names its own.
     *
     * The one place the format needs a per-version `from`: maven and gradle
     * publish under different names, and a Gradle project built in the Maven
     * image has no gradle on PATH. Bending Java's versions into a shared
     * template would have meant either leaving it out of the catalogue or
     * describing it as something it is not.
     */
    public function test_java_versions_resolve_to_their_own_builder_images(): void
    {
        $maven = RuntimeImageCatalog::spec('java', JavaRuntime::MAVEN);
        $gradle = RuntimeImageCatalog::spec('java', JavaRuntime::GRADLE);

        $this->assertNotNull($maven);
        $this->assertNotNull($gradle);
        $this->assertNotSame($maven->from, $gradle->from, 'the override did not take');
        $this->assertStringStartsWith('maven:', $maven->from);
        $this->assertStringStartsWith('gradle:', $gradle->from);
    }

    /**
     * An override applies to the version it names and nothing else.
     */
    public function test_an_override_does_not_leak_to_other_versions(): void
    {
        $this->assertSame(
            RuntimeImageCatalog::spec('java', JavaRuntime::MAVEN)?->from,
            JavaRuntime::imageTag(JavaRuntime::MAVEN)
        );
        $this->assertSame(JavaRuntime::GRADLE_IMAGE, JavaRuntime::imageTag(JavaRuntime::GRADLE));
    }

    /**
     * Every repository the engine builds into is one the config declares.
     *
     * The whole point of the table: what gets built, and what gets recognised
     * as ours rather than pulled, come from the same place.
     */
    public function test_every_built_repository_is_declared_by_the_catalogue(): void
    {
        $repositories = RuntimeImageCatalog::builtRepositories();

        $this->assertSame('php', $repositories[PhpBaseImage::repository()] ?? null);
        $this->assertSame('ruby', $repositories[RubyBaseImage::repository()] ?? null);
    }

    public function test_php_falls_back_to_its_constants_when_the_catalogue_is_silent(): void
    {
        // Whatever the config says, the compiled-in answer has to remain a
        // usable one — it is what a host with a deleted or unparseable config
        // deploys on.
        $this->assertNotEmpty(PhpRuntime::MINORS);
        $this->assertContains(PhpRuntime::DEFAULT_MINOR, PhpRuntime::MINORS);
        $this->assertSame(
            PhpRuntime::MINORS,
            array_values(array_unique(PhpRuntime::MINORS))
        );
    }
}
