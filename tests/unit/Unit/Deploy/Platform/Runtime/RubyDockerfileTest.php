<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\Ruby\RailsProxy;
use PHPUnit\Framework\TestCase;
use Tests\Support\RubyDockerfileFixtures;

class RubyDockerfileTest extends TestCase
{
    use RubyDockerfileFixtures;

    public function test_rails_host_initializer_allows_only_public_host(): void
    {
        $ruby = RailsProxy::hostAuthorizationInitializer('https://app.example.test/path');

        $this->assertStringContainsString("hosts << 'app.example.test'", $ruby);
        // An empty config.hosts means "any Host" in production; appending to it
        // would narrow the app to a single name.
        $this->assertStringContainsString('unless hosts.empty?', $ruby);
        $this->assertStringNotContainsString('config.hosts.clear', $ruby);
        $this->assertStringNotContainsString('/.+/', $ruby);
    }

    public function test_rails_host_initializer_does_nothing_where_rails_is_not_loaded(): void
    {
        $ruby = RailsProxy::hostAuthorizationInitializer('https://app.example.test');

        $this->assertMatchesRegularExpression(
            '/^if defined\?\(Rails\) && Rails\.respond_to\?\(:application\)\n  Rails\.application\.config\.after_initialize do$/m',
            $ruby
        );
    }

    public function test_rails_behind_the_proxy_does_not_pin_hsts_on_a_self_signed_cert(): void
    {
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'true'],
            RailsProxy::sslEnvironment('https://app.example.test')
        );
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'false'],
            RailsProxy::sslEnvironment('http://app.example.test')
        );
        $this->assertSame(
            ['RAILS_FORCE_SSL' => 'false', 'RAILS_ASSUME_SSL' => 'false'],
            RailsProxy::sslEnvironment(null)
        );
    }
}
