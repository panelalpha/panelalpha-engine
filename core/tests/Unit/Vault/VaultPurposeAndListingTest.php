<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\SecretVaultController;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * The inventory: what a caller can learn about stored secrets without being
 * able to read one.
 *
 * The secret is never returned by anything, which makes the listing the only
 * handle on it. Two things follow, and they are what these tests hold. Every
 * row needs an `id`, because a request entry's ref is shown once and never
 * stored -- without one, an entry could be read about and never deleted. And
 * every row needs a `purpose`, because `type` is a category (three entries
 * can all be `git_token`) and dates do not say which token is which.
 */
class VaultPurposeAndListingTest extends VaultTestCase
{
    public function test_a_purpose_is_stored_and_returned(): void
    {
        $data = $this->mint(['type' => 'git_token', 'purpose' => 'Deploy key for the shop repo']);

        $this->assertSame('Deploy key for the shop repo', $data['purpose']);
        $this->assertSame('Deploy key for the shop repo', SecretVaultEntry::query()->find($data['id'])?->purpose);
    }

    public function test_a_purpose_is_optional(): void
    {
        $data = $this->mint(['type' => 'git_token']);

        $this->assertNull($data['purpose']);
    }

    public function test_a_blank_purpose_is_stored_as_nothing(): void
    {
        // Otherwise a listing shows an empty box that looks like a purpose
        // somebody meant to write.
        $data = $this->mint(['type' => 'git_token', 'purpose' => '   ']);

        $this->assertNull($data['purpose']);
    }

    public function test_an_over_long_purpose_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->mint(['type' => 'git_token', 'purpose' => str_repeat('x', 256)]);
    }

    public function test_the_listing_identifies_entries_without_revealing_them(): void
    {
        $this->mint(['type' => 'git_token', 'purpose' => 'Shop repo']);
        $this->mint(['type' => 'git_token', 'purpose' => 'Docs repo']);

        $rows = $this->list();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertIsInt($row['id']);
            $this->assertSame('git_token', $row['type']);
            $this->assertSame('vault:' . $row['id'], $row['ref']);
            $this->assertArrayHasKey('created_at', $row);
            $this->assertArrayHasKey('project', $row);
            // The whole point: an inventory, never the values.
            $this->assertArrayNotHasKey('secret', $row);
            $this->assertArrayNotHasKey('secret_encrypted', $row);
        }

        // Same type, same dates -- the purpose is the only thing that says
        // which is which, and therefore which one is safe to delete.
        $this->assertEqualsCanonicalizing(['Shop repo', 'Docs repo'], array_column($rows, 'purpose'));
    }

    public function test_the_listing_never_carries_the_secret_even_once_pasted(): void
    {
        $this->entry(['secret' => 'ghp_verysecret', 'purpose' => 'Shop repo']);

        $encoded = json_encode($this->list());

        $this->assertStringNotContainsString('ghp_verysecret', (string) $encoded);
        $this->assertStringContainsString('Shop repo', (string) $encoded);
    }

    public function test_an_entry_can_be_addressed_by_the_id_the_listing_gives(): void
    {
        [$entry] = $this->entry(['secret' => 'ghp_pasted', 'purpose' => 'Shop repo']);

        $response = (new SecretVaultController())->show($entry->reference());
        $data = json_decode((string) $response->getContent(), true)['data'];

        $this->assertSame($entry->id, $data['id']);
        $this->assertSame('filled', $data['status']);
        $this->assertSame('Shop repo', $data['purpose']);
    }

    public function test_an_entry_can_be_deleted_by_that_id(): void
    {
        // Deleting is the only way to replace a secret.
        [$entry] = $this->entry(['secret' => 'ghp_pasted']);

        (new SecretVaultController())->destroy($entry->reference());

        $this->assertNull(SecretVaultEntry::query()->find($entry->id));
    }

    public function test_a_junk_id_is_a_404_not_a_crash(): void
    {
        // A non-numeric id resolves to no entry: a 404, not a database error
        // from casting "notanumber" to an integer key.
        try {
            (new SecretVaultController())->show('vault:notanumber');
            $this->fail('An unresolvable id must not be reported as a found entry.');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }
    }

    public function test_the_listing_filters_by_scope_and_shows_who_owns_what(): void
    {
        [$global] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shared');
        $this->projectEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_mine', 'shop');

        $rows = $this->list(['scope' => 'global']);
        $this->assertSame([$global->reference()], array_column($rows, 'ref'));
        $this->assertNull($rows[0]['project']);

        $rows = $this->list(['scope' => 'project']);
        $this->assertSame(['shop'], array_column($rows, 'project'));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mint(array $input): array
    {
        $request = Request::create('/api/vault/secrets', 'POST', $input);
        $this->app->instance('request', $request);

        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode((string) (new SecretVaultController())->store($request)->getContent(), true);

        return $body['data'];
    }

    /**
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function list(array $query = []): array
    {
        $request = Request::create('/api/vault/secrets', 'GET', $query);
        $this->app->instance('request', $request);

        /** @var array{data: list<array<string, mixed>>} $body */
        $body = json_decode((string) (new SecretVaultController())->index($request)->getContent(), true);

        return $body['data'];
    }
}
