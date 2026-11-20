<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\Tunnel;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Networking;
use App\System\Project\Dind\ShellOperations;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Routing a project keeps going when a step fails, and says so: in the engine log, and in the deploy log where
 * the operator has to act. A redeploy beside the running version leaves the site where it answers.
 */
class NetworkingRouteFailuresTest extends TestCase
{
    use InMemoryDatabase;

    private string $username = '';

    private ?DeployLogger $logger = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->username = 'netf'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->logger = DeployLogger::start($this->username);
    }

    public function test_a_domain_whose_name_is_blank_has_no_public_url(): void
    {
        $user = $this->makeUser($this->username);
        $this->makeMainDomain($user, '   ');

        $this->assertNull(Networking::publicUrlOf($user->fresh()));
    }

    public function test_a_redeploy_beside_the_running_version_keeps_the_site_on_its_port(): void
    {
        $user = $this->makeUser($this->username);
        (new GenerationState($this->username))->put(GenerationState::ROUTES, [
            'owner' => GenerationState::owner(),
            'details' => ['app_port' => 3000],
            'applied' => false,
        ]);
        $webserver = $this->createMock(Webserver::class);
        $webserver->expects($this->never())->method('reloadRouting');

        $this->networking($webserver)->routeTo($user, 8080);

        $this->assertSame(8080, $user->fresh()->getAppPort());
        $this->assertContains(
            'The site stays on port 3000, where the running version answers, until the new version takes traffic',
            $this->messages()
        );
    }

    public function test_failed_rule_moves_rule_syncs_and_vhost_rebuilds_are_logged_and_survived(): void
    {
        $user = $this->makeUser($this->username);
        $this->makeMainDomain($user, $this->username.'.test');
        Schema::drop('proxy_rules');
        Log::spy();
        $webserver = $this->createStub(Webserver::class);
        $webserver->method('rebuildDomainConfig')->willThrowException(new \RuntimeException('nginx: config test failed'));

        $this->networking($webserver)->applyRoutes($user->fresh(), 8080, 3000);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, "Failed to move the proxy rules of {$this->username} to port 8080: "));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === "Failed to create proxy rules for {$this->username} {$this->username}.test");
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === "Failed to rebuild domain vhost after proxy rules for {$this->username}");
        $this->assertContains('Webserver reload after proxy rules failed: nginx: config test failed', $this->messages());
    }

    public function test_a_tunnel_that_cannot_be_synced_is_reported_in_the_deploy_log(): void
    {
        $user = $this->makeUser($this->username);
        $domain = $this->makeMainDomain($user, $this->username.'.test');
        Tunnel::query()->create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => $this->username.'.example.test',
            'details' => [],
        ]);
        $webserver = $this->createMock(Webserver::class);
        $webserver->expects($this->once())->method('reloadRouting');

        $this->networking($webserver)->applyRoutes($user->fresh(), 8080);

        $warnings = array_values(array_filter($this->messages(), static fn (string $line): bool => str_starts_with($line, 'Tunnel ingress was not synced: ')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($this->username, $warnings[0]);
    }

    private function networking(Webserver $webserver): Networking
    {
        $system = $this->createStub(System::class);
        $system->method('webserver')->willReturn($webserver);

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn($this->username);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return new Networking($dind);
    }

    /** @return list<string> */
    private function messages(): array
    {
        return array_map(static fn (array $entry): string => $entry['msg'], $this->logger?->entries() ?? []);
    }
}
