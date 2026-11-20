<?php

namespace Tests\Integration\System\Services\Webserver;

use App\Models\Domain;
use App\System\Services\Webserver\CookieScope;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A domain being added has no tunnels yet, so its scope is its own names and the project's saved ones. */
class CookieScopeUnsavedDomainTest extends TestCase
{
    use InMemoryDatabase;

    public function test_an_unsaved_domain_is_scoped_by_its_names_and_the_projects(): void
    {
        $this->bootInMemoryDatabase();
        $user = $this->makeUser('shop');
        $this->makeMainDomain($user, 'shop.example.test');
        $domain = new Domain(['user_id' => $user->id, 'domain' => 'new-shop.example.test', 'type' => 'addon']);
        $domain->setDetails(['aliases' => ['www.new-shop.example.test']]);

        $scope = CookieScope::forDomain($domain, $user, false);

        $this->assertSame(['new-shop.example.test', 'www.new-shop.example.test', 'shop.example.test'], $scope->own);
        $this->assertFalse($scope->gated());
    }
}
