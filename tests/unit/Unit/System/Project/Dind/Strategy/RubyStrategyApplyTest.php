<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\Strategy\RubyStrategy;
use Tests\TestCase;

/**
 * Pins what {@see RubyStrategy::apply()} writes for a Rack app, a Rails app and a mounted project.
 */
class RubyStrategyApplyTest extends TestCase
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

    public function test_a_rack_app_gets_a_dockerfile_on_the_shared_ruby_base(): void
    {
        $this->sinatra();

        $this->apply(DetectProjectStrategy::detect($this->dir), 'panelalpha/ruby:3.3-pa1');

        $this->assertMatchesGolden('ruby-rack');
    }

    public function test_a_rails_app_gets_the_host_initializer_and_a_generated_secret(): void
    {
        $this->putFile('Gemfile', "source 'https://rubygems.org'\ngem 'rails', '~> 7.1'\ngem 'pg'\n");
        $this->putFile('config.ru', "run Rails.application\n");
        $this->putFile('config/application.rb', "module X; end\n");
        $this->putFile('config/initializers/x.rb', "\n");
        $this->putFile('bin/rails', "#!/usr/bin/env ruby\n");
        $this->putFile('.env.example', "RAILS_RELATIVE_URL_ROOT=/app\n");
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['port_hint'] = 0;

        $this->apply($decision, null);

        $this->assertMatchesGolden('ruby-rails');
    }

    public function test_a_mounted_strategy_runs_the_base_image_with_the_server_command(): void
    {
        $this->sinatra();
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['strategy'] = 'python';
        $decision['port_hint'] = 4567;

        $this->apply($decision, 'panelalpha/ruby:3.3-pa1');

        $this->assertMatchesGolden('ruby-mounted');
    }

    public function test_a_mounted_strategy_falls_back_to_the_stock_image(): void
    {
        $this->sinatra();
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['strategy'] = 'python';

        $this->apply($decision, null);

        $this->assertMatchesGolden('ruby-mounted-stock');
    }

    private function sinatra(): void
    {
        $this->putFile('Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\ngem 'sqlite3'\n");
        $this->putFile('config.ru', "require './app'\nrun Sinatra::Application\n");
        $this->putFile('app.rb', "require 'sinatra'\n");
    }

    /** @param  array<string, mixed>  $decision */
    private function apply(array $decision, ?string $base): void
    {
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('ensureRubyBaseImage')->willReturnCallback(function (string $image, array $packages) use ($base): ?string {
            $this->record('ensureRubyBaseImage', [$image, $packages]);

            return $base;
        });

        (new RubyStrategy($this->harnessDind(['UID' => 1001, 'GID' => 1001], null, true, $inner)))
            ->apply($decision, $this->dir, '1001:1001');
    }
}
