<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\SecretVaultController;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;

/**
 * Minting in either scope, addressing by `vault:<id>`, and the config
 * endpoint that lists the globals.
 *
 * The controller is called directly rather than over the route, so these read
 * the mint rules themselves without a bearer token in the way.
 */
class SecretVaultControllerGlobalTest extends VaultTestCase
{
    public function test_project_scope_is_the_default_and_neither_scope_expires(): void
    {
        $project = $this->mint(['type' => 'git_token']);
        $global = $this->mint(['type' => 'git_token', 'scope' => 'global']);

        $this->assertSame('project', $project['scope']);
        $this->assertNull($project['project'], 'Unclaimed until a project is given it.');
        $this->assertSame('global', $global['scope']);

        foreach ([$project, $global] as $data) {
            $this->assertSame('vault:' . $data['id'], $data['ref']);
            $this->assertArrayNotHasKey('expires_in', $data, 'The secret does not expire.');
            $this->assertSame(SecretVaultEntry::TTL_SECONDS, $data['url_expires_in'], 'Its paste form does.');
        }
    }

    public function test_a_project_secret_can_be_created_for_a_named_project(): void
    {
        // The project need not exist yet.
        $data = $this->mint(['type' => 'git_token', 'project' => 'shop']);

        $this->assertSame('project', $data['scope']);
        $this->assertSame('shop', $data['project']);
        $this->assertSame('shop', SecretVaultEntry::query()->find($data['id'])?->project);
    }

    public function test_a_named_project_is_refused_on_a_global_or_when_it_is_not_a_project_name(): void
    {
        foreach ([
            ['type' => 'git_token', 'scope' => 'global', 'project' => 'shop'],
            ['type' => 'git_token', 'project' => 'Not A Name'],
            ['type' => 'git_token', 'project' => '9shop'],
        ] as $input) {
            try {
                $this->mint($input);
                $this->fail('Refused: ' . json_encode($input));
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('project', $e->errors());
            }
        }
        $this->assertSame(0, SecretVaultEntry::query()->count());
    }

    public function test_a_secret_can_be_given_an_expiry(): void
    {
        $data = $this->mint(['type' => 'git_token', 'scope' => 'global', 'expires_in' => 86400]);

        $this->assertNotNull($data['expires_at']);
        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), strtotime($data['expires_at']), 5);
        $this->assertNull($this->mint(['type' => 'git_token'])['expires_at'], 'Without one it never expires.');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->mint(['type' => 'git_token', 'expires_in' => 10]);
    }

    public function test_an_unknown_scope_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->mint(['type' => 'git_token', 'scope' => 'request']);
    }

    public function test_an_entry_is_addressed_by_its_reference_or_bare_id(): void
    {
        [$entry] = $this->projectEntry('git_token', 'ghp_mine', 'shop');

        foreach ([$entry->reference(), (string) $entry->id] as $ref) {
            $data = json_decode((string) (new SecretVaultController())->show($ref)->getContent(), true)['data'];
            $this->assertSame($entry->reference(), $data['ref']);
            $this->assertSame('shop', $data['project']);
            $this->assertSame('filled', $data['status']);
            $this->assertArrayNotHasKey('secret', $data);
        }
    }

    public function test_config_lists_every_pasted_global(): void
    {
        [$shop] = $this->globalEntry('git_token', 'ghp_shop');
        [$blog] = $this->globalEntry('git_token', 'ghp_blog');
        $this->globalEntry('cloudflare_api_token'); // minted, never pasted
        $this->projectEntry('git_token', 'ghp_mine');

        $data = json_decode((string) (new SecretVaultController())->config()->getContent(), true)['data'];

        $this->assertSame([$shop->reference(), $blog->reference()], array_column($data['globals'], 'ref'));
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
