<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\RuntimeSidecars;
use PHPUnit\Framework\TestCase;

/**
 * A slice of a stack (newznab-tmux's `docker-compose.monitoring.yml`, Atlas's `docker-compose.https.yml`)
 * names services the main file defines; kept as backing services, they failed the deploy with
 * `service "mysqld-exporter" depends on undefined service "mariadb": invalid compose project`.
 */
class RuntimeSidecarsUndefinedDependencyTest extends TestCase
{
    public function test_a_dependency_the_file_never_defines_is_dropped(): void
    {
        $found = RuntimeSidecars::fromYaml(<<<'YAML'
            services:
              pushgateway:
                image: 'prom/pushgateway:v1.11.3'
              mysqld-exporter:
                image: 'prom/mysqld-exporter:v0.20.0'
                command: ['--mysqld.address=mariadb:3306']
                depends_on:
                  - mariadb
              redis-exporter:
                image: 'oliver006/redis_exporter:v1.93.0'
                environment:
                  REDIS_ADDR: 'redis://redis:6379'
                depends_on:
                  - redis
            YAML, true);

        $this->assertSame(['pushgateway', 'mysqld-exporter', 'redis-exporter'], array_keys($found['services']));
        $this->assertArrayNotHasKey('depends_on', $found['services']['mysqld-exporter']);
        $this->assertArrayNotHasKey('depends_on', $found['services']['redis-exporter']);
    }

    public function test_a_kept_sidecar_moved_off_the_name_app_keeps_its_dependants(): void
    {
        $found = RuntimeSidecars::fromYaml(<<<'YAML'
            services:
              app:
                image: postgres:16
              worker:
                image: redis:7
                depends_on:
                  - app
            YAML, true);

        $this->assertSame(['app-sidecar'], $found['services']['worker']['depends_on'] ?? null);
    }
}
