<?php

namespace Tests\Integration\Deploy\Compose;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Compose\RuntimeSidecars;
use App\Lib\Deploy\Compose\SecretSourceFiles;
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
