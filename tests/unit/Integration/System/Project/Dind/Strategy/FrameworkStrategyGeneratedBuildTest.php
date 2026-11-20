<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\DockerfileBuilder;
use App\System\Project\Dind\Strategy\FrameworkStrategy;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A recipe the host does not compile is built into an image: the entrypoint, a generated Dockerfile with its
 * ignore file, and a run file that builds it, in that order.
 */
class FrameworkStrategyGeneratedBuildTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_a_plain_node_server_is_built_from_a_generated_dockerfile(): void
    {
        $this->putFile('package.json', '{"name":"x","scripts":{"start":"node server.js"},"dependencies":{"koa":"2"}}');
        $this->putFile('server.js', "require('http').createServer().listen(3000)\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $this->assertSame('node', $decision['strategy']);

        (new FrameworkStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001], null, true)))->apply($decision, $this->dir, '1001:1001');

        $kinds = array_map(static fn (string $event): string => strtok((string) preg_replace('/^\[\d+ log lines\] /', '', $event), "\n"), $this->events);
        $dockerfile = "write {$this->dir}/".DockerfileBuilder::FILENAME.' chown=1001:1001 mode=644';
        $this->assertSame('entrypoint', $kinds[0]);
        $this->assertContains($dockerfile, $kinds);
        $this->assertContains("write {$this->dir}/".DockerfileBuilder::FILENAME.'.dockerignore chown=1001:1001 mode=644', $kinds);
        $this->assertLessThan(array_search("compose {$this->dir} chown=1001:1001", $kinds, true), array_search($dockerfile, $kinds, true));

        $compose = Yaml::parse($this->payloadOf("compose {$this->dir} chown=1001:1001"));
        $this->assertSame(DockerfileBuilder::FILENAME, $compose['services']['app']['build']['dockerfile']);
        $this->assertStringContainsString('FROM node:22', $this->payloadOf($dockerfile));
    }

    private function payloadOf(string $kind): string
    {
        foreach ($this->events as $event) {
            $body = (string) preg_replace('/^\[\d+ log lines\] /', '', $event);
            if (strtok($body, "\n") === $kind) {
                return substr($body, strlen($kind) + 1);
            }
        }
        $this->fail("no {$kind} was recorded");
    }
}
