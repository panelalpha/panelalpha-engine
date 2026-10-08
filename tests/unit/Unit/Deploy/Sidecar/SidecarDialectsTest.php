<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\SidecarDialects;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of what the engine knows about other people's vocabularies.
 *
 * Everything else about a compose service is derived from the file and the
 * image. Three things cannot be - the URL scheme an ecosystem answers to, the
 * driver name a framework knows it by, and which ports no web application
 * serves on - so they live as data. These tests hold the reader of that data
 * to its contract, including the part operators depend on: an unlisted engine
 * still deploys, it just gets no connection URL.
 */
class SidecarDialectsTest extends TestCase
{
    protected function tearDown(): void
    {
        SidecarDialects::forget();
        parent::tearDown();
    }

    public function test_the_shipped_catalogue_loads(): void
    {
        $this->assertTrue(SidecarDialects::has('postgres'));
        $this->assertTrue(SidecarDialects::has('mysql'));
        $this->assertTrue(SidecarDialects::has('redis'));
    }

    public function test_an_unlisted_engine_is_not_in_the_catalogue(): void
    {
        $this->assertFalse(SidecarDialects::has('acme-store'));
    }

    public function test_aliases_resolve_to_one_canonical_engine(): void
    {
        // mariadb and mysql have to be configured alike, or the same stack
        // gets two different sets of init variables depending on the image tag.
        $this->assertSame('mysql', SidecarDialects::canonical('mariadb'));
        $this->assertSame('postgres', SidecarDialects::canonical('postgresql'));
        $this->assertSame('postgres', SidecarDialects::canonical('pgvector'));
        $this->assertSame('cassandra', SidecarDialects::canonical('scylladb'));
    }

    public function test_a_canonical_name_resolves_to_itself(): void
    {
        $this->assertSame('mysql', SidecarDialects::canonical('MySQL'));
        $this->assertSame('mysql', SidecarDialects::canonical('  mysql '));
    }

    public function test_an_unknown_name_is_returned_unchanged(): void
    {
        // Not null: an unlisted engine still deploys under its own name.
        $this->assertSame('acme-store', SidecarDialects::canonical('acme-store'));
    }

    public function test_an_empty_name_resolves_to_nothing(): void
    {
        $this->assertNull(SidecarDialects::canonical(''));
        $this->assertNull(SidecarDialects::canonical('   '));
    }

    public function test_a_dialect_carries_what_a_framework_needs(): void
    {
        $dialect = SidecarDialects::dialect('mariadb');

        $this->assertSame('mysql', $dialect['engine']);
        $this->assertSame(3306, $dialect['port']);
        $this->assertSame('mysql', $dialect['scheme']);
        $this->assertSame('mysql', $dialect['driver']);
    }

    public function test_an_observed_port_beats_the_catalogues_default(): void
    {
        // Whatever the running image declares wins: the default exists only
        // for when the file and the image both say nothing.
        $this->assertSame(15432, SidecarDialects::dialect('postgres', 15432)['port']);
    }

    public function test_an_unlisted_engine_gets_a_dialect_with_nothing_filled_in(): void
    {
        $dialect = SidecarDialects::dialect('acme-store');

        $this->assertSame('acme-store', $dialect['engine']);
        $this->assertSame(0, $dialect['port']);
        $this->assertNull($dialect['scheme']);
        $this->assertNull($dialect['driver']);
        $this->assertSame([], $dialect['env']);
    }

    public function test_default_ports_are_reported(): void
    {
        $this->assertSame(5432, SidecarDialects::portFor('postgres'));
        $this->assertSame(6379, SidecarDialects::portFor('redis'));
        $this->assertSame(0, SidecarDialects::portFor('acme-store'));
    }

    public function test_init_variables_are_listed_per_engine(): void
    {
        // These are the variables whose presence identifies the engine when
        // the image name is unhelpful.
        $this->assertContains('POSTGRES_PASSWORD', SidecarDialects::initVariablesFor('postgres'));
        $this->assertContains('MYSQL_ROOT_PASSWORD', SidecarDialects::initVariablesFor('mariadb'));
        $this->assertSame([], SidecarDialects::initVariablesFor('acme-store'));
    }

    public function test_memory_limits_are_read_from_the_catalogue(): void
    {
        $this->assertSame('512m', SidecarDialects::memoryLimitFor('postgres'));
        $this->assertNull(SidecarDialects::memoryLimitFor('acme-store'));
    }

    public function test_service_overrides_are_read_from_the_catalogue(): void
    {
        // MySQL refuses remote root connections unless told otherwise.
        $overrides = SidecarDialects::serviceOverridesFor('mysql');

        $this->assertSame('%', $overrides['environment']['MYSQL_ROOT_HOST']);
        $this->assertSame([], SidecarDialects::serviceOverridesFor('acme-store'));
    }

    public function test_only_ports_no_web_app_serves_on_are_unambiguous(): void
    {
        $ports = SidecarDialects::unambiguousPorts();

        $this->assertContains(5432, $ports);
        $this->assertContains(3306, $ports);
        $this->assertContains(6379, $ports);

        // InfluxDB's 8086 and MinIO's 9000 are in the catalogue but are
        // plausible application ports; treating them as datastore-only would
        // filter the app's own port and leave the site unreachable.
        $this->assertNotContains(8086, $ports);
        $this->assertNotContains(9000, $ports);
    }

    public function test_unambiguous_ports_are_listed_once(): void
    {
        $ports = SidecarDialects::unambiguousPorts();

        $this->assertSame(array_values(array_unique($ports)), $ports);
    }
}
