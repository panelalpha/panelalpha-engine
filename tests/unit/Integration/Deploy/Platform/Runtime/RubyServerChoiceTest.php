<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\Ruby\RubyApp;
use App\Lib\Deploy\Platform\Runtime\Ruby\RubyServer;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * Unicorn when the Gemfile names it, and rackup on the port when nothing names a server.
 */
class RubyServerChoiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-ruby-server-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_unicorn_app_runs_unicorn_with_its_config(): void
    {
        file_put_contents($this->dir.'/Gemfile', "source 'https://rubygems.org'\ngem 'unicorn'\n");

        $this->assertSame('["bundle","exec","unicorn","-c","config/unicorn.rb"]', RubyServer::command(RubyApp::at($this->dir), 3000));
    }

    public function test_an_app_naming_no_server_is_rackup_on_the_port(): void
    {
        file_put_contents($this->dir.'/Gemfile', "source 'https://rubygems.org'\ngem 'sinatra'\n");

        $this->assertSame(
            '["sh","-c","bundle exec rackup config.ru -o '.TestNetwork::ANY.' -p 4567"]',
            RubyServer::command(RubyApp::at($this->dir), 4567)
        );
    }
}
