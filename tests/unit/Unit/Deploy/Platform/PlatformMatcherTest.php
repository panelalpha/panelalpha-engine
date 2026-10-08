<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformMatcher;
use App\Lib\Deploy\Platform\PlatformProbe;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\TestCase;

/**
 * The predicate language manifests are written in.
 *
 * No Laravel boot — the matcher reads a directory and nothing else.
 */
class PlatformMatcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/platform-matcher-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    private function write(string $relative, string $contents = ''): void
    {
        $path = $this->tmpDir . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    private function context(): ProjectContext
    {
        $files = [];
        foreach (scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $files[strtolower($entry)] = true;
            }
        }

        return ProjectContext::make($this->tmpDir, $files);
    }

    public function test_all_keys_of_a_node_must_hold(): void
    {
        $this->write('composer.json', '{}');
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['file' => 'composer.json'], $this->context()));
        $this->assertFalse($matcher->matches(
            ['all' => [['file' => 'composer.json'], ['file' => 'artisan']]],
            $this->context()
        ));
    }

    /**
     * The rule that makes the Static platform expressible: an index.html and
     * none of the manifests that would make the project an application.
     */
    public function test_none_excludes_a_project_that_has_any_listed_file(): void
    {
        $this->write('index.html', '<h1>hi</h1>');
        $matcher = new PlatformMatcher();

        $node = [
            'file' => 'index.html',
            'none' => [['file' => 'composer.json'], ['file' => 'package.json']],
        ];
        $this->assertTrue($matcher->matches($node, $this->context()));

        $this->write('package.json', '{}');
        $this->assertFalse($matcher->matches($node, $this->context()));
    }

    public function test_not_negates_a_single_node(): void
    {
        $this->write('go.mod', 'module x');
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['not' => ['file' => 'cargo.toml']], $this->context()));
        $this->assertFalse($matcher->matches(['not' => ['file' => 'go.mod']], $this->context()));
    }

    public function test_a_scalar_condition_accepts_a_list_of_alternatives(): void
    {
        $this->write('build.gradle.kts', '');
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(
            ['file' => ['pom.xml', 'build.gradle', 'build.gradle.kts']],
            $this->context()
        ));
    }

    public function test_dep_and_script_read_package_json(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['next' => '^15.0.0'],
            'scripts' => ['build' => 'next build'],
        ]));
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['dep' => 'next'], $this->context()));
        $this->assertTrue($matcher->matches(['script' => 'build'], $this->context()));
        $this->assertFalse($matcher->matches(['script' => 'start'], $this->context()));
    }

    public function test_glob_matches_any_extension_of_a_config_stem(): void
    {
        $this->write('next.config.mjs', 'export default {}');
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['glob' => 'next.config'], $this->context()));
    }

    public function test_contains_can_test_a_file_with_a_regex(): void
    {
        $this->write('astro.config.mjs', "export default { output: 'server' }");
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches([
            'contains' => ['glob' => 'astro.config', 'pattern' => "output\\s*:\\s*['\"]server['\"]", 'regex' => true],
        ], $this->context()));
    }

    /**
     * An empty predicate must not mean "everything". A manifest whose detect
     * block decoded to nothing would otherwise claim every project on the box.
     */
    public function test_an_empty_node_matches_nothing(): void
    {
        $this->assertFalse((new PlatformMatcher())->matches([], $this->context()));
    }

    /**
     * Ignoring an unknown key would widen the predicate rather than narrow
     * it — the remaining keys would still pass.
     */
    public function test_an_unknown_condition_is_an_error_not_a_no_op(): void
    {
        $this->expectException(ManifestException::class);
        (new PlatformMatcher())->matches(['fille' => 'composer.json'], $this->context());
    }

    public function test_a_probe_can_contribute_fields_to_the_match(): void
    {
        $probe = new class implements PlatformProbe {
            public function id(): string
            {
                return 'demo';
            }

            public function evaluate(ProjectContext $context): bool|array
            {
                return ['workspace_relative' => 'apps/web'];
            }
        };

        $matcher = new PlatformMatcher(['demo' => $probe]);

        $this->assertTrue($matcher->matches(['probe' => 'demo'], $this->context()));
        $this->assertSame(['workspace_relative' => 'apps/web'], $matcher->lastProbeData());
    }

    public function test_an_unknown_probe_is_an_error(): void
    {
        $this->expectException(ManifestException::class);
        (new PlatformMatcher())->matches(['probe' => 'nope'], $this->context());
    }
}
