<?php

namespace Tests\Unit\Domains;

use App\Models\Domain;
use App\Models\Tunnel;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * domain:tunnel:create and domain:tunnel:delete up to the point where they
 * would call a provider: every refusal a mistyped command gets, and a
 * declined delete leaving the tunnel in place.
 */
class TunnelCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->alice = $this->makeUser('alice');
        $this->makeMainDomain($this->alice, 'alice.test');
        $bob = $this->makeUser('bob');
        $this->makeMainDomain($bob, 'bob.test');
    }

    public function test_create_refuses_each_bad_input_before_any_provider_call(): void
    {
        $cases = [
            ['a.example.com --hostname=b.example.com --project=alice --domain=alice.test', "Conflicting hostnames: argument 'a.example.com' vs --hostname='b.example.com'."],
            ['a.example.com --domain=alice.test', '--project is required.'],
            ['a.example.com --project=alice', '--domain is required.'],
            ['--project=alice --domain=alice.test', 'Tunnel hostname is required (positional argument or --hostname).'],
            ['a.example.com --project=alice --domain=alice.test --provider=ngrok', '--provider must be one of: cloudflare, panelalpha'],
            ["'not a host' --project=alice --domain=alice.test", "Invalid tunnel hostname 'not a host'."],
            ['a.example.com --project=nobody --domain=alice.test', "Project 'nobody' not found."],
            ['a.example.com --project=alice --domain=missing.test', "Domain 'missing.test' not found for project 'alice'."],
            ['a.example.com --project=alice --domain=bob.test', "Domain 'bob.test' not found for project 'alice'."],
        ];

        foreach ($cases as [$args, $message]) {
            $this->artisan('domain:tunnel:create ' . $args)
                ->expectsOutputToContain($message)
                ->assertExitCode(1);
        }

        $this->assertSame(0, Tunnel::query()->count());
    }

    public function test_delete_refuses_each_bad_input(): void
    {
        $this->addTunnel('app.example.com', 'alice.test');

        $cases = [
            ['a.example.com --hostname=b.example.com --project=alice', "Conflicting hostnames: argument 'a.example.com' vs --hostname='b.example.com'."],
            ['app.example.com', '--project is required.'],
            ['--project=alice', 'Tunnel hostname is required (positional argument or --hostname).'],
            ['app.example.com --project=nobody', "Project 'nobody' not found."],
            ['missing.example.com --project=alice', "Tunnel 'missing.example.com' not found for project 'alice'."],
            ['app.example.com --project=bob', "Tunnel 'app.example.com' not found for project 'bob'."],
            ['app.example.com --project=alice --domain=bob.test', "Tunnel 'app.example.com' is not attached to domain 'bob.test'."],
        ];

        foreach ($cases as [$args, $message]) {
            $this->artisan('domain:tunnel:delete ' . $args)
                ->expectsOutputToContain($message)
                ->assertExitCode(1);
        }

        $this->assertSame(1, Tunnel::query()->count());
    }

    public function test_a_declined_delete_leaves_the_tunnel(): void
    {
        $this->addTunnel('app.example.com', 'alice.test');

        $this->artisan('domain:tunnel:delete APP.example.com --project=alice --domain=alice.test')
            ->expectsOutputToContain('Only the tunnel hostname is removed. Local domain and proxy rules stay.')
            ->expectsConfirmation('Delete this tunnel?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertExitCode(0);

        $this->assertSame(1, Tunnel::query()->count());
    }

    private function addTunnel(string $hostname, string $domain): void
    {
        Tunnel::create([
            'domain_id' => Domain::query()->where('domain', $domain)->value('id'),
            'user_id' => $this->alice->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => $hostname,
            'details' => [],
        ]);
    }
}
