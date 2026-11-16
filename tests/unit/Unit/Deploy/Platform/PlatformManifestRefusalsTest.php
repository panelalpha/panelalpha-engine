<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `requires` and `check_skip` refusals no other test pins, word for word.
 */
class PlatformManifestRefusalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CheckRegistry::flush();
        SourceRecipes::flush();
    }

    /** @param array<string, mixed> $raw */
    private function manifest(array $raw): PlatformManifest
    {
        return PlatformManifest::fromArray(array_merge([
            'id' => 'probe',
            'label' => 'Probe',
            'strategy' => 'probe',
            'priority' => 0,
            'runtime' => 'node',
            'detect' => ['file' => 'x'],
        ], $raw), 'probe.yaml');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        $skip = ['check' => '_baseline/no-directory-listing', 'reason' => 'r'];

        return [
            'runtime name' => [['requires' => ['Node' => 'runtime']], "probe: 'Node' is not a valid runtime name"],
            'numeric runtime name' => [['requires' => [0 => 'runtime']], "probe: '0' is not a valid runtime name"],
            'spec not an object' => [['requires' => ['node' => 5]], 'probe: requires.node: must be a role name or an object'],
            'role unknown' => [['requires' => ['node' => ['role' => 'dev', 'x' => 1]]], "probe: requires.node: 'role' must be 'runtime' or 'build'"],
            'unknown keys' => [['requires' => ['node' => ['role' => 'build', 'version' => 1, 'x' => 2]]], 'probe: requires.node: unknown key(s) version, x'],
            'covered_by not a reference' => [['check_skip' => [$skip + ['covered_by' => 'nope']]], "probe: check_skip '_baseline/no-directory-listing': covered_by must name one check as 'group/id'"],
            'covered_by not a string' => [['check_skip' => [$skip + ['covered_by' => 5]]], "probe: check_skip '_baseline/no-directory-listing': covered_by must name one check as 'group/id'"],
            'reason before covered_by' => [['check_skip' => [['check' => '_baseline/no-directory-listing', 'reason' => ' ', 'covered_by' => 5]]], "probe: check_skip '_baseline/no-directory-listing': a reason is required, and it is shown in the health report"],
            'entries before the registry' => [['check_skip' => [['check' => 'nope/none', 'reason' => 'r'], ['check' => 'x']]], "probe: check_skip: 'check' must name one check as 'group/id'"],
        ];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('refusals')]
    public function test_a_malformed_manifest_is_refused_with_its_message(array $raw, string $message): void
    {
        try {
            $this->manifest($raw);
            $this->fail('expected a ManifestException');
        } catch (ManifestException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_requirements_are_normalised(): void
    {
        $this->assertSame(
            ['node' => ['role' => 'build', 'optional' => false], 'python' => ['role' => 'runtime', 'optional' => true]],
            $this->manifest(['requires' => ['node' => 'build', 'python' => ['optional' => 1]]])->requires
        );
    }
}
