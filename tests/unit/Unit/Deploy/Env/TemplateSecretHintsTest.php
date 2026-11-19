<?php

namespace Tests\Unit\Deploy\Env;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Env\SecretFormat;
use App\Lib\Deploy\Env\TemplateSecretHints;
use App\Lib\Deploy\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * An app that validates its key refuses the engine's default 48 hex characters, and an app whose template
 * leaves the key blank, commented out or inside JSON gets no key at all: every one of these exited at
 * start and nothing answered. The lines are the repositories' own.
 */
class TemplateSecretHintsTest extends TestCase
{
    private const SEED = 'account-seed';

    /** @return array<string, string> */
    private function values(string $contents): array
    {
        $values = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
    private function fill(string $template, array $overrides = []): array
    {
        [$contents, $shaped] = TemplateSecretHints::fill($template, self::SEED, $overrides);

        return [$this->values($contents), $shaped];
    }

    /** gorkem-bwl/atlas: "TOKEN_ENCRYPTION_KEY must be a 64-character hex string". */
    public function test_a_published_placeholder_gets_the_exact_hex_length_its_comment_requires(): void
    {
        [$values, $shaped] = $this->fill(<<<'ENV'
            # Must be exactly 64 hex characters (openssl rand -hex 32)
            TOKEN_ENCRYPTION_KEY=CHANGE_ME
            ENV);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $values['TOKEN_ENCRYPTION_KEY']);
        $this->assertSame(['TOKEN_ENCRYPTION_KEY' => '64 hex characters'], $shaped);
    }

    /** seoes/proval: "ENCRYPTION_KEY must be 32 bytes long", with a sample value nobody flagged. */
    public function test_a_sample_value_outside_the_format_its_inline_command_makes_is_replaced(): void
    {
        [$values] = $this->fill('ENCRYPTION_KEY="ABCD1234=" # openssl rand -base64 32');

        $this->assertSame(32, strlen((string) base64_decode($values['ENCRYPTION_KEY'], true)));
    }

    /** chiban-org/chiban-oss: "CHIBAN_SECRET_KEYS: every key must be base64 of exactly 32 bytes". */
    public function test_a_blank_inside_json_is_filled_in_the_shape_its_words_name(): void
    {
        [$values] = $this->fill(<<<'ENV'
            CHIBAN_ACTIVE_KEY_ID=k1
            CHIBAN_SECRET_KEYS={"k1":"REPLACE_WITH_BASE64_32_BYTES"}
            ENV);

        $keys = json_decode($values['CHIBAN_SECRET_KEYS'], true);
        $this->assertIsArray($keys);
        $this->assertSame(32, strlen((string) base64_decode((string) $keys['k1'], true)));
        $this->assertSame('k1', $values['CHIBAN_ACTIVE_KEY_ID']);
    }

    /** jakobbjelver/messer-swipe: "AUTH_SECRET must be at least 32 characters". */
    public function test_an_empty_key_the_comment_asks_to_generate_is_generated(): void
    {
        [$values] = $this->fill(<<<'ENV'
            # AUTH_SECRET is required in production. Generate one with:
            #   node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
            AUTH_SECRET=
            ENV);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $values['AUTH_SECRET']);
    }

    /** fabriziosalmi/certmate: "API token length must be between 32 and 512 characters". */
    public function test_a_token_the_comment_says_how_to_make_counts_as_the_apps_own(): void
    {
        [$values] = $this->fill(<<<'ENV'
            # API Authentication
            # Generate one with: python -c "import secrets; print(secrets.token_urlsafe(32))"
            # Alternatively, set API_BEARER_TOKEN_FILE to a path containing the token.
            API_BEARER_TOKEN=your_secure_api_token_here
            ENV);

        $this->assertGreaterThanOrEqual(43, strlen($values['API_BEARER_TOKEN']));
        $this->assertNotSame('your_secure_api_token_here', $values['API_BEARER_TOKEN']);
    }

    /** labstack/pitot: "PITOT_AUTH_CODE_SECRET must be at least 32 characters", every entry commented out. */
    public function test_a_commented_out_key_the_template_says_the_app_requires_is_set(): void
    {
        [$values] = $this->fill(<<<'ENV'
            # Every entry is commented out so copying this template sets nothing, preserving image ENV.
            # Local authentication requires PITOT_AUTH_CODE_SECRET (32+ characters).
            # Chat is disabled unless PITOT_AI_PROVIDER selects anthropic or openai; its key is required.

            # ANTHROPIC_API_KEY; string; secret
            # Required when PITOT_AI_PROVIDER is anthropic.
            # ANTHROPIC_API_KEY=

            # PITOT_AUTH_CODE_SECRET; string; secret
            # Signs sign-in codes and login links; needs at least 32 characters in local mode.
            # Default: (empty)
            # PITOT_AUTH_CODE_SECRET=
            ENV);

        $this->assertGreaterThanOrEqual(32, strlen($values['PITOT_AUTH_CODE_SECRET'] ?? ''));
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $values);
    }

    /** A key an app already encrypts with keeps the value every earlier deploy gave it. */
    public function test_a_published_placeholder_with_only_a_command_keeps_the_engines_default(): void
    {
        [$values, $shaped] = $this->fill(<<<'ENV'
            # openssl rand -hex 32
            JWT_SECRET=changeme
            ENV);

        $this->assertSame('changeme', $values['JWT_SECRET']);
        $this->assertSame([], $shaped);
    }

    public function test_a_value_already_in_the_asked_format_is_kept(): void
    {
        $key = str_repeat('ab', 32);
        [$values, $shaped] = $this->fill("# Must be exactly 64 hex characters\nTOKEN_ENCRYPTION_KEY={$key}");

        $this->assertSame($key, $values['TOKEN_ENCRYPTION_KEY']);
        $this->assertSame([], $shaped);
    }

    public function test_an_optional_blank_stays_blank(): void
    {
        [$values] = $this->fill(<<<'ENV'
            # Optional: leave empty to disable signing (openssl rand -hex 32)
            WEBHOOK_SECRET=
            ENV);

        $this->assertSame('', $values['WEBHOOK_SECRET']);
    }

    public function test_passwords_and_third_party_credentials_are_left_alone(): void
    {
        [$values, $shaped] = $this->fill(<<<'ENV'
            # at least 14 characters
            ADMIN_PASSWORD=changeme
            # Your OpenAI key, at least 32 characters
            OPENAI_API_KEY=
            ENV);

        $this->assertSame('changeme', $values['ADMIN_PASSWORD']);
        $this->assertSame('', $values['OPENAI_API_KEY']);
        $this->assertSame([], $shaped);
    }

    public function test_a_key_the_account_set_is_kept(): void
    {
        [$values] = $this->fill(
            "# Must be exactly 64 hex characters\nTOKEN_ENCRYPTION_KEY=CHANGE_ME",
            ['TOKEN_ENCRYPTION_KEY' => 'mine']
        );

        $this->assertSame('CHANGE_ME', $values['TOKEN_ENCRYPTION_KEY']);
    }

    public function test_the_generated_value_is_the_same_on_every_deploy(): void
    {
        $template = "# Must be exactly 64 hex characters\nTOKEN_ENCRYPTION_KEY=CHANGE_ME";

        $this->assertSame($this->fill($template)[0], $this->fill($template)[0]);
    }

    public function test_formats_read_from_the_words_projects_use(): void
    {
        $cases = [
            'openssl rand -hex 16' => '32 hex characters',
            "randomBytes(32).toString('base64')" => 'base64 of 32 bytes',
            'must be a base64-encoded 32-byte key' => 'base64 of 32 bytes',
            'must be 32 bytes, hex or base64 encoded' => 'base64 of 32 bytes',
            'must be exactly 32 bytes long' => '32 characters',
            'a 64-character hex string (32 bytes for AES-256)' => '64 hex characters',
            'at least 50 characters' => 'at least 50 characters',
            'secrets.token_hex(24)' => '48 hex characters',
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, SecretFormat::fromText($text)?->describe(), $text);
        }
        $this->assertNull(SecretFormat::fromText('Signs session cookies'));
    }

    public function test_a_fixed_length_hex_key_shorter_than_the_default_is_cut_to_size(): void
    {
        $this->assertSame(
            substr(ComposePlaceholders::generatedSecret('KEY', self::SEED), 0, 32),
            ComposePlaceholders::hexSecretOfLength('KEY', self::SEED, 32)
        );
    }
}
