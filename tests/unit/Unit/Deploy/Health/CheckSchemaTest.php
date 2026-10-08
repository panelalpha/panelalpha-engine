<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\HealthCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The schema an editor validates a check file against, and the parser that
 * reads it, have to be the same vocabulary.
 *
 * Nothing tied them together, and the drift was real rather than theoretical:
 * `expect` gained `path` and `json` in the parser and the schema was updated
 * beside it, but nothing would have failed if it had not been. Worse, the
 * shipped check files were never validated at all -- so a `$schema` reference
 * pointing at a file that does not exist, which is what a recipe's first check
 * shipped with, went unnoticed until it was read by eye.
 *
 * The same shape {@see \Tests\Unit\Deploy\Platform\SourceRecipeTest} already
 * uses for the manifest schemas: the lists are asserted equal, so adding a key
 * to one and forgetting the other is a failing test rather than a silent
 * divergence.
 */
class CheckSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function schema(): array
    {
        $path = dirname(__DIR__, 5) . '/core/resources/checks/_schema.json';
        $this->assertFileExists($path);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** Every check file the engine ships or a recipe does, by path. */
    private function checkFiles(): array
    {
        $files = [];
        foreach ([CheckRegistry::directory()] as $root) {
            foreach (glob($root . '/*/*.yaml') ?: [] as $file) {
                $files[] = $file;
            }
        }

        // Recipe checks, which live under a `checks/` directory beside a recipe.
        $sources = dirname(__DIR__, 5) . '/core/resources/sources';
        foreach (glob($sources . '/*/*/*/checks/*/*.yaml') ?: [] as $file) {
            $files[] = $file;
        }

        return $files;
    }

    /**
     * What the parser accepts and what the schema declares are one list.
     *
     * `KNOWN_KEYS` and `EXPECT_KEYS` are private on purpose -- the vocabulary
     * is meant to be small and changed deliberately -- so they are read by
     * asking a check what it accepts rather than by reflection.
     */
    public function test_the_schema_and_the_parser_agree_on_expect(): void
    {
        $schema = $this->schema();
        $declared = array_keys($schema['properties']['expect']['properties']);
        sort($declared);

        // Derived by trying, with one wrinkle: `path` alone is deliberately
        // refused -- it says where to look, not what must be there, so a check
        // carrying only that would pass whatever it found. Every key is
        // therefore probed *beside* a real assertion, which is also the only
        // way any of them is written in practice.
        $accepted = [];
        foreach (['status', 'status_not', 'body', 'body_not', 'path', 'json'] as $key) {
            $value = match ($key) {
                'status', 'status_not' => [200],
                'body', 'body_not' => ['x'],
                'path' => '/x',
                default => ['a' => 'b'],
            };
            $expect = $key === 'status' ? [$key => $value] : ['status' => [200], $key => $value];
            try {
                HealthCheck::fromArray([
                    'id' => 'probe',
                    'message' => 'probe',
                    'expect' => $expect,
                ], 'test', 'test/probe.yaml');
                $accepted[] = $key;
            } catch (\Throwable $e) {
                // Refused: not part of the vocabulary.
            }
        }
        sort($accepted);

        $this->assertSame($declared, $accepted, 'the schema and the parser disagree on expect');
    }

    /** The id grammar is the filename rule, so a mismatch is caught early. */
    public function test_the_schema_patterns_match_the_parser(): void
    {
        $schema = $this->schema();

        foreach (['a-check-1', 'x'] as $ok) {
            $this->assertMatchesRegularExpression('/' . $schema['properties']['id']['pattern'] . '/', $ok);
        }
        foreach (['-leading', 'UPPER', 'has_underscore'] as $bad) {
            $this->assertDoesNotMatchRegularExpression('/' . $schema['properties']['id']['pattern'] . '/', $bad);
        }
    }

    /**
     * Every shipped check file names a schema that exists.
     *
     * A `$schema` in the first line is the whole of what an editor obeys, and a
     * wrong one is invisible: the file parses, the engine reads it, and only
     * the person editing it loses completion and validation without knowing.
     * A recipe's first check shipped with `../_schema.json`, which resolves to
     * nothing from `sources/<host>/<owner>/<repo>/checks/<group>/`.
     */
    public function test_every_check_file_names_a_reference_that_resolves(): void
    {
        $files = $this->checkFiles();
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (!preg_match('#yaml-language-server:\s*\$schema=(\S+)#', $contents, $m)) {
                continue;
            }
            $reference = $m[1];
            if (!str_starts_with($reference, '.') && !str_starts_with($reference, '/')) {
                // A URL is an editor's own business.
                continue;
            }
            $resolved = realpath(dirname($file) . '/' . $reference);

            $this->assertNotFalse(
                $resolved,
                str_replace(dirname(__DIR__, 4), '', $file) . " names \$schema={$reference}, which does not resolve"
            );
        }
    }

    /**
     * Every shipped and recipe check parses, and its `expect` uses the
     * vocabulary the schema describes.
     *
     * The registry already loads the shipped ones; this is what makes the
     * recipe ones and the schema agree with them in one place.
     */
    public function test_every_check_file_parses_under_the_real_parser(): void
    {
        foreach ($this->checkFiles() as $file) {
            $raw = Yaml::parseFile($file);
            $this->assertIsArray($raw, $file);

            $group = basename(dirname($file));
            // Throws on anything the schema would refuse but the parser enforces.
            $check = HealthCheck::fromArray($raw, $group, $file);
            $this->assertSame(basename($file, '.yaml'), $check->id, "{$file}: id and filename disagree");
        }
    }
}
