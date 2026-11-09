<?php

namespace Tests\Unit\Domains;

use App\Models\Domain;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Deleting a domain keeps its directory and document root, and both the answer and the CLI name them.
 */
class DomainDeleteKeepsFilesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_the_default_document_root_is_kept_with_the_domain_directory(): void
    {
        $this->assertSame(['/shop.test', '/shop.test/public_html'], $this->domain('shop.test', null)->directoriesKeptOnDelete());
    }

    public function test_a_document_root_outside_the_domain_directory_is_kept_as_well(): void
    {
        $this->assertSame(['/shop.test', '/public_html'], $this->domain('shop.test', '/public_html')->directoriesKeptOnDelete());
    }

    public function test_a_document_root_that_is_the_domain_directory_is_named_once(): void
    {
        $this->assertSame(['/shop.test'], $this->domain('shop.test', '/shop.test')->directoriesKeptOnDelete());
    }

    public function test_the_cli_says_which_directories_it_keeps(): void
    {
        $user = $this->makeUser('alice');
        $this->makeMainDomain($user, 'alice.test');
        Domain::make(['user_id' => $user->id, 'domain' => 'shop.test', 'type' => 'addon', 'details' => ['document_root' => '/shop.test/public_html']])->save();

        $this->artisan('domain:delete', ['domain' => 'shop.test', '--project' => 'alice'])
            ->expectsOutputToContain('Its files are kept in ~/shop.test and ~/shop.test/public_html.')
            ->expectsConfirmation('Delete this domain?', 'no')
            ->assertExitCode(0);
    }

    private function domain(string $name, ?string $documentRoot): Domain
    {
        $domain = new Domain();
        $domain->domain = $name;
        $domain->details = $documentRoot === null ? [] : ['document_root' => $documentRoot];

        return $domain;
    }
}
