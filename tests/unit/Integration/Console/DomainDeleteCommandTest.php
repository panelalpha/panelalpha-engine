<?php

namespace Tests\Integration\Console;

use App\Models\Domain;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `domain:delete` refuses the project's main domain, the address it is renamed through. */
class DomainDeleteCommandTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_deleting_the_main_domain_is_refused(): void
    {
        $user = $this->makeUser('alice');
        $this->makeMainDomain($user, 'alice.example.test');

        $this->artisan('domain:delete alice.example.test --project=alice --force')
            ->expectsOutputToContain('Cannot delete the main domain: change the project\'s main domain instead.')
            ->assertExitCode(1);

        $this->assertSame(1, Domain::query()->count());
    }
}
