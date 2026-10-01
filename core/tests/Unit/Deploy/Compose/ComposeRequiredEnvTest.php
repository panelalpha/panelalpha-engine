<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Deploy\Compose\ComposeRequiredEnv;
use PHPUnit\Framework\TestCase;

class ComposeRequiredEnvTest extends TestCase
{
    private const SEED = 'test-seed';

    /** onetimesecret (#140): the root only includes, the file it includes requires two secrets. */
    private const OTS_ROOT = <<<'YAML'
include:
  - path: docker/compose/docker-compose.simple.yml
YAML;

    private const OTS_SIMPLE = <<<'YAML'
services:
  app:
    image: onetimesecret/onetimesecret:${OTS_IMAGE_TAG:-v0.26.13}
    env_file:
      - ../../.env
    environment:
      - VALKEY_URL=redis://:${VALKEY_PASSWORD:?VALKEY_PASSWORD must be set — add one with 'echo "VALKEY_PASSWORD=$$(openssl rand -hex 32)" >> .env'}@maindb:6379/0
      - SECRET=${SECRET:?SECRET must be set in .env}
      - SESSION_SECRET=${SESSION_SECRET:-}
  maindb:
    image: valkey/valkey:8.1-bookworm
    environment:
      - REDISCLI_AUTH=${VALKEY_PASSWORD:?VALKEY_PASSWORD must be set}
    command: >
      valkey-server
      --requirepass ${VALKEY_PASSWORD:?VALKEY_PASSWORD must be set}
YAML;

    /** kaneo (#144): postgres takes its password from `.env` and nothing puts one there. */
    private const KANEO = <<<'YAML'
services:
  postgres:
    image: postgres:16-alpine
    env_file:
      - .env
    environment:
      POSTGRES_USER: ${POSTGRES_USER:-kaneo}
      POSTGRES_DB: ${POSTGRES_DB:-kaneo}
  kaneo:
    image: ghcr.io/usekaneo/kaneo:latest
    env_file:
      - .env
YAML;

    public function test_required_secrets_in_an_included_file_and_a_command_are_generated(): void
    {
        $files = ComposeRequiredEnv::collect('docker-compose.yml', static fn (string $path): ?string => match ($path) {
            'docker-compose.yml' => self::OTS_ROOT,
            'docker/compose/docker-compose.simple.yml' => self::OTS_SIMPLE,
            default => null,
        });

        $this->assertCount(2, $files);
        $this->assertSame('docker/compose', $files[1]['dir']);

        $missing = ComposeRequiredEnv::missing($files, [], self::SEED);

        $this->assertSame(['VALKEY_PASSWORD', 'SECRET'], array_keys($missing));
        // The same value ComposePlaceholders writes into `environment:`, so both agree.
        $this->assertSame(ComposePlaceholders::generatedSecret('VALKEY_PASSWORD', self::SEED), $missing['VALKEY_PASSWORD']);
    }

    public function test_a_hinted_length_matches_what_compose_placeholders_writes(): void
    {
        $yaml = <<<'YAML'
services:
  server:
    image: rustrak/server
    command: serve --key ${SESSION_SECRET_KEY:?generate one with openssl rand -hex 32}
YAML;

        $missing = ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], [], self::SEED);

        $this->assertSame(64, strlen($missing['SESSION_SECRET_KEY']));
        $this->assertSame(
            ComposePlaceholders::requiredSecretValue('SESSION_SECRET_KEY', self::SEED, 64),
            $missing['SESSION_SECRET_KEY']
        );
        $this->assertStringStartsWith(ComposePlaceholders::generatedSecret('SESSION_SECRET_KEY', self::SEED), $missing['SESSION_SECRET_KEY']);
    }

    public function test_a_value_already_set_is_left_alone(): void
    {
        $files = [['dir' => 'docker/compose', 'yaml' => self::OTS_SIMPLE]];

        $missing = ComposeRequiredEnv::missing($files, ['VALKEY_PASSWORD' => 'mine', 'SECRET' => ''], self::SEED);

        $this->assertSame(['SECRET'], array_keys($missing));
    }

    public function test_escaped_and_non_credential_references_are_not_invented(): void
    {
        $yaml = <<<'YAML'
services:
  app:
    image: acme/app
    command: sh -c 'echo $${API_TOKEN:?unset}'
    environment:
      PUBLIC_HOST: ${PUBLIC_HOST:?set the hostname}
YAML;

        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], [], self::SEED));
    }

    public function test_a_database_reading_its_password_from_env_file_gets_one(): void
    {
        $missing = ComposeRequiredEnv::missing([['dir' => '', 'yaml' => self::KANEO]], [], self::SEED);

        $this->assertSame(['POSTGRES_PASSWORD'], array_keys($missing));
        $this->assertSame(
            [],
            ComposeRequiredEnv::missing([['dir' => '', 'yaml' => self::KANEO]], ['POSTGRES_PASSWORD' => 'set'], self::SEED)
        );
    }

    public function test_the_env_file_of_an_included_file_is_resolved_from_its_own_directory(): void
    {
        $yaml = <<<'YAML'
services:
  db:
    image: mariadb:11
    env_file: ../../.env
YAML;

        $this->assertSame(
            ['MYSQL_ROOT_PASSWORD'],
            array_keys(ComposeRequiredEnv::missing([['dir' => 'deploy/compose', 'yaml' => $yaml]], [], self::SEED))
        );
        // One level up is not the project's .env.
        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => 'deploy/compose', 'yaml' => str_replace('../../', '../', $yaml)]], [], self::SEED));
    }

    public function test_a_password_given_through_a_variable_generates_that_variable(): void
    {
        $yaml = static fn (string $value): string => "services:\n  db:\n    image: postgres:17\n    environment:\n      POSTGRES_PASSWORD: \"{$value}\"\n";

        $this->assertSame(['DB_PASS'], array_keys(ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml('${DB_PASS}')]], [], self::SEED)));
        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml('${DB_PASS:-secret}')]], [], self::SEED));
        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml('${DB_PASS}')]], ['DB_PASS' => 'x'], self::SEED));
        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml('literal')]], [], self::SEED));
    }

    /**
     * Shynet: the database's user, password and name are bare variables the
     * app reads from the same `.env`. Left empty, postgres fell back to its
     * own defaults and the app connected as nobody (502 behind a success).
     */
    public function test_a_databases_user_and_name_variables_get_the_engines_defaults(): void
    {
        $yaml = <<<'YAML'
services:
  shynet:
    image: milesmcc/shynet:latest
    env_file:
      - .env
  db:
    image: postgres
    environment:
      - "POSTGRES_USER=${DB_USER}"
      - "POSTGRES_PASSWORD=${DB_PASSWORD}"
      - "POSTGRES_DB=${DB_NAME}"
YAML;

        $missing = ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], [], self::SEED);

        $this->assertSame('app', $missing['DB_USER']);
        $this->assertSame('app', $missing['DB_NAME']);
        $this->assertSame(ComposePlaceholders::generatedSecret('DB_PASSWORD', self::SEED), $missing['DB_PASSWORD']);
        $this->assertSame(['DB_PASSWORD'], array_keys(
            ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], ['DB_USER' => 'shynet', 'DB_NAME' => 'shynet'], self::SEED)
        ));
        // A default the author wrote is theirs.
        $this->assertArrayNotHasKey('DB_USER', ComposeRequiredEnv::missing(
            [['dir' => '', 'yaml' => str_replace('${DB_USER}', '${DB_USER:-shynet}', $yaml)]],
            [],
            self::SEED
        ));
    }

    public function test_another_way_in_the_author_chose_is_kept(): void
    {
        $trust = "services:\n  db:\n    image: postgres\n    env_file: .env\n    environment:\n      POSTGRES_HOST_AUTH_METHOD: trust\n";
        $random = "services:\n  db:\n    image: mysql:8\n    env_file: .env\n";

        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $trust]], [], self::SEED));
        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $random]], ['MYSQL_RANDOM_ROOT_PASSWORD' => 'yes'], self::SEED));
    }

    public function test_a_database_that_reads_no_env_file_is_not_touched(): void
    {
        $yaml = "services:\n  db:\n    image: postgres:16\n    environment:\n      POSTGRES_USER: app\n";

        $this->assertSame([], ComposeRequiredEnv::missing([['dir' => '', 'yaml' => $yaml]], [], self::SEED));
    }
}
