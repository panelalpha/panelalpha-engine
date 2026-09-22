<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\DetectAppPort;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DetectAppPort.
 *
 * Covers the public API (detectPrimaryPort / detectAllPorts) and the
 * private heuristics indirectly via end-to-end YAML fixtures. The class
 * has no Laravel dependencies, so this test extends the plain PHPUnit
 * TestCase — no app boot required.
 *
 * Each test writes a small YAML document to a temp file in setUp()
 * and unlinks it in tearDown(), so the suite is hermetic and parallel-safe.
 */
class DetectAppPortTest extends TestCase
{
    private string $composePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->composePath = tempnam(sys_get_temp_dir(), 'detect-app-port-') . '.yml';
    }

    protected function tearDown(): void
    {
        if ($this->composePath !== '' && file_exists($this->composePath)) {
            unlink($this->composePath);
        }
        parent::tearDown();
    }

    private function writeCompose(string $yaml): void
    {
        file_put_contents($this->composePath, $yaml);
    }

    // ---- basic shapes ----------------------------------------------------

    public function test_detects_single_host_port_string(): void
    {
        $this->writeCompose(<<<YAML
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8080, $result['primary']);
        $this->assertSame([8080], $result['all']);
    }

    public function test_detects_simple_int_port(): void
    {
        $this->writeCompose(<<<YAML
services:
  web:
    image: nginx:alpine
    ports:
      - 8080
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8080, $result['primary']);
        $this->assertSame([8080], $result['all']);
    }

    public function test_skips_loopback_bindings(): void
    {
        $this->writeCompose(<<<YAML
services:
  web:
    image: nginx:alpine
    ports:
      - "127.0.0.1:8080:80"
      - "0.0.0.0:9090:90"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(9090, $result['primary']);
        $this->assertSame([9090], $result['all']);
    }

    public function test_skips_localhost_loopback(): void
    {
        $this->writeCompose(<<<YAML
services:
  web:
    image: nginx:alpine
    ports:
      - "localhost:8080:80"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
        $this->assertArrayNotHasKey('primary', $result);
    }

    public function test_protocol_suffix_is_stripped(): void
    {
        $this->writeCompose(<<<YAML
services:
  web:
    image: nginx:alpine
    ports:
      - "8080:80/tcp"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8080, $result['primary']);
        $this->assertSame([8080], $result['all']);
    }

    // ---- env-var default resolution -------------------------------------

    public function test_resolves_env_var_default_with_colon(): void
    {
        // The exact failure mode from the bug report: "${APP_PORT:-8090}:8000"
        $this->writeCompose(<<<YAML
services:
  app:
    build: .
    ports:
      - "\${APP_PORT:-8090}:8000"
  db:
    image: postgres:16
    ports:
      - "5434:5432"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8090, $result['primary']);
        $this->assertSame([8090], $result['all']);
    }

    public function test_resolves_env_var_default_bare_dash(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "\${APP_PORT-9000}:9000"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(9000, $result['primary']);
        $this->assertSame([9000], $result['all']);
    }

    public function test_resolves_env_var_default_with_complex_container_port(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "\${HOST_PORT:-7000}:7000"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(7000, $result['primary']);
    }

    // ---- internal-port filtering -----------------------------------------

    public function test_filters_postgres_by_container_port(): void
    {
        $this->writeCompose(<<<YAML
services:
  db:
    image: postgres:16
    ports:
      - "5434:5432"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
        $this->assertArrayNotHasKey('primary', $result);
    }

    public function test_filters_mysql_with_default_port(): void
    {
        $this->writeCompose(<<<YAML
services:
  db:
    image: mysql:8
    ports:
      - "3306:3306"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_filters_redis_by_image_name_with_custom_host_port(): void
    {
        // Container port is the default 6379 (in INTERNAL_SERVICE_PORTS),
        // host port is custom 6380.
        $this->writeCompose(<<<YAML
services:
  redis:
    image: redis:7
    ports:
      - "6380:6379"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_filters_postgres_by_image_name_with_custom_container_port(): void
    {
        // Custom container port 5433 (not in INTERNAL_SERVICE_PORTS), so the
        // container-port check alone wouldn't catch it — the image-name
        // fallback must.
        $this->writeCompose(<<<YAML
services:
  db:
    image: postgres:16
    ports:
      - "5434:5433"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_filters_mariadb_by_image_basename_with_registry(): void
    {
        // Custom registry prefix should not prevent image matching.
        $this->writeCompose(<<<YAML
services:
  db:
    image: mycorp/mariadb:10
    ports:
      - "3307:3306"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_filters_memcached_by_image_name(): void
    {
        $this->writeCompose(<<<YAML
services:
  cache:
    image: memcached:1.6
    ports:
      - "11212:11211"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_keeps_app_port_alongside_postgres(): void
    {
        // The exact scenario from the bug report.
        $this->writeCompose(<<<YAML
services:
  app:
    build: .
    container_name: contact-monitor_app
    command: ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
    ports:
      - "\${APP_PORT:-8090}:8000"
    depends_on:
      - db
  db:
    image: postgres:16
    container_name: contact-monitor_db
    ports:
      - "5434:5432"
volumes:
  contact-monitor_pgdata:
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8090, $result['primary']);
        $this->assertSame([8090], $result['all']);
    }

    public function test_keeps_non_internal_service_with_custom_port(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "7000:7000"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(7000, $result['primary']);
        $this->assertSame([7000], $result['all']);
    }

    /**
     * Port 7000 used to be in INTERNAL_SERVICE_PORTS (Cassandra). The list
     * is intentionally restricted to ports that don't collide with popular
     * web/app port ranges, so this regression test locks in the new
     * behaviour: a non-DB image binding on 7000 must be kept.
     */
    public function test_keeps_port_7000_when_image_is_not_a_database(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "7000:7000"
      - "7001:7001"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([7000, 7001], $result['all']);
    }

    /**
     * Port 8086 used to be in INTERNAL_SERVICE_PORTS (InfluxDB). Same
     * rationale as the 7000/7001 case: 8086 is a popular web/admin port
     * and must not be filtered out for non-DB services.
     */
    public function test_keeps_port_8086_when_image_is_not_a_database(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "8086:8086"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([8086], $result['all']);
    }

    public function test_filters_cassandra_by_image_name_with_port_7000(): void
    {
        // The image-name fallback must still catch Cassandra even though
        // port 7000 is no longer in INTERNAL_SERVICE_PORTS.
        $this->writeCompose(<<<YAML
services:
  cassandra:
    image: cassandra:4
    ports:
      - "7000:7000"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    public function test_filters_influxdb_by_image_name_with_port_8086(): void
    {
        $this->writeCompose(<<<YAML
services:
  influx:
    image: influxdb:2
    ports:
      - "8086:8086"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    // ---- expose section ---------------------------------------------------

    public function test_expose_section_is_parsed(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    expose:
      - 8080
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8080, $result['primary']);
        $this->assertSame([8080], $result['all']);
    }

    public function test_expose_filters_internal_ports(): void
    {
        $this->writeCompose(<<<YAML
services:
  cache:
    image: myorg/app
    expose:
      - 6379
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([], $result['all']);
    }

    // ---- error / edge cases ----------------------------------------------

    public function test_handles_missing_compose_file(): void
    {
        $missing = sys_get_temp_dir() . '/nonexistent-' . uniqid('', true) . '.yml';
        $this->assertFileDoesNotExist($missing);
        $result = DetectAppPort::detectAllPorts($missing);
        $this->assertSame(['all' => [], 'refused' => []], $result);
        $this->assertSame(8080, DetectAppPort::detectPrimaryPort($missing));
    }

    public function test_handles_invalid_yaml(): void
    {
        file_put_contents($this->composePath, "not: valid: yaml: at: all: :::");
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(['all' => [], 'refused' => []], $result);
    }

    public function test_returns_empty_when_no_services(): void
    {
        $this->writeCompose(<<<YAML
services: {}
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(['all' => [], 'refused' => []], $result);
    }

    public function test_returns_empty_when_no_ports(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(['all' => [], 'refused' => []], $result);
    }

    // ---- multiple services / sort order ----------------------------------

    public function test_prefers_preferred_port_when_multiple_detected(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "9000:9000"
      - "8080:8080"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame(8080, $result['primary']);
        $this->assertSame([8080, 9000], $result['all']);
    }

    public function test_multiple_services_collect_all_public_ports(): void
    {
        $this->writeCompose(<<<YAML
services:
  app:
    image: myorg/webapp
    ports:
      - "7000:7000"
  admin:
    image: myorg/admin
    ports:
      - "7001:7001"
YAML);
        $result = DetectAppPort::detectAllPorts($this->composePath);
        $this->assertSame([7000, 7001], $result['all']);
        $this->assertSame(7000, $result['primary']);
    }
}
