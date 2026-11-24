<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\RuntimeSidecars;
use App\Lib\Deploy\Compose\SecretSourceFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A database kept from the repository's compose file that reads its password from a secret (Cover Your Tracks'
 * `MYSQL_PASSWORD_FILE: /run/secrets/mysql-password`, Arkham Horror's `POSTGRES_PASSWORD_FILE`) failed every
 * deploy with `service "db" refers to undefined secret`: the generated file carried the service and not the
 * top-level `secrets:` it names, and the engine set `MYSQL_PASSWORD` beside `MYSQL_PASSWORD_FILE`, which the
 * official images refuse.
 */
class RuntimeSidecarsSecretsTest extends TestCase
{
    private const COMPOSE = <<<'YAML'
        secrets:
          db-password:
            file: docker/secrets/db-password
          admin-password:
            file: docker/secrets/admin-password
          unused:
            file: docker/secrets/unused
        configs:
          proxy_conf:
            file: ./proxy.conf
        services:
          db:
            image: mysql:8
            environment:
              MYSQL_PASSWORD_FILE: /run/secrets/db-password
              MYSQL_USER: tracks
              MYSQL_DATABASE: tracks
            secrets:
              - db-password
          proxy:
            image: nginx
            configs:
              - source: proxy_conf
                target: /etc/nginx/conf.d/default.conf
          app:
            build: .
            secrets:
              - db-password
              - admin-password
        YAML;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-secrets-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/docker/secrets', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function extract(): array
    {
        return RuntimeSidecars::fromYaml(self::COMPOSE, false, null, 'acme/tracks', 2048, 'seed', null, [], null, $this->dir);
    }

    public function test_the_secrets_and_configs_the_services_name_are_carried(): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "changeme\n");

        $found = $this->extract();

        $this->assertSame(['db-password', 'admin-password'], array_keys($found['secrets'] ?? []));
        $this->assertSame(['proxy_conf'], array_keys($found['configs'] ?? []));
        $this->assertSame(['db-password', 'admin-password'], $found['app_secrets'] ?? null, 'the build takes the app service\'s place');
    }

    public function test_a_shipped_secret_file_becomes_the_password_everyone_uses(): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "changeme\n");

        $found = $this->extract();
        $db = $found['services']['db']['environment'];

        $this->assertSame('changeme', $db['MYSQL_PASSWORD']);
        $this->assertArrayNotHasKey('MYSQL_PASSWORD_FILE', $db, 'the images refuse a variable set both ways');
        $this->assertSame('changeme', $found['env']['DB_PASSWORD'] ?? null);
    }

    public function test_a_secret_file_the_repository_leaves_out_gets_the_value_written_for_it(): void
    {
        $found = $this->extract();

        $this->assertSame(SecretSourceFiles::value('db-password', 'seed'), $found['services']['db']['environment']['MYSQL_PASSWORD']);
        $this->assertSame(
            ['docker/secrets/db-password' => 'db-password', 'docker/secrets/admin-password' => 'admin-password'],
            SecretSourceFiles::missing(['secrets' => $found['secrets']], fn (string $path): bool => is_file($this->dir.'/'.$path))
        );
    }

    /** An empty file, `external: true` or an `environment:` source holds nothing the engine can read. */
    public function test_a_secret_that_cannot_be_read_gives_the_database_the_password_the_app_gets(): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "\n");
        $sources = [
            'an empty file' => self::COMPOSE,
            'an external secret' => str_replace('file: docker/secrets/db-password', 'external: true', self::COMPOSE),
            'an environment source' => str_replace('file: docker/secrets/db-password', 'environment: DB_PASSWORD', self::COMPOSE),
        ];

        foreach ($sources as $source => $compose) {
            $found = RuntimeSidecars::fromYaml($compose, false, null, 'acme/tracks', 2048, 'seed', null, [], null, $this->dir);
            $db = $found['services']['db']['environment'];

            $this->assertArrayNotHasKey('MYSQL_PASSWORD_FILE', $db, $source);
            $this->assertNotSame('', $db['MYSQL_PASSWORD'] ?? '', $source);
            $this->assertSame($db['MYSQL_PASSWORD'], $found['env']['DB_PASSWORD'] ?? null, $source);
        }
    }

    /** @return array<string, array{string}> */
    public static function unfilledSources(): array
    {
        return [
            'an unset environment source' => ['environment: DB_PASSWORD'],
            'an external secret' => ['external: true'],
            'a content source' => ['content: changeme'],
            'an empty file' => ['file: docker/secrets/db-password'],
        ];
    }

    /**
     * Compose refuses a service naming a secret it cannot fill: an unset `environment:`, an `external:` one outside
     * Swarm, or a `content:` (a config's source, not a secret's). The database no longer reads it, and nothing else does.
     */
    #[DataProvider('unfilledSources')]
    public function test_a_settled_secret_only_the_database_read_is_declared_and_mounted_nowhere(string $declaration): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "\n");

        $compose = $this->rendered(self::databaseOnly($declaration, ['- db-password'], 'MYSQL_PASSWORD_FILE: /run/secrets/db-password'));
        $db = $compose['services']['db'];

        $this->assertArrayNotHasKey('secrets', $db);
        $this->assertArrayNotHasKey('secrets', $compose);
        $this->assertArrayNotHasKey('MYSQL_PASSWORD_FILE', $db['environment']);
        $this->assertSame($db['environment']['MYSQL_PASSWORD'], $compose['services']['app']['environment']['DB_PASSWORD'] ?? null);
    }

    /** @return array<string, array{list<string>}> */
    public static function longFormEntries(): array
    {
        return [
            'an absolute target' => [['- source: db-password', '  target: /run/secrets/mysql-pw']],
            'a relative target' => [['- source: db-password', '  target: mysql-pw']],
        ];
    }

    /**
     * The long form names the secret by `source:` and mounts it at its `target:`, absolute or under /run/secrets.
     *
     * @param  list<string>  $lines
     */
    #[DataProvider('longFormEntries')]
    public function test_a_long_form_entry_goes_as_the_short_form_does(array $lines): void
    {
        $compose = $this->rendered(self::databaseOnly('environment: DB_PASSWORD', [...$lines, '- other'], 'MYSQL_PASSWORD_FILE: /run/secrets/mysql-pw'));

        $this->assertSame(['other'], $compose['services']['db']['secrets']);
        $this->assertSame(['other'], array_keys($compose['secrets']));
    }

    /** @return array<string, array{string}> */
    public static function sourcesComposeCannotFill(): array
    {
        return [
            'an unset environment source' => ['environment: DB_PASSWORD'],
            'an external secret' => ['external: true'],
        ];
    }

    /** Another service naming a secret Compose cannot fill would refuse to start as well: it loses it too. */
    #[DataProvider('sourcesComposeCannotFill')]
    public function test_a_settled_secret_compose_cannot_fill_leaves_the_app_and_every_other_service(string $declaration): void
    {
        $compose = $this->rendered(str_replace('file: docker/secrets/db-password', $declaration, self::COMPOSE)
            ."\n  backup:\n    image: acme/mysql-backup:1\n    secrets:\n      - db-password\n");

        $this->assertArrayNotHasKey('secrets', $compose['services']['db']);
        $this->assertArrayNotHasKey('secrets', $compose['services']['backup']);
        $this->assertSame(['admin-password'], $compose['services']['app']['secrets']);
        $this->assertSame(['admin-password'], array_keys($compose['secrets']));
    }

    /** @return array<string, array{string, array<string, list<?string>>}> */
    public static function sourcesComposeFills(): array
    {
        return [
            'an empty file' => [self::COMPOSE, []],
            'a set environment source' => [str_replace('file: docker/secrets/db-password', 'environment: DB_PASSWORD', self::COMPOSE), ['DB_PASSWORD' => ['s3cret']]],
        ];
    }

    /**
     * A source compose always has a value for fills the secret, so whoever else mounts it keeps it.
     *
     * @param  array<string, list<?string>>  $env
     */
    #[DataProvider('sourcesComposeFills')]
    public function test_a_settled_secret_compose_can_fill_stays_for_the_services_still_naming_it(string $yaml, array $env): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "\n");

        $compose = $this->rendered($yaml, $env);

        $this->assertArrayNotHasKey('secrets', $compose['services']['db']);
        $this->assertSame(['db-password', 'admin-password'], $compose['services']['app']['secrets']);
        $this->assertSame(['db-password', 'admin-password'], array_keys($compose['secrets']));
    }

    /** A variable that may be unset (a key only `.env.example` has) can leave compose without the secret's value. */
    public function test_an_environment_source_that_may_be_unset_counts_as_unset(): void
    {
        $yaml = str_replace('file: docker/secrets/db-password', 'environment: DB_PASSWORD', self::COMPOSE);

        $compose = $this->rendered($yaml, ['DB_PASSWORD' => ['example', null]]);

        $this->assertSame(['admin-password'], $compose['services']['app']['secrets']);
        $this->assertSame(['admin-password'], array_keys($compose['secrets']));
    }

    /** Another of the database's variables still reads the file, so its mount stays. */
    public function test_a_settled_secret_the_database_still_names_stays_mounted_there(): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "\n");
        $yaml = self::databaseOnly(
            'file: docker/secrets/db-password',
            ['- db-password'],
            "MYSQL_PASSWORD_FILE: /run/secrets/db-password\n      REPLICATION_PASSWORD_FILE: /run/secrets/db-password"
        );

        $compose = $this->rendered($yaml);

        $this->assertSame(['db-password'], $compose['services']['db']['secrets']);
        $this->assertSame(['db-password'], array_keys($compose['secrets']));
    }

    /** A secret the engine inlined is mounted and declared as the repository wrote it. */
    public function test_a_secret_that_is_inlined_keeps_its_mount_and_declaration(): void
    {
        file_put_contents($this->dir.'/docker/secrets/db-password', "changeme\n");

        $compose = $this->rendered(self::databaseOnly('file: docker/secrets/db-password', ['- db-password'], 'MYSQL_PASSWORD_FILE: /run/secrets/db-password'));

        $this->assertSame(['db-password'], $compose['services']['db']['secrets']);
        $this->assertSame(['db-password' => ['file' => 'docker/secrets/db-password']], $compose['secrets']);
        $this->assertSame('changeme', $compose['services']['db']['environment']['MYSQL_PASSWORD']);
    }

    /**
     * A database reading `db-password` and an app that reads no secret.
     *
     * @param  list<string>  $entries  the database's `secrets:` lines
     */
    private static function databaseOnly(string $declaration, array $entries, string $variable): string
    {
        $indented = implode('', array_map(static fn (string $line): string => "      {$line}\n", $entries));

        return "secrets:\n  db-password:\n    {$declaration}\n  other:\n    file: docker/secrets/other\n"
            ."services:\n  app:\n    build: .\n  db:\n    image: mysql:8\n    environment:\n      {$variable}\n"
            ."      MYSQL_USER: tracks\n      MYSQL_DATABASE: tracks\n    secrets:\n{$indented}";
    }

    /**
     * The run file the engine writes from $yaml's extract.
     *
     * @param  array<string, list<?string>|string>  $env
     * @return array<string, mixed>
     */
    private function rendered(string $yaml, array $env = []): array
    {
        $found = RuntimeSidecars::fromYaml($yaml, false, null, 'acme/tracks', 2048, 'seed', null, $env, null, $this->dir);

        return Yaml::parse(GeneratedCompose::render(
            ['build' => '.', 'environment' => $found['env']],
            ['sidecars' => $found['services']] + array_intersect_key($found, array_flip(['secrets', 'configs', 'app_secrets']))
        ));
    }

    public function test_the_generated_file_declares_them_and_mounts_the_apps(): void
    {
        $found = $this->extract();

        $compose = Yaml::parse(GeneratedCompose::render(['build' => '.'], [
            'sidecars' => $found['services'],
            'secrets' => $found['secrets'],
            'configs' => $found['configs'],
            'app_secrets' => $found['app_secrets'],
        ]));

        $this->assertSame(['db-password', 'admin-password'], array_keys($compose['secrets']));
        $this->assertSame(['proxy_conf'], array_keys($compose['configs']));
        $this->assertSame(['db-password', 'admin-password'], $compose['services']['app']['secrets']);
    }

    public function test_only_paths_inside_the_checkout_are_written(): void
    {
        $this->assertTrue(SecretSourceFiles::isCheckoutPath('./config/postgres_password.txt'));
        $this->assertFalse(SecretSourceFiles::isCheckoutPath('/etc/shadow'));
        $this->assertFalse(SecretSourceFiles::isCheckoutPath('../outside'));
        $this->assertFalse(SecretSourceFiles::isCheckoutPath('${SECRETS_DIR}/x'));
    }
}
