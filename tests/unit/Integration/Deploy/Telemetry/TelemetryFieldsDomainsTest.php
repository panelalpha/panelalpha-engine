<?php

namespace Tests\Integration\Deploy\Telemetry;

use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\TelemetryFields;
use App\Models\Domain;
use App\Models\Tunnel;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The hostnames a report names for an account that exists: which sources
 * answer, in which order, and what each entry says about itself.
 */
class TelemetryFieldsDomainsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function domain(int $userId, string $name, string $type, array $details = []): Domain
    {
        /** @var Domain $domain */
        $domain = Domain::make(['user_id' => $userId, 'domain' => $name, 'type' => $type, 'details' => $details]);
        $domain->save();

        return $domain;
    }

    private function tunnel(Domain $domain, string $hostname, string $provider): void
    {
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $domain->user_id,
            'provider' => $provider,
            'hostname' => $hostname,
            'details' => [],
        ]);
    }

    public function test_the_main_domain_comes_first_then_each_row_its_tunnels_and_its_aliases(): void
    {
        $user = $this->makeUser('shop', [], 'Shop.Example.TEST.');
        $main = $this->domain($user->id, 'shop.example.test', 'main', ['aliases' => ['www.shop.example.test']]);
        $this->tunnel($main, ' shop-tunnel.panelalpha.online ', 'panelalpha');
        $addon = $this->domain($user->id, 'https://Addon.Example.test/landing', 'addon', [
            'aliases' => ['www.addon.example.test', 42, 'https://user:ghp_secret@leak.example.test/', 'shop.example.test'],
        ]);
        $this->tunnel($addon, 'addon-tunnel.panelalpha.online', '');
        $this->tunnel($addon, '   ', 'panelalpha');
        $this->domain($user->id, 'blog.shop.example.test', '');

        $this->assertSame([
            ['domain' => 'shop.example.test', 'primary' => true, 'type' => 'main'],
            ['domain' => 'shop-tunnel.panelalpha.online', 'tunnel' => 'panelalpha'],
            ['domain' => 'www.shop.example.test', 'alias' => true],
            ['domain' => 'addon.example.test', 'type' => 'addon'],
            ['domain' => 'addon-tunnel.panelalpha.online'],
            ['domain' => 'www.addon.example.test', 'alias' => true],
            ['domain' => 'blog.shop.example.test'],
        ], (new TelemetryFields('shop'))->domains());
    }

    public function test_an_account_with_no_domain_rows_names_its_own_domain_only(): void
    {
        $this->makeUser('solo', [], 'solo.example.test');

        $this->assertSame(
            [['domain' => 'solo.example.test', 'primary' => true, 'type' => 'main']],
            (new TelemetryFields('solo'))->domains()
        );
    }

    public function test_a_main_domain_that_is_not_a_name_leaves_the_rows_to_answer(): void
    {
        $user = $this->makeUser('bare', [], 'localhost');
        $this->domain($user->id, 'bare.example.test', 'main');

        $this->assertSame(
            [['domain' => 'bare.example.test', 'type' => 'main']],
            (new TelemetryFields('bare'))->domains()
        );
    }

    public function test_the_list_is_capped_with_the_primary_kept(): void
    {
        $user = $this->makeUser('many', [], 'many.example.test');
        $aliases = [];
        for ($i = 0; $i < DeployReport::MAX_NAMES + 10; $i++) {
            $aliases[] = "a{$i}.many.example.test";
        }
        $this->domain($user->id, 'addon.many.example.test', 'addon', ['aliases' => $aliases]);

        $domains = (new TelemetryFields('many'))->domains();

        $this->assertCount(DeployReport::MAX_NAMES, $domains);
        $this->assertSame(['domain' => 'many.example.test', 'primary' => true, 'type' => 'main'], $domains[0]);
        $this->assertSame(['domain' => 'addon.many.example.test', 'type' => 'addon'], $domains[1]);
        $this->assertSame(['domain' => 'a47.many.example.test', 'alias' => true], $domains[DeployReport::MAX_NAMES - 1]);
    }
}
