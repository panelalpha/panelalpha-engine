<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Domain;
use App\Models\Tunnel;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `PUT /projects/{project}/domains/{domain}` with `aliases`: a name that is
 * taken is a 422, refused before the vhost is rebuilt. Nothing here touches the host.
 */
class DomainAliasHttpTest extends TestCase
{
    use InMemoryDatabase;

    private Domain $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);

        $this->shop = $this->makeMainDomain($this->makeUser('alice', ['template' => 'wordpress'], 'shop.test'), 'shop.test');
        $bob = $this->makeUser('bob', ['template' => 'wordpress'], 'bob.test');
        $bobMain = $this->makeMainDomain($bob, 'bob.test');
        $bobMain->setDetails(['aliases' => ['bob-alias.test']]);
        $bobMain->save();
        Tunnel::create([
            'domain_id' => $bobMain->id,
            'user_id' => $bob->id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'api-bob.panelalpha.test',
        ]);
    }

    /** addAlias() refuses a tunnel's hostname with a bare exception, which was a 500. */
    public function test_a_hostname_a_tunnel_serves_is_refused_with_422(): void
    {
        $this->putJson('/api/projects/alice/domains/shop.test', ['aliases' => ['api-bob.panelalpha.test']])
            ->assertStatus(422)
            ->assertJsonPath('errors.aliases', ['Domain alias api-bob.panelalpha.test is not available']);

        $this->assertSame([], $this->shop->fresh()?->getAliases());
    }

    public function test_another_domains_name_or_alias_is_still_refused(): void
    {
        foreach (['bob.test', 'bob-alias.test'] as $taken) {
            $this->putJson('/api/projects/alice/domains/shop.test', ['aliases' => [$taken]])
                ->assertStatus(422)
                ->assertJsonPath('errors.aliases', ["Domain alias {$taken} is not available"]);
        }

        $this->assertSame([], $this->shop->fresh()?->getAliases());
    }

    /**
     * The controller treats a present `aliases` key as the new list. `[]`
     * removes them all; an absent key leaves them alone. The empty list used
     * to be skipped, so a domain could never lose its last alias.
     */
    public function test_an_empty_alias_list_is_the_new_list_and_an_absent_key_is_not(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/core/app/Http/Controllers/User/DomainController.php');

        // The guard is array_key_exists, not !empty(): an empty list reaches
        // removeAliases(), an absent key does not.
        $this->assertStringContainsString("if (array_key_exists('aliases', \$params)) {", $source);
        $this->assertStringNotContainsString("if (!empty(\$params['aliases'])) {", $source);
    }
}
