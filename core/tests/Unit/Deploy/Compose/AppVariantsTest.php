<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use PHPUnit\Framework\TestCase;

/**
 * A workstation compose file with several variants of the app and a test
 * suite beside them (Zerobyte, engine#422): the build stands in for the
 * production variant, and the suite's helpers do not run in the tenant.
 */
class AppVariantsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function zerobyte(): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/compose/zerobyte-compose.yaml');

        return RuntimeSidecars::fromYaml($raw, false, null, 'github.com/nicotsx/zerobyte');
    }

    public function test_the_production_variants_env_is_the_apps_and_the_dev_one_is_not(): void
    {
        $env = self::zerobyte()['app_env'];

        $this->assertArrayNotHasKey('NODE_ENV', $env);
        // zerobyte-prod's own, which the production build stands in for.
        $this->assertSame('debug', $env['LOG_LEVEL'] ?? null);
        // Nothing from the e2e variants.
        $this->assertArrayNotHasKey('DISABLE_RATE_LIMITING', $env);
        $this->assertArrayNotHasKey('TRUSTED_ORIGINS', $env);
    }

    public function test_the_test_suites_helpers_are_dropped_and_their_env_does_not_reach_the_app(): void
    {
        $result = self::zerobyte();

        $this->assertSame([], array_keys($result['services']));
        $this->assertSame(['zerobyte-e2e-https', 'tinyauth-app', 'tinyauth'], $result['dropped_test_services']);
        foreach (array_keys($result['env'] + $result['app_env']) as $key) {
            $this->assertDoesNotMatchRegularExpression('/^(TINYAUTH_|CADDY_)/', (string) $key);
        }
    }

    public function test_the_published_secret_warning_names_only_the_variant_used(): void
    {
        $this->assertSame(['zerobyte-prod' => ['APP_SECRET']], self::zerobyte()['published_secrets']);
    }

    public function test_a_dev_variant_gives_way_to_one_that_is_not_dev(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          web-dev:
            build: .
            volumes: ['./:/app']
            environment: [NODE_ENV=development, A=dev]
          web:
            build: .
            volumes: ['./:/app']
            environment: [A=main, B=main]
        YAML, false, null, 'github.com/acme/web');

        $this->assertSame(['A' => 'main', 'B' => 'main'], $result['app_env']);
    }

    public function test_variants_with_no_signal_are_merged_as_before(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          api:
            build: .
            volumes: ['./:/app']
            environment: [A=api]
          worker:
            build: .
            volumes: ['./:/app']
            environment: [A=worker, B=worker]
        YAML, false, null, 'github.com/acme/web');

        $this->assertSame(['A' => 'api', 'B' => 'worker'], $result['app_env']);
    }

    public function test_a_sidecar_the_app_also_depends_on_is_not_part_of_the_suite(): void
    {
        $result = RuntimeSidecars::fromYaml(<<<'YAML'
        services:
          app:
            build: .
            volumes: ['./:/app']
            depends_on: [db]
          app-e2e:
            build: .
            depends_on: [db, mock]
          db:
            image: postgres:16
          mock:
            image: wiremock/wiremock
          e2e-runner:
            image: mcr.microsoft.com/playwright
        YAML, false, null, 'github.com/acme/app');

        $this->assertSame(['db'], array_keys($result['services']));
        $this->assertContains('mock', $result['dropped_test_services']);
    }
}
