<?php

namespace Tests\Unit\Domains;

use App\Lib\Domains\NewDomain;
use App\Models\Domain;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class NewDomainTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_www_name_is_created_bare_with_the_www_name_as_an_alias(): void
    {
        $this->assertSame(['example.com', ['www.example.com']], NewDomain::withoutWww('www.example.com', []));
        $this->assertSame(
            ['example.com', ['a.example.com', 'www.example.com']],
            NewDomain::withoutWww('www.example.com', ['a.example.com'])
        );
    }

    public function test_the_www_alias_is_not_added_twice(): void
    {
        $this->assertSame(
            ['example.com', ['www.example.com']],
            NewDomain::withoutWww('www.example.com', ['www.example.com'])
        );
    }

    public function test_a_name_without_www_is_left_alone(): void
    {
        $this->assertSame(['shop.example.com', []], NewDomain::withoutWww('shop.example.com', []));
    }

    public function test_no_limit_means_no_refusal(): void
    {
        $user = $this->makeUser('alice');

        $this->assertNull(NewDomain::reachedLimit($user, 'addon'));
        $this->assertNull(NewDomain::reachedLimit($user, 'sub'));
    }

    public function test_a_limit_is_reached_once_the_count_meets_it(): void
    {
        $user = $this->makeUser('alice', ['addon_domains_limit' => 1, 'subdomains_limit' => 2]);
        $this->addDomain($user, 'one.test', 'addon');
        $this->addDomain($user, 'a.alice.example.test', 'sub');

        $this->assertSame(1, NewDomain::reachedLimit($user, 'addon'));
        $this->assertNull(NewDomain::reachedLimit($user, 'sub'), 'one of two subdomains used');
    }

    public function test_only_addon_and_sub_have_a_limit(): void
    {
        $user = $this->makeUser('alice', ['addon_domains_limit' => 0]);

        $this->assertNull(NewDomain::reachedLimit($user, 'main'));
    }

    public function test_names_are_checked_domain_first_then_each_alias(): void
    {
        $user = $this->makeUser('alice');
        $this->addDomain($user, 'taken.test', 'addon', ['alias-taken.test']);

        $this->assertSame([NewDomain::INVALID_DOMAIN, 'not a name'], NewDomain::nameProblem('not a name', ['also bad']));
        $this->assertSame([NewDomain::DOMAIN_EXISTS, 'taken.test'], NewDomain::nameProblem('taken.test', []));
        $this->assertSame([NewDomain::DOMAIN_EXISTS, 'alias-taken.test'], NewDomain::nameProblem('alias-taken.test', []));
        $this->assertSame([NewDomain::INVALID_ALIAS, 'bad alias'], NewDomain::nameProblem('free.test', ['bad alias', 'taken.test']));
        $this->assertSame([NewDomain::ALIAS_EXISTS, 'taken.test'], NewDomain::nameProblem('free.test', ['ok.test', 'taken.test']));
        $this->assertNull(NewDomain::nameProblem('free.test', ['ok.test']));
    }

    public function test_the_details_a_new_domain_starts_with(): void
    {
        $this->assertSame([
            'document_root' => '/shop.test/public_html',
            'redirect_enabled' => false,
            'redirect_url' => null,
            'force_https_redirect' => false,
            'ssl_disabled' => true,
            'aliases' => ['www.shop.test'],
        ], NewDomain::details('shop.test', true, ['www.shop.test']));
    }

    /** @param list<string> $aliases */
    private function addDomain($user, string $name, string $type, array $aliases = []): void
    {
        Domain::make([
            'user_id' => $user->id,
            'domain' => $name,
            'type' => $type,
            'details' => ['document_root' => "/{$name}/public_html", 'aliases' => $aliases],
        ])->save();
    }
}
