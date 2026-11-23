<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\SecretFileEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * A datastore's `*_FILE` secret given to it as the plain variable, and nothing else touched.
 */
class SecretFileEnvironmentTest extends TestCase
{
    private const DECLARED = ['db-password' => ['file' => 'secrets/db-password']];

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private static function inlined(string $name, array $service, string $contents = "changeme\n"): array
    {
        return SecretFileEnvironment::inlined(
            $name,
            $service + ['secrets' => ['db-password']],
            self::DECLARED,
            static fn (string $path): ?string => $path === 'secrets/db-password' ? $contents : null,
            static fn (string $secret): string => 'generated'
        );
    }

    public function test_a_datastores_secret_file_becomes_its_plain_variable(): void
    {
        $service = self::inlined('db', ['image' => 'postgres:16', 'environment' => ['POSTGRES_PASSWORD_FILE' => '/run/secrets/db-password']]);

        $this->assertSame(['POSTGRES_PASSWORD' => 'changeme'], $service['environment']);
    }

    /** Only a datastore refuses a variable set both ways; another service reads its file as it was written to. */
    public function test_a_service_that_is_no_datastore_keeps_its_secret_file(): void
    {
        $environment = ['API_TOKEN_FILE' => '/run/secrets/db-password'];

        $service = self::inlined('worker', ['image' => 'acme/worker:1', 'environment' => $environment]);

        $this->assertSame($environment, $service['environment']);
    }

    /** Compose interpolates the run file, so a `$` in the secret is written as its escape. */
    public function test_a_dollar_in_the_secret_is_escaped_for_compose(): void
    {
        $service = self::inlined('db', ['image' => 'mysql:8', 'environment' => ['MYSQL_PASSWORD_FILE' => '/run/secrets/db-password']], "pa\$word\n");

        $this->assertSame(['MYSQL_PASSWORD' => 'pa$$word'], $service['environment']);
    }

    /** `- TZ` passes the variable through from the environment Compose runs in. */
    public function test_a_listed_variable_without_a_value_is_kept(): void
    {
        $service = self::inlined('db', ['image' => 'mysql:8', 'environment' => ['TZ', 'MYSQL_PASSWORD_FILE=/run/secrets/db-password']]);

        $this->assertSame(['TZ' => null, 'MYSQL_PASSWORD' => 'changeme'], $service['environment']);
    }
}
