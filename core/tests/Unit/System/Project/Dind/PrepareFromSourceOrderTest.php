<?php

namespace Tests\Unit\System\Project\Dind;

use PHPUnit\Framework\TestCase;

/**
 * Port detection reads host ports compose takes from `.env`, so `.env` has to
 * be written first: Poznote's `${HTTP_WEB_PORT}` is 8040 from .env.template,
 * and the domain was routed to 80 because detection ran before the copy.
 */
class PrepareFromSourceOrderTest extends TestCase
{
    public function test_port_detection_runs_after_the_env_is_written(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 5) . '/app/System/Project/Dind/PrepareFromSource.php');

        $env = strpos($source, '$this->dind->applyProjectEnvVars(');
        $detect = strpos($source, '$this->dind->networking()->detectAndCreateProxyRules(');
        $this->assertNotFalse($env);
        $this->assertNotFalse($detect);
        $this->assertGreaterThan($env, $detect);
    }
}
