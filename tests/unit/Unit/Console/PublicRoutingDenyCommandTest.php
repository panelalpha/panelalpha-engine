<?php

namespace Tests\Unit\Console;

use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Project\PublicRouting\PublicRoutingGrants;
use App\Models\Setting;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/**
 * `system:public-routing:deny` says what it took back, asks for a redeploy only when the main domain moved, and says
 * so when there was nothing to take.
 */
class PublicRoutingDenyCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const HOST = 'host.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['cert_domain' => self::HOST]);
        $this->app->instance(System::class, new RecordingSystem);
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $this->makeMainDomain($user, 'shop.example.test');
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_second_deny_says_nothing_changed_and_asks_for_no_redeploy(): void
    {
        (new PublicRoutingGrants)->allow('shop', PublicRoutingGrants::KINDS);

        $first = $this->deny();
        $second = $this->deny();

        $this->assertStringContainsString('shop is now allowed nothing.', $first);
        $this->assertStringNotContainsString('redeploy', $first, 'the project never left its own name');
        $this->assertStringContainsString('Nothing changed: shop is allowed nothing.', $second);
        $this->assertStringNotContainsString('now allowed', $second);
        $this->assertStringNotContainsString('redeploy', $second);
    }

    public function test_denying_what_was_never_allowed_changes_nothing(): void
    {
        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::HOST_DOMAIN]);

        $output = $this->deny(['--ports' => true]);

        $this->assertStringContainsString("Nothing changed: shop is allowed the server's own name.", $output);
        $this->assertSame([PublicRoutingGrants::HOST_DOMAIN], (new PublicRoutingGrants)->of('shop'));
    }

    public function test_a_project_moved_off_the_servers_name_is_asked_to_redeploy(): void
    {
        (new PublicRoutingGrants)->allow('shop', [PublicRoutingGrants::HOST_DOMAIN]);
        $hostDomain = Mockery::mock(HostDomain::class);
        $hostDomain->shouldReceive('release')->once()->andReturnUsing(static function (User $user): array {
            $user->domain = 'shop-moved.example.test';

            return ['state' => HostDomain::NOT_ALLOWED, 'name' => $user->domain];
        });
        $this->app->instance(HostDomain::class, $hostDomain);

        $output = $this->deny(['--host-domain' => true]);

        $this->assertStringContainsString('shop is now allowed nothing.', $output);
        $this->assertStringContainsString('Its main domain is shop-moved.example.test; redeploy it', $output);
    }

    /** @param array<string, mixed> $options */
    private function deny(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('system:public-routing:deny', ['project' => 'shop'] + $options));

        return Artisan::output();
    }
}
