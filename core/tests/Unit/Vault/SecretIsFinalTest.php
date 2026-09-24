<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\SecretVaultController;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A stored secret is final: nothing overwrites it, and replacing one means
 * deleting the entry.
 *
 * The rule has to hold at both doors. The form is the obvious one, but it is
 * a POST -- anyone holding the link can repeat it without ever loading the
 * page -- so refusing to *render* the form is not the same as refusing the
 * write. Minting never touches a stored entry either: a new link is a new
 * entry with its own `vault:<id>`.
 *
 * Why it matters beyond tidiness: the secret can never be read back, so an
 * overwrite is undetectable after the fact. `use_count` and `last_used_at`
 * would also start describing however many secrets had passed through one
 * row rather than one secret.
 */
class SecretIsFinalTest extends VaultTestCase
{
    public function test_the_form_refuses_a_second_paste(): void
    {
        [$entry, $ref] = $this->entry(['secret' => 'first-value']);

        $response = $this->post('/vault/' . $ref, ['secret' => 'second-value']);

        $response->assertOk();
        $response->assertSee('This secret is already set');
        $this->assertSame('first-value', $entry->refresh()->revealSecret(), 'The stored secret must be untouched.');
    }

    public function test_the_form_is_not_even_offered_once_a_secret_is_set(): void
    {
        [, $ref] = $this->entry(['secret' => 'first-value']);

        $response = $this->get('/vault/' . $ref);

        // Not a paste field in sight: showing one that the POST would refuse
        // invites somebody to type a credential for nothing.
        $response->assertDontSee('name="secret"', false);
        $response->assertSee('This secret is already set');
    }

    public function test_the_page_no_longer_offers_to_replace_anything(): void
    {
        [, $ref] = $this->entry(); // pending, never filled

        $content = (string) $this->get('/vault/' . $ref)->getContent();

        $this->assertStringNotContainsString('saving again replaces it', $content);
        $this->assertStringContainsString('cannot be changed from this page afterwards', $content);
    }

    public function test_the_page_says_which_project_the_secret_is_for(): void
    {
        [, $ref] = $this->projectEntry(project: 'shop');

        $this->get('/vault/' . $ref)->assertSee('Saved for project shop only.');
    }

    public function test_a_pending_entry_still_accepts_its_first_paste(): void
    {
        [$entry, $ref] = $this->entry();

        $this->post('/vault/' . $ref, ['secret' => 'the-only-value'])->assertOk();

        $this->assertSame('the-only-value', $entry->refresh()->revealSecret());
    }

    public function test_minting_another_global_of_a_type_leaves_the_stored_one_alone(): void
    {
        [$stored] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_shop_org');

        $data = $this->mint(['type' => 'git_token', 'scope' => 'global', 'purpose' => 'Blog org']);

        // Several globals per type, told apart by purpose: a new one is a new entry.
        $this->assertNotSame($stored->reference(), $data['ref']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('ghp_shop_org', $stored->refresh()->revealSecret());
        $this->assertSame(2, SecretVaultEntry::query()->where('scope', 'global')->count());
    }

    public function test_deleting_then_minting_is_how_a_secret_is_replaced(): void
    {
        [$old] = $this->globalEntry(SecretVaultEntry::TYPE_GIT_TOKEN, 'ghp_old');

        (new SecretVaultController())->destroy($old->reference());
        $data = $this->mint(['type' => 'git_token', 'scope' => 'global']);

        $this->assertNull(SecretVaultEntry::query()->find($old->id));
        $this->assertSame('pending', $data['status']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mint(array $input): array
    {
        $request = Request::create('/api/vault/secrets', 'POST', $input);
        $this->app->instance('request', $request);

        $response = (new SecretVaultController())->store($request);

        /** @var array{data: array<string, mixed>} $body */
        $body = json_decode((string) $response->getContent(), true);

        return $body['data'];
    }
}
