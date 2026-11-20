<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppConfigCommandsFixtures;

/**
 * An app config's staged commands, in the scripts the deploy actually runs.
 *
 * Until now they reached the two host stages and were dropped on the four
 * container ones, so a project could declare an install-stage command and
 * watch it never run. These assert the whole schedule.
 */
class AppConfigCommandsTest extends TestCase
{
    use AppConfigCommandsFixtures;

    public function test_an_app_config_serve_placeholder_is_resolved_for_the_project(): void
    {
        $dir = sys_get_temp_dir() . '/pa-serve-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{"scripts":{"start":"node server.js"}}');
        file_put_contents($dir . '/yarn.lock', "# yarn lockfile v1\n");
        $appConfig = $this->appConfig(
            "  - {id: serve, stage: start, serve: true, run: \"{{js.start:node index.js}}\"}\n"
        );

        try {
            $script = StageScript::render(
                PlatformRegistry::find('express'),
                \App\Lib\Deploy\Platform\ProjectContext::at($dir),
                ['serve' => 'node bin/control.sh'],
                [],
                [],
                $appConfig
            );
        } finally {
            @unlink($dir . '/package.json');
            @unlink($dir . '/yarn.lock');
            @rmdir($dir);
        }

        $this->assertStringContainsString('exec yarn start', $script);
        $this->assertStringNotContainsString('{{js.', $script);
    }
}
