<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use Tests\TestCase;

/**
 * Pins the compose file and the log {@see DockerfileStrategy::apply()} writes for a repository
 * Dockerfile: build args, declared volumes, the terminal settings and Rails' environment.
 */
class DockerfileStrategyApplyTest extends TestCase
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

    public function test_a_rails_dockerfile_with_build_args_volumes_and_a_terminal(): void
    {
        $this->putFile('Dockerfile', "FROM ruby:3.3\nVOLUME /app/storage\nEXPOSE 3000\n");
        $this->putFile('Gemfile', "source 'https://rubygems.org'\ngem 'rails', '~> 7.1'\n");
        $this->putFile('config/application.rb', "module X; end\n");
        $this->putFile('.env', "APP_URL=http://localhost\n");
        $this->putFile('docker-compose.yml', "services:\n  web:\n    build: .\n    tty: true\n    stdin_open: true\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['strategy'] = 'dockerfile';
        $decision['dockerfile'] = 'Dockerfile';
        $decision['port_hint'] = -1;
        $decision['build_args'] = ['BASE' => 'apache', 'N' => 3, '' => 'x', 'EMPTY' => '', 'LIST' => ['a']];

        (new DockerfileStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001])))->apply($decision, $this->dir, '1001:1001');

        $this->assertMatchesGolden('dockerfile-rails-terminal');
    }

    public function test_a_plain_dockerfile_on_its_declared_port(): void
    {
        $this->putFile('Dockerfile', "FROM nginx:alpine\nEXPOSE 8080\n");
        $decision = DetectProjectStrategy::detect($this->dir);

        (new DockerfileStrategy($this->harnessDind([], null, null, null, null, null)))->apply($decision, $this->dir, null);

        $this->assertMatchesGolden('dockerfile-plain');
    }
}
