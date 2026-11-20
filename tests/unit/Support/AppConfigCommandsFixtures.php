<?php

namespace Tests\Support;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;

trait AppConfigCommandsFixtures
{
    private function appConfig(string $commands, string $hooks = ''): AppConfig
    {
        $page = "- `" . AppConfig::YAML_FILENAME . "`\n```yaml\n"
            . "commands:\n" . $commands . "```\n" . $hooks;

        return AppConfig::fromMarkdown($page) ?? $this->fail('app config did not parse');
    }
}
