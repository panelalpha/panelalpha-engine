<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;

trait StageScriptServeFixtures
{
    private function manifest(): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => [
                ['id' => 'warm', 'stage' => 'start', 'run' => 'app warm'],
                ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
            ],
        ]);
    }

    private function appConfig(string $run): AppConfig
    {
        $page = "- `" . AppConfig::YAML_FILENAME . "`\n```yaml\ncommands:\n"
            . "  - {id: own, stage: start, serve: true, run: \"{$run}\"}\n```\n";

        return AppConfig::fromMarkdown($page) ?? $this->fail('app config did not parse');
    }

    /** @return list<string> */
    private function tail(string $script, int $lines): array
    {
        return array_slice(explode("\n", rtrim($script, "\n")), -$lines);
    }
}
