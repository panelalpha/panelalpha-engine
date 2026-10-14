<?php

namespace Tests\Unit\Vault;

use App\Models\SecretVaultEntry;

/**
 * Per-type help for the paste form, by convention: the type is free-form
 * (whatever field the caller named at create time) and the help is a file.
 * `resources/vault/<type>.md` when it exists, `default.md` otherwise -- so
 * an unknown type still explains itself and adding help is dropping a file
 * in, no code.
 */
class VaultHelpTest extends VaultTestCase
{
    public function test_a_type_with_a_file_gets_its_own_help(): void
    {
        $html = SecretVaultEntry::help(SecretVaultEntry::TYPE_GIT_TOKEN);

        $this->assertStringContainsString('<h3>', $html);
        $this->assertStringContainsString('encrypted', $html);
        // CommonMark rendered it, not returned it raw.
        $this->assertStringContainsString('<a href="https://github.com/settings/tokens">', $html);
    }

    public function test_an_unknown_type_falls_back_to_the_default_help(): void
    {
        // Deliberately not a type that has a file. This used to be
        // `cloudflare_api_token`, which then grew one -- the test kept passing
        // by accident only until it did.
        $html = SecretVaultEntry::help('some_other_secret');

        // No heading: the card's own title is the heading.
        $this->assertStringNotContainsString('<h3>', $html);
        $this->assertStringContainsString('You were sent here by an assistant that needs this value', $html);
        $this->assertStringNotContainsString('GitHub', $html);
    }

    /**
     * A Cloudflare token gets its own page, because the permissions are the
     * whole difficulty: the documented pair is not enough unless the token also
     * selects the account and the zone, and neither omission names itself.
     */
    public function test_the_cloudflare_token_type_explains_its_permissions(): void
    {
        $html = SecretVaultEntry::help(SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN);

        $this->assertStringContainsString('Cloudflare Tunnel', $html);
        $this->assertStringContainsString('Edit', $html);
        $this->assertStringContainsString('DNS', $html);
        $this->assertStringContainsString('Account Resources', $html);
        // The token-creation page, which is the one link a reader needs.
        $this->assertStringContainsString('https://dash.cloudflare.com/profile/api-tokens', $html);
    }

    /**
     * Every type claiming a help file has one, and every type with a file is
     * claimed. The two drift silently otherwise: a type listed with no file
     * renders the fallback while pretending otherwise.
     */
    public function test_the_types_with_help_and_the_files_agree(): void
    {
        foreach (SecretVaultEntry::TYPES_WITH_HELP as $type) {
            $file = dirname(__DIR__, 4) . '/core/resources/vault/' . $type . '.md';

            $this->assertFileExists($file, "type '{$type}' is listed as having help but has no file");
        }

        foreach ((array) glob(dirname(__DIR__, 4) . '/core/resources/vault/*.md') as $file) {
            $type = basename((string) $file, '.md');
            if (in_array($type, ['default'], true)) {
                continue;
            }
            $this->assertContains(
                $type,
                SecretVaultEntry::TYPES_WITH_HELP,
                "resources/vault/{$type}.md exists but '{$type}' is not in TYPES_WITH_HELP"
            );
        }
    }

    public function test_the_fallback_is_the_same_file_for_every_unknown_type(): void
    {
        $one = SecretVaultEntry::help('something_new');
        $two = SecretVaultEntry::help('another_thing');

        $this->assertSame($one, $two);
    }

    public function test_a_type_with_junk_characters_never_probes_paths(): void
    {
        // The type doubles as a filename; only snake_case is allowed there.
        // Anything else must fall back, never traverse.
        $html = SecretVaultEntry::help('../../form');

        $this->assertStringContainsString('You were sent here by an assistant that needs this value', $html);
    }

    public function test_rendered_help_has_no_raw_html(): void
    {
        // Rendered with html_input=strip, so a file that grows a <script>
        // tag one day cannot inject into the paste page.
        $html = SecretVaultEntry::help(SecretVaultEntry::TYPE_GIT_TOKEN);

        $this->assertStringNotContainsString('<script', $html);
    }
}