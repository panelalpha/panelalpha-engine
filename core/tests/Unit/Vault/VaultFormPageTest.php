<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;
use Illuminate\Testing\TestResponse;

/**
 * The paste page: which screen a type gets, that it never leaks the entry, and
 * that a save ends on the success screen. Through the real route, so the
 * controller's token lookup is exercised, not just the view.
 */
class VaultFormPageTest extends VaultTestCase
{
    private function page(string $ref): TestResponse
    {
        return $this->get('/vault/' . $ref);
    }

    /** The one field every paste page renders, whatever its type. */
    private function assertIsPasteForm(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertSee('name="secret"', false);
        $response->assertSee('Save secret');
    }

    public function test_a_git_token_gets_the_repository_screen(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $response = $this->page($ref);

        $this->assertIsPasteForm($response);
        $response->assertSee('Connect your repository');
        $response->assertSee('Where is your repository?');
        // Every provider's copy ships with the page; switching needs no request.
        $response->assertSee('Other (any Git server)');
        $response->assertSee('https://bitbucket.org/account/settings/app-passwords/');
        $response->assertSee('read_repository');
        $response->assertSee('github_pat_…');
    }

    public function test_a_cloudflare_token_gets_the_cloudflare_screen(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN]);

        $response = $this->page($ref);

        $this->assertIsPasteForm($response);
        $response->assertSee('Connect Cloudflare');
        $response->assertSee('Cloudflare Tunnel');
        $response->assertSee('Zone Resources');
        $response->assertSee('placeholder="cf_…"', false);
        // The provider select belongs to the git screen only.
        $response->assertDontSee('Where is your repository?');
    }

    public function test_an_unplanned_type_still_gets_a_working_paste_page(): void
    {
        [, $ref] = $this->entry(['type' => 'some_future_secret']);

        $response = $this->page($ref);

        $this->assertIsPasteForm($response);
        $response->assertSee('Paste your secret');
        $response->assertDontSee('Connect your repository');
    }

    public function test_the_page_says_nothing_about_the_entry(): void
    {
        [, $ref] = $this->entry([
            'type' => SecretVaultEntry::TYPE_GIT_TOKEN,
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->page($ref);

        $response->assertDontSee('git_token');
        $response->assertDontSee('expires');
        $response->assertDontSee('pending');
        // The ref is the URL the page is served at, so it appears once: in the form's action.
        $this->assertSame(1, substr_count((string) $response->getContent(), $ref));
    }

    public function test_an_unknown_token_is_not_told_apart_from_an_expired_one(): void
    {
        [, $expired] = $this->entry(['expires_at' => now()->subMinute()]);

        $unknown = $this->page('a-token-that-was-never-minted');
        $late = $this->page($expired);

        $unknown->assertOk();
        $late->assertOk();
        $this->assertStringContainsString('not usable', (string) $unknown->getContent());
        $this->assertStringContainsString('expired', (string) $late->getContent());
        $unknown->assertDontSee('name="secret"', false);
        $late->assertDontSee('name="secret"', false);
    }

    public function test_pasting_stores_the_secret_and_ends_on_the_success_screen(): void
    {
        [$entry, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN]);

        $response = $this->post('/vault/' . $ref, ['secret' => 'cf_secret_value']);

        $response->assertOk();
        $response->assertSee('Secret saved');
        $response->assertDontSee('cf_secret_value');

        $entry->refresh();
        $this->assertSame('cf_secret_value', $entry->revealSecret());
        $this->assertNotNull($entry->filled_at);
    }

    public function test_the_success_screen_is_the_same_for_every_type(): void
    {
        [, $git] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);
        [, $other] = $this->entry(['type' => 'some_future_secret']);

        $one = (string) $this->post('/vault/' . $git, ['secret' => 'ghp_abc'])->getContent();
        $two = (string) $this->post('/vault/' . $other, ['secret' => 'whatever'])->getContent();

        $this->assertSame($one, $two);
    }

    /**
     * This used to offer a second paste that replaced the first. A stored
     * secret is now final -- replacing one means deleting the entry -- so the
     * page says so instead of showing a field.
     */
    public function test_a_filled_entry_refuses_instead_of_offering_to_replace(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN, 'secret' => 'ghp_first']);

        $response = $this->page($ref);

        $response->assertOk();
        $response->assertSee('This secret is already set');
        $response->assertDontSee('name="secret"', false);
        $response->assertDontSee('ghp_first');
    }

    public function test_a_paste_to_an_expired_entry_stores_nothing(): void
    {
        [$entry, $ref] = $this->entry(['expires_at' => now()->subMinute()]);

        $response = $this->post('/vault/' . $ref, ['secret' => 'too_late']);

        $response->assertOk();
        $response->assertSee('expired');
        $entry->refresh();
        $this->assertNull($entry->secret_encrypted);
    }

    public function test_the_paste_page_carries_no_external_script(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $content = (string) $this->page($ref)->getContent();

        // Nothing but our own assets may be executable; the webfont import is a font.
        $this->assertStringContainsString('/vault/vault.js', $content);
        $this->assertStringNotContainsString('unpkg', $content);
        $this->assertStringNotContainsString('cdn.', $content);
    }

    /**
     * Behind the host's nginx Laravel's request root has no port and no scheme,
     * so `asset()` emits absolute URLs that 404 -- one shipped and left the
     * whole page unstyled.
     */
    public function test_every_asset_url_is_root_relative(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $content = (string) $this->page($ref)->getContent();

        $this->assertStringContainsString('href="/vault/vault.css"', $content);
        $this->assertStringContainsString('src="/vault/pa-engine-lockup.svg"', $content);
        $this->assertStringContainsString('data-icon="/vault/icons/github.svg"', $content);

        // Only our own URLs: the steps' provider links are external by design.
        preg_match_all('/(?:href|src)="([^"]*\/vault\/[^"]*)"/', $content, $matches);
        $this->assertNotEmpty($matches[1], 'no vault asset URL found to check');
        foreach ($matches[1] as $url) {
            $this->assertStringStartsWith('/', $url, "asset URL is not root-relative: {$url}");
        }
    }

    /**
     * The tab icon shipped as the Git logo, so the vault looked like GitHub's.
     * `git.svg` still labels the title tile -- it is only the favicon that is ours.
     */
    public function test_the_tab_icon_is_the_engine_mark_not_a_provider_logo(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $content = (string) $this->page($ref)->getContent();

        $this->assertStringContainsString('<link rel="icon" href="/favicon.svg" type="image/svg+xml">', $content);
        $this->assertStringContainsString('href="/favicon.ico"', $content);
        $this->assertStringContainsString('href="/apple-touch-icon.png"', $content);
        $this->assertStringContainsString('href="/site.webmanifest"', $content);
        $this->assertStringNotContainsString('rel="icon" href="/vault/icons/', $content);
    }

    /** `url()->current()` shipped a dead Save button: it posts to port 80 behind the proxy. */
    public function test_the_form_posts_back_to_its_own_relative_url(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);

        $content = (string) $this->page($ref)->getContent();

        $this->assertStringContainsString('<form id="vault-form" method="POST" action="/vault/' . $ref . '"', $content);
    }

    /** The lede and `default.md` carry the same sentence; rendering both repeated the page. */
    public function test_the_generic_screen_does_not_repeat_itself(): void
    {
        [, $ref] = $this->entry(['type' => 'some_future_secret']);

        $content = (string) $this->page($ref)->getContent();

        $this->assertSame(
            1,
            substr_count($content, 'You were sent here by an assistant that needs this value'),
            'the generic screen restates its own opening sentence'
        );
        $this->assertStringContainsString('used only where you were told it', $content);
    }

    /** The unavailable screen shipped a blank tile: a white mark on a white tile. */
    public function test_no_screen_puts_a_white_icon_on_the_white_tile(): void
    {
        [, $ref] = $this->entry(['type' => SecretVaultEntry::TYPE_GIT_TOKEN]);
        [, $cf] = $this->entry(['type' => SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN]);

        $screens = [
            $this->page($ref),
            $this->page($cf),
            $this->page('a-token-that-was-never-minted'),
        ];

        foreach ($screens as $response) {
            $content = (string) $response->getContent();

            // Only the title tile: the "Other" option draws `git-white.svg` on black, correctly.
            preg_match('/<span class="tile">(.*?)<\/span>/s', $content, $tile);

            $this->assertNotEmpty($tile, 'no title tile rendered');
            $this->assertStringNotContainsString(
                'git-white.svg',
                $tile[1],
                'a white icon is rendered inside the white title tile'
            );
        }
    }
}
