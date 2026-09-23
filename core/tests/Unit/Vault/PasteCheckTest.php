<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\SecretVaultController;
use App\Lib\Deploy\Source\GitProbeResult;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Vault\PasteCheck;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * engine#7: a git_token paste is tried against the repository named at mint
 * time, by the same probe project_create uses, before it is stored.
 *
 * Offline on purpose: a local bare repository over file:// answers, a missing
 * path refuses, and a closed port is unreachable -- the real probe, no forge.
 */
class PasteCheckTest extends VaultTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-paste-check-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
        parent::tearDown();
    }

    private function bareRepo(): string
    {
        $repo = $this->dir . '/repo.git';
        $seed = $this->dir . '/seed';
        foreach ([
            ['git', 'init', '-q', '--bare', $repo],
            ['git', 'init', '-q', $seed],
            ['git', '-C', $seed, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'x'],
            ['git', '-C', $seed, 'push', '-q', $repo, 'HEAD:refs/heads/main'],
        ] as $command) {
            $process = new Process($command);
            $process->mustRun();
        }

        return 'file://' . $repo;
    }

    /** @param array<string, string> $verifyWith */
    private function entryCheckedAgainst(array $verifyWith): string
    {
        [, $ref] = $this->entry(['verify_with' => $verifyWith]);

        return $ref;
    }

    private function token(): string
    {
        return 'ghp_' . str_repeat('a', 36);
    }

    public function test_the_form_and_project_create_share_one_probe(): void
    {
        $url = $this->bareRepo();

        $this->assertSame(GitProbeResult::VERIFIED, (new GitRemoteProbe())->check('git_repo', $url, $this->token())->outcome);
        $this->assertNull((new GitRemoteProbe())->problem('git_repo', $url, $this->token()));
    }

    /** A private repository: readable with a token, refused without one. */
    private function privateRepo(): void
    {
        $this->app->instance(PasteCheck::class, new PasteCheck(new class extends GitRemoteProbe {
            public function check(string $repoField, string $repoUrl, ?string $token, string $tokenField = 'git_token'): GitProbeResult
            {
                return $token === null
                    ? GitProbeResult::refused(['field' => $repoField, 'code' => 'x', 'message' => 'private'])
                    : GitProbeResult::verified();
            }
        }));
    }

    public function test_a_token_that_reads_a_private_repository_is_saved_as_verified(): void
    {
        $this->privateRepo();
        $ref = $this->entryCheckedAgainst(['repo_url' => 'https://github.com/acme/shop.git']);

        $response = $this->post('/vault/' . $ref, ['secret' => $this->token()]);

        $response->assertOk();
        $response->assertSee('Checked: this token can read github.com/acme/shop');
        $entry = SecretVaultEntry::query()->where('ref_hash', SecretVaultEntry::hashRef($ref))->firstOrFail();
        $this->assertTrue($entry->isSealed());
        $this->assertSame('verified', $entry->verification['result']);
        $this->assertSame($this->token(), $entry->revealSecret());
    }

    /** Readable without credentials, so a working answer proves nothing about the token. */
    public function test_a_public_repository_saves_the_token_unchecked(): void
    {
        $ref = $this->entryCheckedAgainst(['repo_url' => $this->bareRepo()]);

        $response = $this->post('/vault/' . $ref, ['secret' => $this->token()]);

        $response->assertOk();
        $response->assertSee('Saved without a check');
        $response->assertSee('This repository is public');
        $entry = SecretVaultEntry::query()->where('ref_hash', SecretVaultEntry::hashRef($ref))->firstOrFail();
        $this->assertSame('unchecked', $entry->verification['result']);
    }

    public function test_a_refused_token_is_not_saved_and_the_link_stays_open(): void
    {
        $ref = $this->entryCheckedAgainst(['repo_url' => 'file://' . $this->dir . '/missing.git']);

        $response = $this->post('/vault/' . $ref, ['secret' => $this->token()]);

        $response->assertStatus(422);
        $response->assertSee('Not saved:');
        $response->assertSee('name="secret"', false);
        $response->assertDontSee($this->token());
        $entry = SecretVaultEntry::query()->where('ref_hash', SecretVaultEntry::hashRef($ref))->firstOrFail();
        $this->assertFalse($entry->isSealed(), 'a refused token must leave the link usable');
        $this->assertNull($entry->secret_encrypted);

        // And a working one can follow through the same link.
        $entry->forceFill(['verify_with' => ['repo_url' => $this->bareRepo()]])->save();
        $this->post('/vault/' . $ref, ['secret' => $this->token()])->assertOk();
        $this->assertTrue($entry->refresh()->isSealed());
    }

    public function test_an_unreachable_host_saves_the_token_unchecked(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        $port = substr($name, strrpos($name, ':') + 1);

        $ref = $this->entryCheckedAgainst(['repo_url' => "https://127.0.0.1:{$port}/o/r.git"]);

        $response = $this->post('/vault/' . $ref, ['secret' => $this->token()]);

        $response->assertOk();
        $response->assertSee('Saved without a check');
        $entry = SecretVaultEntry::query()->where('ref_hash', SecretVaultEntry::hashRef($ref))->firstOrFail();
        $this->assertTrue($entry->isSealed());
        $this->assertSame('unchecked', $entry->verification['result']);
    }

    public function test_a_pasted_header_is_refused_by_the_create_rule_before_any_probe(): void
    {
        $ref = $this->entryCheckedAgainst(['repo_url' => $this->bareRepo()]);

        $response = $this->post('/vault/' . $ref, ['secret' => 'Bearer ' . $this->token()]);

        $response->assertStatus(422);
        $response->assertSee('not a whole Authorization header');
    }

    public function test_without_verify_with_the_form_saves_as_before(): void
    {
        [$entry, $ref] = $this->entry();

        $response = $this->post('/vault/' . $ref, ['secret' => $this->token()]);

        $response->assertOk();
        $response->assertDontSee('Checked:');
        $this->assertTrue($entry->refresh()->isSealed());
        $this->assertNull($entry->verification);
    }

    public function test_the_form_names_what_it_checks_and_preselects_the_forge(): void
    {
        $ref = $this->entryCheckedAgainst(['repo_url' => 'https://gitlab.com/acme/shop.git']);

        $response = $this->get('/vault/' . $ref);

        $response->assertOk();
        $response->assertSee('tried against gitlab.com/acme/shop');
        $response->assertSee('Check and save');
        $response->assertSee('value="gitlab" data-field', false);
        $this->assertMatchesRegularExpression('/value="gitlab"[^>]*selected/', (string) $response->getContent());
    }

    public function test_mint_normalises_the_repository_like_project_create(): void
    {
        $this->assertSame(
            ['repo_url' => 'https://github.com/acme/shop'],
            PasteCheck::prepare('git_token', ['repo_url' => 'github.com/acme/shop'])
        );
        $this->assertNull(PasteCheck::prepare('git_token', null));
        $this->assertNull(PasteCheck::prepare('git_token', []));
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function unusable(): iterable
    {
        yield 'type with no check' => ['env_vars', ['repo_url' => 'https://github.com/a/b'], 'verify_with'];
        yield 'unknown key' => ['git_token', ['repo' => 'https://github.com/a/b'], 'verify_with'];
        yield 'no repo_url' => ['git_token', ['repo_url' => ''], 'verify_with.repo_url'];
        yield 'ssh' => ['git_token', ['repo_url' => 'git@github.com:a/b.git'], 'verify_with.repo_url'];
        yield 'plain http' => ['git_token', ['repo_url' => 'http://git.example.com/a/b.git'], 'verify_with.repo_url'];
        yield 'account only' => ['git_token', ['repo_url' => 'https://github.com/acme'], 'verify_with.repo_url'];
    }

    /**
     * @param array<string, mixed> $verifyWith
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable')]
    public function test_mint_refuses_what_it_cannot_check_against(string $type, array $verifyWith, string $key): void
    {
        try {
            PasteCheck::prepare($type, $verifyWith);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_the_api_mints_with_verify_with_and_reports_it(): void
    {
        $request = Request::create('/api/vault/secrets', 'POST', [
            'type' => 'git_token',
            'verify_with' => ['repo_url' => 'github.com/acme/shop'],
        ]);
        $this->app->instance('request', $request);

        $body = json_decode((string) (new SecretVaultController())->store($request)->getContent(), true);

        $this->assertSame(['repo_url' => 'https://github.com/acme/shop'], $body['data']['verify_with']);
        $show = json_decode((string) (new SecretVaultController())->show('id:' . $body['data']['id'])->getContent(), true);
        $this->assertSame(['repo_url' => 'https://github.com/acme/shop'], $show['data']['verify_with']);
        $this->assertNull($show['data']['verification']);
    }
}
