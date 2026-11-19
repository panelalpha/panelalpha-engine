<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\ProjectEnvironment;
use App\Lib\Deploy\EnvFile;
use PHPUnit\Framework\TestCase;

/**
 * A `.env` copied over a compose project overrides the project's own choices.
 *
 * The generated compose loads `.env` through `env_file:`, so every key in it
 * becomes a real environment variable -- and a real variable beats
 * `${VAR:-default}`, because compose substitutes its default only when the
 * variable is unset **or empty**. Copying a `.env.example` therefore silently
 * overrides what the project decided for its own containers.
 *
 * Lychee is the case that showed it. Its `.env.example` is the *bare-metal*
 * example and says `DB_CONNECTION=sqlite`; its compose file says
 * `${DB_CONNECTION:-mysql}` and ships a MariaDB beside the app. The app was
 * pointed at SQLite, and `${DB_DATABASE:-lychee}` then handed SQLite the
 * literal string `lychee` as a file path:
 *
 *     Database file at path [lychee] does not exist.
 *
 * `artisan migrate` failed, the entrypoint's `set -e` killed the container,
 * and it restart-looped -- with a healthy MariaDB beside it the whole time.
 * Three deploys failed on it before the container's own output was readable.
 */
class ComposeDefaultsWinTest extends TestCase
{
    /** Lychee's `.env.example`, the lines that matter. */
    private const ENV_EXAMPLE = <<<'ENV'
        APP_NAME=Lychee
        APP_KEY=base64:generatedbytheengine
        DB_CONNECTION=sqlite
        DB_HOST=
        DB_DATABASE=lychee
        DB_USERNAME=
        DB_PASSWORD=
        TIMEZONE=UTC
        ENV;

    /** Its compose file's `x-common-env` anchor, likewise. */
    private const COMPOSE = <<<'YAML'
        x-common-env: &common-env
          APP_KEY: "${APP_KEY}"
          DB_CONNECTION: "${DB_CONNECTION:-mysql}"
          DB_HOST: "${DB_HOST:-lychee_db}"
          DB_DATABASE: "${DB_DATABASE:-lychee}"
          DB_USERNAME: "${DB_USERNAME:-lychee}"
          DB_PASSWORD: "${DB_PASSWORD:-password}"
        YAML;

    /**
     * @return array<string, string>
     */
    private function valuesOf(string $contents): array
    {
        $values = [];
        foreach (EnvFile::parse($contents) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[$row['key']] = $row['value'];
            }
        }

        return $values;
    }

    public function test_the_key_that_pointed_lychee_at_sqlite_is_deferred(): void
    {
        [$trimmed, $dropped] = ProjectEnvironment::deferToComposeDefaults(self::ENV_EXAMPLE, self::COMPOSE);

        $this->assertContains('DB_CONNECTION', $dropped);
        $this->assertArrayNotHasKey(
            'DB_CONNECTION',
            $this->valuesOf($trimmed),
            'compose says mysql; the bare-metal example must not override it'
        );
    }

    /** Every `${VAR:-default}` key the template sets to something else goes, and only those. */
    public function test_only_defaulted_keys_are_deferred(): void
    {
        [$trimmed, $dropped] = ProjectEnvironment::deferToComposeDefaults(self::ENV_EXAMPLE, self::COMPOSE);

        sort($dropped);
        $this->assertSame(
            ['DB_CONNECTION', 'DB_HOST', 'DB_PASSWORD', 'DB_USERNAME'],
            $dropped
        );

        $kept = $this->valuesOf($trimmed);
        $this->assertSame('Lychee', $kept['APP_NAME'], 'a key compose never mentions is untouched');
        $this->assertSame('UTC', $kept['TIMEZONE']);
        $this->assertSame('lychee', $kept['DB_DATABASE'], 'the template agrees with the default');
    }

    /**
     * AgenticResourceRegistry: ClickHouse reads `.env` whole and its health check interpolates
     * `${CLICKHOUSE_PASSWORD:-clickhouse}`. Deferring the template's equal `clickhouse` left the server with no
     * password and the check with one, and the check's `Authentication failed` kept the stack down.
     */
    public function test_a_template_value_equal_to_every_default_is_kept(): void
    {
        $compose = <<<'YAML'
            services:
              observal-clickhouse:
                env_file: [../.env]
                healthcheck:
                  test: ["CMD-SHELL", "clickhouse-client --password ${CLICKHOUSE_PASSWORD:-clickhouse} --query 'SELECT 1'"]
              observal-api:
                environment:
                  CLICKHOUSE_PASSWORD: "${CLICKHOUSE_PASSWORD:-clickhouse}"
                  API_WORKERS: "${API_WORKERS:-2}"
                  CLICKHOUSE_HOST_PORT: "${CLICKHOUSE_HOST_PORT:-8123}"
            YAML;

        [$trimmed, $dropped] = ProjectEnvironment::deferToComposeDefaults(
            "CLICKHOUSE_USER=default\nCLICKHOUSE_PASSWORD=\"clickhouse\"\nAPI_WORKERS=4\n",
            $compose
        );

        $this->assertSame(['API_WORKERS'], $dropped);
        $this->assertSame('clickhouse', $this->valuesOf($trimmed)['CLICKHOUSE_PASSWORD']);
    }

    /** Two defaults that disagree still leave the key to compose. */
    public function test_a_value_equal_to_only_one_of_two_defaults_is_deferred(): void
    {
        [, $dropped] = ProjectEnvironment::deferToComposeDefaults(
            "PORT=3000\n",
            "a: \"\${PORT:-3000}\"\nb: \"\${PORT:-8080}\"\n"
        );

        $this->assertSame(['PORT'], $dropped);
    }

    /**
     * The one that must survive. A bare `${APP_KEY}` has no other source, so
     * the generated key is the only thing standing between the app and
     * MissingAppKeyException.
     */
    public function test_a_bare_reference_keeps_the_generated_value(): void
    {
        [$trimmed, $dropped] = ProjectEnvironment::deferToComposeDefaults(self::ENV_EXAMPLE, self::COMPOSE);

        $this->assertNotContains('APP_KEY', $dropped);
        $this->assertSame('base64:generatedbytheengine', $this->valuesOf($trimmed)['APP_KEY']);
    }

    /** What was suggested, and why it is not in force, stays in the file. */
    public function test_the_deferred_keys_are_commented_not_erased(): void
    {
        [$trimmed] = ProjectEnvironment::deferToComposeDefaults(self::ENV_EXAMPLE, self::COMPOSE);

        $this->assertStringContainsString('# DB_CONNECTION=', $trimmed);
        $this->assertStringContainsString('supplies a default', $trimmed);
    }

    /** A compose file with no defaults changes nothing at all. */
    public function test_a_compose_file_without_defaults_changes_nothing(): void
    {
        $compose = "services:\n  app:\n    environment:\n      APP_KEY: \"\${APP_KEY}\"\n";

        $this->assertSame(
            [self::ENV_EXAMPLE, []],
            ProjectEnvironment::deferToComposeDefaults(self::ENV_EXAMPLE, $compose)
        );
    }

    /** And so does an env file that shares no keys with it. */
    public function test_an_unrelated_env_file_is_untouched(): void
    {
        $env = "FOO=1\nBAR=2\n";

        $this->assertSame([$env, []], ProjectEnvironment::deferToComposeDefaults($env, self::COMPOSE));
    }

    /** An empty default (`${VAR:-}`) is still a default compose will apply. */
    public function test_an_empty_default_still_defers(): void
    {
        [, $dropped] = ProjectEnvironment::deferToComposeDefaults(
            "PUSHER_APP_KEY=fromexample\n",
            'PUSHER_APP_KEY: "${PUSHER_APP_KEY:-}"'
        );

        $this->assertSame(['PUSHER_APP_KEY'], $dropped);
    }
}
