<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Users\AddMissingWwwDomainAliases;
use App\Models\Domain;
use App\Models\Tunnel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `project:domain:add-www-alias` with a www. name the engine already serves:
 * the check the API makes, a plain line and exit 1, never the bare
 * "Domain already exists" addAlias() throws. Nothing here touches the host.
 */
class AddWwwAliasCommandTest extends TestCase
{
    use InMemoryDatabase;

    private Domain $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new QuietWwwAliasCommand());

        $this->shop = $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'shop.test'), 'shop.test');
    }

    public function test_a_name_a_tunnel_serves_is_refused_with_a_plain_line(): void
    {
        $bob = $this->makeUser('bob', ['template' => 'wordpress'], 'bob.test');
        Tunnel::create([
            'domain_id' => $this->makeMainDomain($bob, 'bob.test')->id,
            'user_id' => $bob->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'www.shop.test',
        ]);

        $this->assertSame(1, Artisan::call('project:domain:add-www-alias', ['--project' => 'alice']));
        $this->assertStringContainsString(
            'www.shop.test is already on this engine (a tunnel hostname), not added.',
            Artisan::output()
        );
        $this->assertSame([], $this->shop->fresh()?->getAliases());
    }

    public function test_a_name_another_domain_holds_is_refused_and_named(): void
    {
        $bob = $this->makeUser('bob', ['template' => 'wordpress'], 'bob.test');
        Domain::create(['user_id' => $bob->id, 'domain' => 'www.shop.test', 'type' => 'addon', 'details' => []]);

        $this->assertSame(1, Artisan::call('project:domain:add-www-alias', ['--project' => 'alice']));
        $this->assertStringContainsString(
            'www.shop.test is already on this engine (addon domain of project bob), not added.',
            Artisan::output()
        );
        $this->assertSame([], $this->shop->fresh()?->getAliases());
    }

    public function test_a_domain_that_has_its_www_alias_is_skipped_as_before(): void
    {
        $this->shop->setDetails(['aliases' => ['www.shop.test']]);
        $this->shop->save();

        $this->assertSame(0, Artisan::call('project:domain:add-www-alias', ['--project' => 'alice']));
        $this->assertStringContainsString('It already has www. alias, skipping.', Artisan::output());
    }
}

/** The command without its closing webserver reload, which reaches the host. */
class QuietWwwAliasCommand extends AddMissingWwwDomainAliases
{
    protected function afterAll(): void
    {
    }
}
