<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use Tests\Support\InMemoryDatabase;
use Tests\Support\ScriptedBroker;
use Tests\TestCase;

/**
 * /domains/{domain}/http-acme-challenges: tokens are files in the domain's challenge directory on the host, found by
 * the domain's name or an alias, and a change that leaves challenges served needs no vhost rebuild.
 */
class HttpAcmeChallengeHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const DIR = '/opt/panelalpha/shared-hosting/data/webserver/acme-challenges/shop.test';

    private ScriptedBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        $this->broker = new ScriptedBroker();
        $main = $this->makeMainDomain($this->makeUser('alice', ['template' => 'dind'], 'shop.test'), 'shop.test');
        $main->setDetails(['aliases' => ['www.shop.test']]);
        $main->save();
    }

    protected function tearDown(): void
    {
        $this->broker->uninstall();
        parent::tearDown();
    }

    public function test_the_challenges_of_a_domain_are_listed_by_its_alias(): void
    {
        $this->served(['tok-a', 'tok_b', '.', 'not.a.token']);
        $this->broker->hostFile(self::DIR . '/tok-a', 'content-a');
        $this->broker->hostFile(self::DIR . '/tok_b', 'content-b');

        $this->getJson('/api/domains/WWW.shop.test/http-acme-challenges')
            ->assertOk()
            ->assertExactJson(['data' => [
                'domain' => 'shop.test',
                'challenges' => [
                    ['token' => 'tok-a', 'content' => 'content-a'],
                    ['token' => 'tok_b', 'content' => 'content-b'],
                ],
            ]]);
    }

    public function test_a_listing_skips_a_token_whose_file_went_away(): void
    {
        $this->served(['tok-a']);
        $this->broker->answer('files.isFile', exit: 1, match: self::DIR . '/tok-a');

        $this->getJson('/api/domains/shop.test/http-acme-challenges')->assertOk()->assertJsonPath('data.challenges', []);
    }

    public function test_one_challenge_is_shown_or_not_found(): void
    {
        $this->broker->hostFile(self::DIR . '/tok-a', 'content-a');
        $this->broker->answer('files.isFile', exit: 1, match: self::DIR . '/gone');

        $this->getJson('/api/domains/shop.test/http-acme-challenges/tok-a')
            ->assertOk()
            ->assertExactJson(['data' => ['domain' => 'shop.test', 'token' => 'tok-a', 'content' => 'content-a']]);
        $this->getJson('/api/domains/shop.test/http-acme-challenges/gone')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
    }

    public function test_an_unknown_domain_is_not_found_on_every_route(): void
    {
        $this->getJson('/api/domains/other.test/http-acme-challenges')->assertNotFound();
        $this->getJson('/api/domains/other.test/http-acme-challenges/tok')->assertNotFound();
        $this->postJson('/api/domains/other.test/http-acme-challenges', ['token' => 'tok', 'content' => 'x'])->assertNotFound();
        $this->deleteJson('/api/domains/other.test/http-acme-challenges')->assertNotFound();
        $this->deleteJson('/api/domains/other.test/http-acme-challenges/tok')->assertNotFound();

        $this->assertSame([], $this->broker->requests());
    }

    public function test_a_token_that_exists_already_is_a_conflict(): void
    {
        $this->postJson('/api/domains/shop.test/http-acme-challenges', ['token' => 'tok-a', 'content' => 'content-a'])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Challenge token already exists']);

        $this->assertSame(['files.isFile'], $this->broker->actions());
    }

    public function test_a_token_added_beside_another_is_written_without_a_rebuild(): void
    {
        $this->served(['tok-a']);
        $this->broker->answer('files.isFile', exit: 1, match: self::DIR . '/tok-new');

        $this->postJson('/api/domains/shop.test/http-acme-challenges', ['token' => 'tok-new', 'content' => 'new-content'])
            ->assertCreated()
            ->assertExactJson(['data' => ['domain' => 'shop.test', 'token' => 'tok-new', 'content' => 'new-content']]);

        $this->assertStringContainsString(self::DIR . '/tok-new', implode(' ', array_merge(...$this->broker->requests())));
        $this->assertNotContains('compose.nginxReload', $this->broker->actions());
    }

    public function test_deleting_every_challenge_of_a_domain_with_none_changes_nothing(): void
    {
        $this->served([]);

        $this->deleteJson('/api/domains/shop.test/http-acme-challenges')->assertNoContent();

        $this->assertSame([], $this->broker->valuesOf('files.removeTree'));
    }

    public function test_deleting_one_of_two_challenges_keeps_them_served(): void
    {
        $this->served(['tok-a', 'tok-b']);

        $this->deleteJson('/api/domains/shop.test/http-acme-challenges/tok-a')->assertNoContent();

        $this->assertSame([[self::DIR . '/tok-a']], $this->broker->valuesOf('files.removeIfExists'));
        $this->assertNotContains('compose.nginxReload', $this->broker->actions());
    }

    public function test_deleting_a_challenge_that_is_not_there_is_not_found(): void
    {
        $this->broker->answer('files.isFile', exit: 1, match: self::DIR . '/gone');

        $this->deleteJson('/api/domains/shop.test/http-acme-challenges/gone')->assertNotFound()->assertExactJson(['message' => 'Not Found']);
        $this->assertSame([], $this->broker->valuesOf('files.removeIfExists'));
    }

    /** @param list<string> $names */
    private function served(array $names): void
    {
        $this->broker->answer('files.listAllNames', implode("\n", $names) . "\n");
    }
}
