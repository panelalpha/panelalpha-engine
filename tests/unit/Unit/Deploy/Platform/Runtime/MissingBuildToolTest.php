<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\MissingBuildTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MissingBuildToolTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function failures(): array
    {
        return [
            'dash' => ['/bin/sh: 1: git: not found', 'git'],
            'npm script' => ["> masterzap@1.0.0 build\nsh: 1: python3: not found", 'python3'],
            'bash script' => ['./scripts/install-subfinder.sh: line 42: curl: command not found', 'curl'],
            'node spawn' => ['Error: spawn git ENOENT', 'git'],
            'node spawnSync' => ['Error: spawnSync make ENOENT', 'make'],
            'yarn' => ["error Couldn't find the binary git", 'git'],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_missing_program_is_named(string $failure, string $tool): void
    {
        $this->assertSame($tool, MissingBuildTool::in($failure));
    }

    public function test_programs_the_full_image_lacks_too_are_not_named(): void
    {
        $this->assertNull(MissingBuildTool::in('scripts/prepare-standalone.sh: line 10: rsync: command not found'));
        $this->assertNull(MissingBuildTool::in('sh: 1: python: not found'));
        $this->assertNull(MissingBuildTool::in('sh: 1: bun: not found'));
    }

    public function test_a_failure_naming_no_program_is_not_a_missing_tool(): void
    {
        $this->assertNull(MissingBuildTool::in('Error: Cannot find module \'@rollup/rollup-linux-x64-gnu\''));
        $this->assertNull(MissingBuildTool::in('Type error: Property \'git\' does not exist on type'));
    }
}
