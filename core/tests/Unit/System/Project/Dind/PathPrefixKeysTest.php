<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Compose\DeployCompose;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\ProjectFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A service the engine generates gets the full URL in BASE_URL only when the
 * project does not use BASE_URL as a sub-path (DVinyl-shaped Express apps).
 */
class PathPrefixKeysTest extends TestCase
{
    private const PROJECT_DIR = '/home/acct/project';

    /** @param array<string, string> $files */
    private function strategy(array $files): DeployStrategy
    {
        $tree = $this->createStub(ProjectFiles::class);
        $tree->method('readIn')->willReturnCallback(
            static fn (string $dir, string $name): ?string => $files[$name] ?? null
        );
        $dind = $this->createStub(Dind::class);
        $dind->method('projectTree')->willReturn($tree);

        return new DeployStrategy($dind);
    }

    /** @return array<string, mixed> */
    private function environment(array $files): array
    {
        $decision = $this->strategy($files)->withPathPrefixKeys(['strategy' => 'express'], self::PROJECT_DIR);
        $compose = Yaml::parse(DeployCompose::framework($decision, 3000, 'https://app.example.com'));

        return $compose['services']['app']['environment'];
    }

    public function test_a_blank_base_url_in_the_template_keeps_the_full_url_out(): void
    {
        $env = $this->environment([
            '.env.example' => "# Base URL for serving on a sub-path, leave empty to serve from root (default)\nBASE_URL=\n",
            '.env' => "BASE_URL=\n",
        ]);

        $this->assertArrayNotHasKey('BASE_URL', $env);
        $this->assertSame('https://app.example.com', $env['APP_URL']);
    }

    public function test_a_path_value_keeps_the_full_url_out(): void
    {
        $this->assertArrayNotHasKey('BASE_URL', $this->environment(['.env.example' => "BASE_URL=/vinyl\n"]));
    }

    public function test_a_url_value_or_no_key_still_gets_the_full_url(): void
    {
        $this->assertSame('https://app.example.com', $this->environment(['.env.example' => "BASE_URL=http://localhost:3000\n"])['BASE_URL']);
        $this->assertSame('https://app.example.com', $this->environment([])['BASE_URL']);
    }
}
