<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\EnvFile;
use App\System\Project\Dind\ProjectEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A `.env.example` copied to `.env` beats the image's own ENV.
 *
 * The generated compose names `.env` in `env_file:`, and a compose
 * `env_file` value outranks an image's `ENV`. So for an image that ships
 * working defaults and a repository that ships a template full of blanks,
 * the blanks win — the engine prefers a developer's fill-in-the-blanks file
 * to the image author's real answer.
 *
 * Homarr is the case (supported-apps#436). Its image ships
 * `ENV DB_URL=/appdata/db/db.sqlite`; its `.env.example` says
 * `DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE`. The copy was byte-identical to
 * the template and the container ran with the blank:
 *
 *   - `run.sh` migrated from /app and *succeeded* into a 364544-byte file
 *     literally named FULL_PATH_TO_YOUR_SQLITE_DB_FILE
 *   - the Next.js standalone server chdir's to its own directory, so it
 *     opened a different, 0-byte one
 *   - `no such table: session`, 1026 restarts in 35 minutes, byte-identical
 *     502 — behind a deploy reported successful
 *
 * This is the opposite of an empty `.env`: not a bare `${VAR}` collapsing to
 * "", but a non-empty value that was never meant to be one.
 *
 * The second-order case is worse than the crash, and is the reason the
 * repeated-character rule is here: the same template sets
 * `SECRET_ENCRYPTION_KEY` to 64 zeroes, which is valid 32-byte hex, so
 * Homarr's own validation accepts it. An app whose blanks happened to be
 * individually harmless would have encrypted every stored integration
 * credential under a value published in a public repository, with nothing
 * failing anywhere to say so.
 */
class EnvExampleBlanksTest extends TestCase
{
    /** Homarr's template, the lines that matter. */
    private const ENV_EXAMPLE = <<<'ENV'
        DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE
        SECRET_ENCRYPTION_KEY=0000000000000000000000000000000000000000000000000000000000000000
        AUTH_PROVIDERS=credentials
        LOG_LEVEL=info
        PORT=7575
        ENV;

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

    public function test_the_blank_no_longer_overrides_the_images_default(): void
    {
        [$trimmed, $blanks] = ProjectEnvironment::withoutTemplatePlaceholders(self::ENV_EXAMPLE);

        $this->assertSame(['DB_URL'], $blanks);
        $this->assertArrayNotHasKey('DB_URL', $this->values($trimmed));
    }

    /** Real values in the same file are values, and stay. */
    public function test_it_leaves_everything_that_is_a_value_alone(): void
    {
        [$trimmed] = ProjectEnvironment::withoutTemplatePlaceholders(self::ENV_EXAMPLE);
        $values = $this->values($trimmed);

        $this->assertSame('credentials', $values['AUTH_PROVIDERS'] ?? null);
        $this->assertSame('info', $values['LOG_LEVEL'] ?? null);
        $this->assertSame('7575', $values['PORT'] ?? null);
    }

    /** Commented, not deleted: the file still says what upstream suggested. */
    public function test_the_suggestion_is_still_readable_in_the_file(): void
    {
        [$trimmed] = ProjectEnvironment::withoutTemplatePlaceholders(self::ENV_EXAMPLE);

        $this->assertStringContainsString('FULL_PATH_TO_YOUR_SQLITE_DB_FILE', $trimmed);
        $this->assertStringContainsString('left to the image', $trimmed);
    }

    /** A key the account set is a person's answer, not a template's blank. */
    public function test_an_account_supplied_value_is_untouched(): void
    {
        [$trimmed, $blanks] = ProjectEnvironment::withoutTemplatePlaceholders(
            self::ENV_EXAMPLE,
            ['DB_URL' => '/data/db.sqlite']
        );

        $this->assertSame([], $blanks);
        $this->assertSame('FULL_PATH_TO_YOUR_SQLITE_DB_FILE', $this->values($trimmed)['DB_URL'] ?? null);
    }

    /**
     * The credential half. 64 zeroes passes Homarr's length and hex checks,
     * so nothing upstream rejects it.
     */
    public function test_a_fixed_length_placeholder_secret_is_replaced(): void
    {
        $this->assertTrue(ComposePlaceholders::isPublishedSecret(
            'SECRET_ENCRYPTION_KEY',
            str_repeat('0', 64)
        ));

        [$filled, $replaced] = ProjectEnvironment::withoutPublishedSecrets(self::ENV_EXAMPLE, 'seed-for-tests');

        $this->assertSame(['SECRET_ENCRYPTION_KEY'], $replaced);
        $this->assertNotSame(
            str_repeat('0', 64),
            $this->values($filled)['SECRET_ENCRYPTION_KEY'] ?? null
        );
    }

    /**
     * Homarr checks the key is exactly 64 characters; a 48-character
     * replacement crash-looped it ("SECRET_ENCRYPTION_KEY has to be 64
     * characters"), measured on a live account.
     */
    public function test_a_fixed_length_placeholder_keeps_its_length(): void
    {
        [$filled] = ProjectEnvironment::withoutPublishedSecrets(self::ENV_EXAMPLE, 'seed-for-tests');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $this->values($filled)['SECRET_ENCRYPTION_KEY'] ?? ''
        );
    }

    /** Seeded, so a redeploy does not invalidate everything it encrypted. */
    public function test_the_generated_secret_survives_a_redeploy(): void
    {
        [$first] = ProjectEnvironment::withoutPublishedSecrets(self::ENV_EXAMPLE, 'seed-for-tests');
        [$second] = ProjectEnvironment::withoutPublishedSecrets(self::ENV_EXAMPLE, 'seed-for-tests');

        $this->assertSame($first, $second);
    }

    /** A real secret that happens to be short is not a placeholder. */
    public function test_a_real_secret_is_not_mistaken_for_one(): void
    {
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('SECRET_KEY', 'aG9wZWZ1bGx5UmFuZG9t'));
        $this->assertFalse(ComposePlaceholders::isPublishedSecret('SECRET_KEY', '0a0a0a0a0a0a0a0a'));
    }

    /**
     * The rule has to stay narrow: it runs over every `.env.example` the
     * engine copies, and dropping a real value would be the same defect
     * pointed the other way.
     */
    public function test_ordinary_values_are_never_blanks(): void
    {
        foreach ([
            'production', 'DEBUG', 'UTC', 'db.sqlite', 'postgres://user:pw@db:5432/app',
            'MY_APP', 'DEFAULT_PATH', 'INFO', 'true', '8080', 'en_US.UTF-8',
        ] as $value) {
            $this->assertFalse(
                ComposePlaceholders::isTemplatePlaceholder($value),
                "{$value} was treated as a blank"
            );
        }
    }

    /** And the shapes that unambiguously are. */
    public function test_the_shapes_that_are_blanks(): void
    {
        foreach ([
            'FULL_PATH_TO_YOUR_SQLITE_DB_FILE', 'CHANGE_ME', 'REPLACE_WITH_YOUR_KEY',
            'INSERT_TOKEN_HERE', '<your-token>', '{{TOKEN}}', '[REDACTED]',
        ] as $value) {
            $this->assertTrue(
                ComposePlaceholders::isTemplatePlaceholder($value),
                "{$value} was treated as a value"
            );
        }
    }

    /**
     * wishlist ships `ORIGIN=`; set-but-empty made adapter-node exit on
     * "Invalid ORIGIN: ''" while unset would have been fine (engine#192).
     */
    public function test_a_blank_public_url_key_gets_the_accounts_address(): void
    {
        $example = "ORIGIN=\nAPP_URL=\"\"\nDOMAIN=\nTOKEN=\nPUBLIC_URL=https://kept.example\n";

        $this->assertSame(['ORIGIN', 'APP_URL'], ProjectEnvironment::blankPublicUrlKeys($example));
        [$contents, $filled] = ProjectEnvironment::withPublicUrlBlanksFilled($example, 'https://wish.example.net');

        $this->assertSame(['ORIGIN', 'APP_URL'], $filled);
        $vars = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $vars[$row['key']] = $row['value'];
            }
        }
        $this->assertSame('https://wish.example.net', $vars['ORIGIN']);
        $this->assertSame('https://wish.example.net', $vars['APP_URL']);
        // Not a whole-URL key, or not blank: left as the template has it.
        $this->assertSame('', $vars['DOMAIN']);
        $this->assertSame('', $vars['TOKEN']);
        $this->assertSame('https://kept.example', $vars['PUBLIC_URL']);
    }

    /** A blank BASE_URL is usually a sub-path prefix meaning "root" (DVinyl), not a missing URL. */
    public function test_a_blank_base_url_is_left_blank(): void
    {
        $example = "BASE_URL=\nAPP_URL=\n";

        $this->assertSame(['APP_URL'], ProjectEnvironment::blankPublicUrlKeys($example));
        [$contents, $filled] = ProjectEnvironment::withPublicUrlBlanksFilled($example, 'https://dvinyl.example.net');

        $this->assertSame(['APP_URL'], $filled);
        $this->assertStringContainsString("BASE_URL=\n", $contents);
    }

    public function test_an_account_override_or_no_address_leaves_the_blank_alone(): void
    {
        $this->assertSame([], ProjectEnvironment::blankPublicUrlKeys("ORIGIN=\n", ['ORIGIN' => 'https://mine.example']));
        $this->assertSame(["ORIGIN=\n", []], ProjectEnvironment::withPublicUrlBlanksFilled("ORIGIN=\n", null));
    }
}
