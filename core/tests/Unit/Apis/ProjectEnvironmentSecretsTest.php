<?php

namespace Tests\Unit\Apis;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\System\Project\Dind\ProjectEnvironment;
use App\Lib\Deploy\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * Blank secrets in a project's `.env.example`, filled in when the engine
 * writes `.env` from it.
 *
 * The generated compose loads `.env` through `env_file:`, so Docker injects
 * every key in it as a real environment variable — and an injected blank
 * beats whatever the image holds. Laravel ships `APP_KEY=`, so the blank
 * shadowed the key `artisan key:generate` had written during the build, and
 * the app answered 500 on MissingAppKey while the container reported healthy.
 *
 * Filling the line rather than deleting it, because the blank is a
 * placeholder the framework's own tooling rewrites in place: a `.env` without
 * it is one `key:generate` silently does nothing to. That was tried first and
 * traded a shadowed key for no key at all.
 */
class ProjectEnvironmentSecretsTest extends TestCase
{
    private function fill(string $contents, string $seed = 'seed-alice'): string
    {
        $method = new \ReflectionMethod(ProjectEnvironment::class, 'withGeneratedSecrets');
        $method->setAccessible(true);

        return $method->invoke(null, $contents, $seed);
    }

    /**
     * @return array<string, string>
     */
    private function vars(string $contents): array
    {
        $out = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $out[$row['key']] = $row['value'];
            }
        }

        return $out;
    }

    public function test_a_blank_app_key_is_filled(): void
    {
        $vars = $this->vars($this->fill("APP_NAME=Laravel\nAPP_KEY=\nAPP_DEBUG=true\n"));

        $this->assertStringStartsWith('base64:', $vars['APP_KEY']);
    }

    public function test_the_generated_key_is_a_real_32_byte_key(): void
    {
        // Laravel's AES-256-CBC cipher rejects anything else at boot, which
        // would swap one 500 for another.
        $vars = $this->vars($this->fill("APP_KEY=\n"));
        $raw = base64_decode(substr($vars['APP_KEY'], strlen('base64:')), true);

        $this->assertNotFalse($raw);
        $this->assertSame(32, strlen($raw));
    }

    public function test_the_key_line_stays_a_line(): void
    {
        // Not appended, not duplicated: the placeholder is rewritten where it
        // sits, so the file a customer opens still reads the way its author
        // wrote it.
        $filled = $this->fill("# Application\nAPP_NAME=Laravel\nAPP_KEY=\n\n# Database\nDB_HOST=db\n");

        $this->assertSame(1, substr_count($filled, 'APP_KEY='));
        $this->assertStringContainsString('# Application', $filled);
        $this->assertStringContainsString('# Database', $filled);
        $this->assertLessThan(strpos($filled, 'DB_HOST'), strpos($filled, 'APP_KEY'));
    }

    /**
     * This used to assert the opposite -- that a key already present was the
     * project's and was left alone. That assumption is what let Firefly III's
     * placeholder through: it is a well-formed 32-character key, so it looked
     * set rather than published, and every deploy would have shared it.
     *
     * A key in a `.env.example` belongs to everybody who cloned the
     * repository. A project that set its own key has a `.env`, and this
     * function is never reached for it -- {@see ProjectEnvironment} only calls
     * it on the example path.
     */
    public function test_a_key_present_in_the_template_is_not_the_projects(): void
    {
        $published = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

        $filled = $this->vars($this->fill("APP_KEY={$published}\n"))['APP_KEY'];

        $this->assertNotSame($published, $filled);
        $this->assertStringStartsWith('base64:', $filled);
    }

    public function test_two_accounts_do_not_share_a_key(): void
    {
        $a = $this->vars($this->fill("APP_KEY=\n", 'seed-alice'))['APP_KEY'];
        $b = $this->vars($this->fill("APP_KEY=\n", 'seed-bob'))['APP_KEY'];

        $this->assertNotSame($a, $b);
    }

    public function test_other_blank_values_are_left_exactly_as_they_are(): void
    {
        // Only a secret the engine can safely invent is touched. A blank
        // DB_PASSWORD may well be the project's intent, and inventing one
        // would point the app at a database it cannot reach.
        $filled = $this->fill("DB_PASSWORD=\nMAIL_FROM_ADDRESS=\nAPP_KEY=\n");
        $vars = $this->vars($filled);

        $this->assertSame('', $vars['DB_PASSWORD']);
        $this->assertSame('', $vars['MAIL_FROM_ADDRESS']);
    }

    public function test_a_project_with_no_app_key_is_untouched(): void
    {
        $contents = "DB_HOST=127.0.0.1\nDB_PORT=3306\n";

        $this->assertSame($contents, $this->fill($contents));
    }

    public function test_an_empty_example_is_untouched(): void
    {
        $this->assertSame('', $this->fill(''));
    }

    /**
     * BookStack ships `APP_KEY=SomeRandomString` -- not blank, and not a key.
     * The deploy ran key:generate successfully, this placeholder was injected
     * into the container environment where Dotenv will not overwrite it, and
     * every request answered 500 on "Unsupported cipher or incorrect key
     * length" while the container reported healthy.
     */
    public function test_a_placeholder_that_is_not_a_usable_key_is_replaced(): void
    {
        $filled = $this->vars($this->fill("APP_NAME=BookStack\nAPP_KEY=SomeRandomString\n"));

        $this->assertNotSame('SomeRandomString', $filled['APP_KEY']);
        $this->assertStringStartsWith('base64:', $filled['APP_KEY']);
        $this->assertSame(32, strlen((string) base64_decode(substr($filled['APP_KEY'], 7), true)));
        $this->assertSame('BookStack', $filled['APP_NAME'], 'other keys are untouched');
    }

    /**
     * Firefly III's placeholder is exactly 32 characters, so AES-256-CBC
     * accepts it and nothing fails -- every deploy of it would have shared one
     * encryption and session key, published on GitHub, silently. A key that
     * *works* is the dangerous case, not the safe one.
     */
    public function test_a_placeholder_that_happens_to_be_a_valid_length_is_still_replaced(): void
    {
        $placeholder = str_repeat('a', 32);

        $filled = $this->vars($this->fill("APP_KEY={$placeholder}\n"));

        $this->assertNotSame($placeholder, $filled['APP_KEY']);
        $this->assertStringStartsWith('base64:', $filled['APP_KEY']);
    }

    /**
     * Even a well-formed base64 key in a template is a published secret.
     * This function only ever reads a .env.example; a project with its own
     * key has a .env, and never reaches here.
     */
    public function test_a_well_formed_key_in_the_template_is_still_replaced(): void
    {
        $published = 'base64:' . base64_encode(str_repeat("\x01", 32));

        $filled = $this->vars($this->fill("APP_KEY={$published}\n"));

        $this->assertNotSame($published, $filled['APP_KEY']);
    }

    /**
     * #178: this used to assert that every deploy got a different key. Every
     * deploy re-clones and lands here again, so that rotated the key -- and
     * every session and encrypted column with it -- on each redeploy. The
     * same account now gets the same key; other accounts still do not.
     */
    public function test_the_same_account_gets_the_same_key_on_every_deploy(): void
    {
        $a = $this->vars($this->fill("APP_KEY=SomeRandomString\n"))['APP_KEY'];
        $b = $this->vars($this->fill("APP_KEY=SomeRandomString\n"))['APP_KEY'];

        $this->assertSame($a, $b);
        $this->assertSame(ComposePlaceholders::publishedSecret('APP_KEY', 'seed-alice'), $a);
    }

    /** A project with no APP_KEY line at all is not given one. */
    public function test_a_file_without_an_app_key_is_untouched(): void
    {
        $contents = "APP_NAME=Thing\nDB_HOST=localhost\n";

        $this->assertSame($contents, $this->fill($contents));
    }

    /** Saleor's `.env.example` says SECRET_KEY=changeme; copied as-is, it went live. */
    public function test_a_published_placeholder_secret_in_the_example_is_replaced(): void
    {
        [$filled, $keys] = ProjectEnvironment::withoutPublishedSecrets("DEBUG=False\nSECRET_KEY=changeme\n", 'seed');
        [$again] = ProjectEnvironment::withoutPublishedSecrets("DEBUG=False\nSECRET_KEY=changeme\n", 'seed');
        $secret = $this->vars($filled)['SECRET_KEY'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $secret);
        $this->assertSame($secret, $this->vars($again)['SECRET_KEY'], 'stable for the same account');
        $this->assertSame(['SECRET_KEY'], $keys);
        $this->assertSame('False', $this->vars($filled)['DEBUG']);
    }

    public function test_blanks_third_party_keys_and_real_secrets_are_left_as_written(): void
    {
        $contents = "DB_PASSWORD=\nSTRIPE_API_KEY=changeme\nSECRET_KEY=8f3a9c1e5b7d2f4a6c8e0b1d3f5a7c9e1b3d5f7a\n";

        $this->assertSame([$contents, []], ProjectEnvironment::withoutPublishedSecrets($contents, 'seed'));
    }

    public function test_the_accounts_env_vars_win_over_a_generated_secret(): void
    {
        $overrides = ['SECRET_KEY' => 'mine'];
        [$base, $keys] = ProjectEnvironment::withoutPublishedSecrets("SECRET_KEY=changeme\n", 'seed', $overrides);

        $this->assertSame([], $keys);
        $this->assertSame('mine', $this->vars(EnvFile::merge($base, $overrides))['SECRET_KEY']);
    }
}
