<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformMatcher;
use App\Lib\Deploy\Platform\ProjectContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `contains`, `all`, `any` and `dir` conditions at their edges: malformed specs, empty lists, empty files.
 */
class PlatformMatcherEdgeCasesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/platform-matcher-edge-' . bin2hex(random_bytes(8));
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

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function malformed(): array
    {
        return [
            'contains without a spec' => [['contains' => []], "'contains' needs a non-empty 'pattern'"],
            'contains with an empty pattern' => [['contains' => ['path' => 'a.txt', 'pattern' => '']], "'contains' needs a non-empty 'pattern'"],
            'contains with no path or glob' => [['contains' => ['pattern' => 'x']], "'contains' needs a 'path' or a 'glob'"],
            'contains with a non-string path' => [['contains' => ['path' => 1, 'pattern' => 'x']], "'contains' needs a 'path' or a 'glob'"],
            'all that is not a list' => [['all' => 'file'], "'all' must be a list of conditions"],
            'any that is not a list' => [['any' => 'file'], "'any'/'none' must be a list of conditions"],
            'none that is not a list' => [['none' => 'file'], "'any'/'none' must be a list of conditions"],
        ];
    }

    /** @param array<string, mixed> $node */
    #[DataProvider('malformed')]
    public function test_a_malformed_condition_is_a_manifest_error(array $node, string $message): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage($message);

        (new PlatformMatcher())->matches($node, $this->context());
    }

    public function test_contains_answers_false_for_an_empty_or_missing_file(): void
    {
        $this->write('empty.txt');
        $matcher = new PlatformMatcher();

        $this->assertFalse($matcher->matches(['contains' => ['path' => 'empty.txt', 'pattern' => 'x']], $this->context()));
        $this->assertFalse($matcher->matches(['contains' => ['path' => 'missing.txt', 'pattern' => 'x']], $this->context()));
        $this->assertFalse($matcher->matches(['contains' => ['glob' => 'nothing.config', 'pattern' => 'x']], $this->context()));
    }

    public function test_contains_reads_a_config_by_its_stem_and_escapes_slashes_in_a_regex(): void
    {
        $this->write('next.config.mjs', "export default { output: 'export', path: 'a/b' }");
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => "output: 'export'"]], $this->context()));
        $this->assertTrue($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => 'a/b', 'regex' => true]], $this->context()));
        $this->assertFalse($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => '(', 'regex' => true]], $this->context()));
        $this->assertTrue($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => 'a/b', 'regex' => 'yes']], $this->context()));
        $this->assertFalse($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => 'a.b', 'regex' => 'yes']], $this->context()));
        $this->assertTrue($matcher->matches(['contains' => ['glob' => 'next.config', 'pattern' => 'a.b', 'regex' => true]], $this->context()));
        $this->assertTrue($matcher->matches(['contains' => ['path' => 'next.config.mjs', 'glob' => 'nope', 'pattern' => 'a/b']], $this->context()));
    }

    public function test_empty_lists_and_directories(): void
    {
        mkdir($this->tmpDir . '/sub');
        $matcher = new PlatformMatcher();

        $this->assertTrue($matcher->matches(['all' => []], $this->context()));
        $this->assertFalse($matcher->matches(['any' => []], $this->context()));
        $this->assertTrue($matcher->matches(['none' => []], $this->context()));
        $this->assertFalse($matcher->matches(['all' => ['file']], $this->context()));
        $this->assertTrue($matcher->matches(['dir' => 'sub'], $this->context()));
        $this->assertFalse($matcher->matches(['dir' => ['', 'nope']], $this->context()));
        $this->assertFalse($matcher->matches(['not' => 'sub'], $this->context()));
    }
}
