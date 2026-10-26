<?php

namespace Tests\Unit\Models;

use App\Models\Domain;
use App\Models\User;
use Tests\Unit\Database\SqliteCollationTestCase;

/**
 * WP-CLI is handed a --path that need not be byte-identical to a stored
 * document root: it may carry a trailing slash, be relative, or name a
 * subdirectory. The lookup has to resolve any of those to the domain, or the
 * wrong PHP version is picked.
 */
class DomainDocumentRootLookupTest extends SqliteCollationTestCase
{
    public function test_exact_document_root_matches(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test/public_html');

        $found = $user->findDomainByDocumentRoot('/home/foobar/example.test/public_html');

        $this->assertNotNull($found);
        $this->assertSame('example.test', $found->domain);
    }

    public function test_trailing_slash_matches(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test/public_html');

        $found = $user->findDomainByDocumentRoot('/home/foobar/example.test/public_html/');

        $this->assertNotNull($found);
        $this->assertSame('example.test', $found->domain);
    }

    public function test_relative_path_matches(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test/public_html');

        $found = $user->findDomainByDocumentRoot('example.test/public_html');

        $this->assertNotNull($found);
        $this->assertSame('example.test', $found->domain);
    }

    public function test_subdirectory_of_a_document_root_matches(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test/public_html');

        $found = $user->findDomainByDocumentRoot('/home/foobar/example.test/public_html/wp-content');

        $this->assertNotNull($found);
        $this->assertSame('example.test', $found->domain);
    }

    public function test_the_most_specific_root_wins(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test');
        $this->domain($user, 'shop.example.test', '/example.test/shop');

        $found = $user->findDomainByDocumentRoot('/home/foobar/example.test/shop/public');

        $this->assertNotNull($found);
        $this->assertSame('shop.example.test', $found->domain);
    }

    public function test_a_path_outside_every_document_root_matches_nothing(): void
    {
        $user = $this->user();
        $this->domain($user, 'example.test', '/example.test/public_html');

        $this->assertNull($user->findDomainByDocumentRoot('/home/foobar/other'));
        $this->assertNull($user->findDomainByDocumentRoot(''));
    }

    public function test_a_domain_added_after_the_relation_was_loaded_is_still_found(): void
    {
        $user = $this->user();
        $this->domain($user, 'old.example.test', '/old.example.test/public_html');
        $user->load('domains');
        $this->domain($user, 'new.example.test', '/new.example.test/public_html');

        $found = $user->findDomainByDocumentRoot('/home/foobar/new.example.test/public_html');

        $this->assertNotNull($found);
        $this->assertSame('new.example.test', $found->domain);
    }

    private function user(): User
    {
        return User::create(['username' => 'foobar', 'domain' => 'foobar.example.com']);
    }

    private function domain(User $user, string $name, string $documentRoot): Domain
    {
        return Domain::create([
            'user_id' => $user->id,
            'domain' => $name,
            'type' => 'main',
            'details' => ['document_root' => $documentRoot],
        ]);
    }
}
