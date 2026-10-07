<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Runtime\Php\MysqlSidecar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every compose file the engine generates must survive a restart.
 *
 * Docker's own default is `restart: no`, so a generator that forgets the key
 * produces an app that comes up on the deploy that created it and never
 * again. It is invisible until the host reboots — which is exactly how it was
 * found: static sites stayed down while every other platform's came back.
 */
class GeneratedComposeRestartTest extends TestCase
{
    public function test_a_generator_that_says_nothing_still_gets_a_policy(): void
    {
        $yaml = GeneratedCompose::render(['image' => 'nginx:alpine', 'ports' => ['8080:80']]);

        $this->assertStringContainsString('restart: unless-stopped', $yaml);
    }

    /** Docker keeps an uncapped json-file log inside the account's quota. */
    public function test_the_generated_app_gets_a_rotated_log(): void
    {
        $app = \Symfony\Component\Yaml\Yaml::parse(DeployCompose::dockerfile('Dockerfile', 3000))['services']['app'];

        $this->assertSame(['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']], $app['logging']);
    }

    /** The hardener's rule: a service that publishes no port gets no default. */
    public function test_an_app_that_publishes_nothing_gets_no_default(): void
    {
        $yaml = GeneratedCompose::render(['image' => 'acme/worker']);

        $this->assertStringNotContainsString('restart:', $yaml);
    }

    /** The engine's own sidecars publish no port, and the app needs them up all the same. */
    public function test_the_engines_own_sidecars_get_the_default(): void
    {
        $decision = MysqlSidecar::withSidecar(['runtime' => 'nginx'], ['connection' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app', 'username' => 'app']);
        $compose = Yaml::parse(DeployCompose::framework($decision, 8080));

        $this->assertSame('unless-stopped', $compose['services']['app']['restart']);
        $this->assertSame('unless-stopped', $compose['services']['db']['restart']);
    }

    /** A service kept from the repository's compose arrives without a policy (its ports were stripped). */
    public function test_a_kept_sidecar_without_a_policy_gets_the_default(): void
    {
        $compose = Yaml::parse(GeneratedCompose::render(['image' => 'acme/app', 'ports' => ['8080:80']], ['sidecars' => [
            'cache' => ['image' => 'redis:7'],
            'search' => ['image' => 'meilisearch', 'restart' => ''],
            'init' => ['image' => 'busybox', 'restart' => 'no'],
            'queue' => ['image' => 'rabbitmq', 'restart' => 'always'],
        ]]));

        $this->assertSame('unless-stopped', $compose['services']['cache']['restart']);
        $this->assertSame('unless-stopped', $compose['services']['search']['restart']);
        $this->assertSame('no', $compose['services']['init']['restart']);
        $this->assertSame('always', $compose['services']['queue']['restart']);
    }

    public function test_a_generators_own_choice_is_respected(): void
    {
        // A one-shot service that should stop when it stops.
        $yaml = GeneratedCompose::render(['image' => 'acme/migrate', 'restart' => 'no']);

        $this->assertStringContainsString("restart: 'no'", $yaml);
        $this->assertStringNotContainsString('unless-stopped', $yaml);
    }

    public function test_every_shipped_generator_produces_a_restarting_app(): void
    {
        $generated = [
            'static' => DeployCompose::staticNginx(),
            'dockerfile' => DeployCompose::dockerfile('Dockerfile', 3000),
            'railpack' => DeployCompose::railpack('acme/app', 8080),
            'framework' => DeployCompose::framework(['runtime' => 'nginx'], 8080),
        ];

        foreach ($generated as $name => $yaml) {
            $this->assertStringContainsString('restart: unless-stopped', $yaml, $name);
        }
    }
}
