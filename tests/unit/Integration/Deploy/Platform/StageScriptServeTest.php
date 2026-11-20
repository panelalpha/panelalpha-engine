<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;
use Tests\Support\StageScriptServeFixtures;

/**
 * The entrypoint's last lines: which serve command wins, and what a replaced start stage without one does.
 */
class StageScriptServeTest extends TestCase
{
    use StageScriptServeFixtures;

    public function test_an_app_configs_serve_placeholder_resolves_against_the_context(): void
    {
        $dir = sys_get_temp_dir() . '/pa-stage-serve-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{"scripts":{"start":"node server.js"}}');

        try {
            $config = $this->appConfig('{{js.start:node index.js}}');
            $withContext = StageScript::render($this->manifest(), ProjectContext::at($dir), [], [], [], $config);
            $withoutContext = StageScript::render($this->manifest(), null, [], [], [], $config);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame(["pa_step start 'own'", 'exec npm start'], $this->tail($withContext, 2));
        $this->assertSame(["pa_step start 'own'", 'exec {{js.start:node index.js}}'], $this->tail($withoutContext, 2));
    }
}
