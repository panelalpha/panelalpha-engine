<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;

/**
 * The port the proxy is pointed at, read out of the repository's own compose
 * file.
 *
 * Getting this wrong is the difference between a working site and a
 * connection reset, and the failure is invisible from the deploy log: every
 * container is up and healthy, the proxy is just talking to the wrong one.
 */
class ComposePortScanTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pa-ports-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function compose(string $yaml): string
    {
        $path = $this->dir . '/compose.yaml';
        file_put_contents($path, $yaml);

        return $path;
    }

    public function test_a_single_published_port_is_the_primary(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
        YAML);

        $this->assertSame(['all' => [8080], 'primary' => 8080, 'refused' => []], ComposePortScan::of($path));
    }

    public function test_the_preferred_port_wins_over_a_lower_number(): void
    {
        // 3000 is numerically smaller, but 80 is what a browser will ask for.
        $path = $this->compose(<<<'YAML'
        services:
          web:
            image: nginx
            ports:
              - "80:80"
          api:
            image: acme/api
            ports:
              - "3000:3000"
        YAML);

        $this->assertSame([80, 3000], ComposePortScan::of($path)['all']);
    }

    public function test_unpreferred_ports_sort_numerically_after_preferred_ones(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "9999:9999"
              - "4200:4200"
              - "8000:8000"
        YAML);

        $this->assertSame([8000, 4200, 9999], ComposePortScan::of($path)['all']);
    }

    public function test_a_database_port_is_not_offered(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
          db:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([8080], $scan['all']);
        // Not offered, but not silently dropped either: a caller asking why
        // 5432 is missing gets an answer instead of an absence.
        $this->assertSame(
            [['port' => 5432, 'reason' => 'datastore', 'service' => 'PostgreSQL']],
            $scan['refused']
        );
    }

    public function test_a_remapped_database_is_still_not_offered(): void
    {
        // Host 5434 looks like an ordinary port. The container side and the
        // image both say otherwise.
        $path = $this->compose(<<<'YAML'
        services:
          db:
            image: postgres:16
            ports:
              - "5434:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([], $scan['all']);
        $this->assertSame(
            [['port' => 5434, 'reason' => 'datastore', 'service' => 'PostgreSQL']],
            $scan['refused']
        );
    }

    public function test_expose_is_read_when_nothing_is_published(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            expose:
              - "3000"
        YAML);

        $this->assertSame(3000, ComposePortScan::primaryOf($path));
    }

    public function test_a_loopback_binding_offers_nothing(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "127.0.0.1:8080:8080"
        YAML);

        // A loopback binding is not a mapping at all, so there is nothing to
        // report as refused either.
        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($path));
    }

    public function test_an_env_var_default_is_honoured(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "${APP_PORT:-8090}:8000"
        YAML);

        $this->assertSame(8090, ComposePortScan::primaryOf($path));
    }

    public function test_duplicate_ports_across_services_appear_once(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          blue:
            image: acme/app
            ports:
              - "8080:8080"
          green:
            image: acme/app
            ports:
              - "8080:8080"
        YAML);

        $this->assertSame([8080], ComposePortScan::of($path)['all']);
    }

    public function test_a_stack_that_publishes_nothing_falls_back_to_the_default(): void
    {
        // Common for repos whose compose relies on an external proxy. 8080 is
        // what the engine's generated entrypoint listens on.
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
        YAML);

        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($path));
        $this->assertSame(8080, ComposePortScan::primaryOf($path));
    }

    public function test_a_missing_file_falls_back_to_the_default(): void
    {
        $this->assertSame(['all' => [], 'refused' => []], ComposePortScan::of($this->dir . '/nothing.yaml'));
        $this->assertSame(8080, ComposePortScan::primaryOf($this->dir . '/nothing.yaml'));
    }

    public function test_unparseable_yaml_falls_back_to_the_default(): void
    {
        // A broken compose file is the project's problem, but it must not be
        // an exception out of detection.
        $path = $this->compose("services:\n  app:\n   - ports: [\n");

        $this->assertSame(8080, ComposePortScan::primaryOf($path));
    }

    public function test_a_non_mapping_service_entry_is_ignored(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app: null
          web:
            image: nginx
            ports:
              - "80:80"
        YAML);

        $this->assertSame([80], ComposePortScan::of($path)['all']);
    }

    /**
     * A datastore remapped onto an ordinary-looking port is refused on the
     * image, so the reason names the image rather than a well-known port.
     */
    public function test_a_datastore_on_an_ordinary_port_is_refused_by_image(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          db:
            image: postgres:16
            ports:
              - "8081:8081"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([], $scan['all']);
        $this->assertSame(
            [['port' => 8081, 'reason' => 'datastore_image', 'service' => 'postgres:16']],
            $scan['refused']
        );
    }

    /** A port the app publishes is not refused because a datastore names it too. */
    public function test_a_port_another_service_publishes_is_not_reported_as_refused(): void
    {
        $path = $this->compose(<<<'YAML'
        services:
          app:
            image: acme/app
            ports:
              - "8080:8080"
          db:
            image: postgres:16
            ports:
              - "8080:5432"
        YAML);

        $scan = ComposePortScan::of($path);

        $this->assertSame([8080], $scan['all']);
        $this->assertSame([], $scan['refused']);
    }
}
