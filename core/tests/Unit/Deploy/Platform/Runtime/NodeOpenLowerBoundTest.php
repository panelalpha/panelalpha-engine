<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use App\Lib\Deploy\Platform\Runtime\RuntimeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * engine#279: `>=14` is a floor, not a pin. It used to resolve to the oldest
 * shipped major (18, EOL) instead of the default.
 */
class NodeOpenLowerBoundTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/node-range-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function constraints(): array
    {
        return [
            'open floor below the default' => ['>=14', '20'],
            'open floor with spaces and a full version' => ['>= 14.17.0', '20'],
            'strict floor' => ['>16', '20'],
            'open floor above the default' => ['>=24', '24'],
            'open floor at a non-default shipped major' => ['>=22', '22'],
            'bounded range takes the newest shipped major in it' => ['>=16 <21', '20'],
            'bounded range, inclusive ceiling' => ['>=18 <=22', '22'],
            'bounded range, ceiling below the default' => ['>=14 <19', '18'],
            'caret pin' => ['^18', '18'],
            'x-range pin' => ['18.x', '18'],
            'tilde pin' => ['~18.2', '18'],
            'exact pin' => ['18', '18'],
            'alternatives are not an open bound' => ['^20.19.0 || >=22.12.0', '20'],
        ];
    }

    #[DataProvider('constraints')]
    public function test_engines_node_resolves_to(string $constraint, string $expected): void
    {
        file_put_contents(
            $this->tmpDir . '/package.json',
            json_encode(['engines' => ['node' => $constraint]])
        );

        $requirement = RuntimeRegistry::get('node')->resolve(
            ProjectContext::make($this->tmpDir, ['package.json' => true])
        );

        $this->assertSame($expected, $requirement?->version);
        $this->assertSame('package.json engines.node', $requirement?->source);
        $this->assertStringStartsWith('node:' . $expected . '-', NodeRuntime::imageFor($this->tmpDir));
    }

    public function test_an_open_floor_in_nvmrc_resolves_the_same_way(): void
    {
        file_put_contents($this->tmpDir . '/package.json', '{}');
        file_put_contents($this->tmpDir . '/.nvmrc', ">=14\n");

        $requirement = RuntimeRegistry::get('node')->resolve(
            ProjectContext::make($this->tmpDir, ['package.json' => true, '.nvmrc' => true])
        );

        $this->assertSame('20', $requirement?->version);
        $this->assertSame('.nvmrc', $requirement?->source);
    }
}
