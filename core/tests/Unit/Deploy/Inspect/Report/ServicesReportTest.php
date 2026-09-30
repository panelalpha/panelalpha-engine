<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\ServicesReport;

/**
 * The backing services an application needs — the ones its compose file
 * declares, and the ones only a DATABASE_URL admits to.
 *
 * The second half is what catches people out: a framework template ships no
 * compose at all and points DATABASE_URL at localhost, so deployed as written
 * it starts cleanly and 500s on the first request.
 */
class ServicesReportTest extends ReportTestCase
{
    /**
     * @param list<array<string, mixed>> $services
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $services): array
    {
        $keyed = [];
        foreach ($services as $service) {
            $keyed[(string) $service['name']] = $service;
        }

        return $keyed;
    }

    public function test_it_separates_the_application_from_its_datastores(): void
    {
        $this->write('docker-compose.yml', <<<YAML
        services:
          web:
            image: myorg/app:1.2
            ports:
              - "8000:8000"
          db:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $services = $this->byName(ServicesReport::of($this->tmpDir));

        $this->assertSame('application', $services['web']['role']);
        $this->assertNull($services['web']['engine']);
        $this->assertSame('datastore', $services['db']['role']);
        $this->assertSame('postgres', $services['db']['engine']);
        $this->assertSame('postgres:16', $services['db']['image']);
        $this->assertSame([5432], $services['db']['ports']);
        $this->assertSame('compose', $services['db']['origin']);
    }

    /**
     * The case the section exists for: nothing in the repository declares a
     * database, and the deploy would still have to supply one.
     */
    public function test_a_datastore_only_an_env_file_admits_to_is_reported(): void
    {
        $this->write('.env.example', "DATABASE_URL=postgres://user:pass@localhost:5432/app\n");

        $services = $this->byName(ServicesReport::of($this->tmpDir));

        $this->assertArrayHasKey('db', $services);
        $this->assertSame('env', $services['db']['origin']);
        $this->assertSame('datastore', $services['db']['role']);
        $this->assertSame('postgres', $services['db']['engine']);
    }

    /**
     * A URL pointing somewhere else is a managed database the deploy must not
     * duplicate — only a local hostname means "I expected a companion".
     */
    public function test_a_managed_database_elsewhere_is_not_reported_as_a_service(): void
    {
        $this->write('.env.example', "DATABASE_URL=postgres://user:pass@db.example.com:5432/app\n");

        $this->assertSame([], ServicesReport::of($this->tmpDir));
    }

    /**
     * Both halves can name the same service. Compose is what the project
     * actually ships, so it must win — reporting the stock image the engine
     * would have supplied instead would misdescribe the deploy.
     */
    public function test_a_service_in_both_halves_is_reported_once_as_the_compose_one(): void
    {
        $this->write('.env.example', "DATABASE_URL=postgres://user:pass@localhost:5432/app\n");
        $this->write('docker-compose.yml', <<<YAML
        services:
          db:
            image: postgres:15-alpine
        YAML);

        $services = ServicesReport::of($this->tmpDir);
        $keyed = $this->byName($services);

        $this->assertCount(1, $services);
        $this->assertSame('compose', $keyed['db']['origin']);
        $this->assertSame('postgres:15-alpine', $keyed['db']['image']);
    }

    /**
     * A recipe's compose file is the one the deploy runs, under a name
     * the repository never uses: its datastores are the ones to report.
     */
    public function test_the_compose_file_detection_chose_is_the_one_read(): void
    {
        $this->write('docker-compose.panelalpha.app-config.yml', <<<YAML
        services:
          db:
            image: mysql:8.0
          wordpress:
            image: wordpress:latest
        YAML);

        $this->assertSame([], ServicesReport::of($this->tmpDir));

        $services = $this->byName(
            ServicesReport::of($this->tmpDir, $this->tmpDir . '/docker-compose.panelalpha.app-config.yml')
        );

        $this->assertSame('mysql', $services['db']['engine']);
        $this->assertSame('application', $services['wordpress']['role']);
    }

    public function test_a_project_declaring_nothing_reports_nothing(): void
    {
        $this->assertSame([], ServicesReport::of($this->tmpDir));
    }

    public function test_an_unparseable_compose_file_is_reported_as_no_services(): void
    {
        // A tab where YAML demands spaces: the parser throws, and a report
        // that let that escape would fail the whole inspection over one file.
        $this->write('docker-compose.yml', "services:\n  web:\n\timage: app\n");

        $this->assertSame([], ServicesReport::of($this->tmpDir));
    }

    public function test_the_report_is_a_list_the_api_can_encode_as_an_array(): void
    {
        $this->write('docker-compose.yml', <<<YAML
        services:
          web:
            image: myorg/app
          db:
            image: postgres:16
        YAML);

        $services = ServicesReport::of($this->tmpDir);

        $this->assertSame([0, 1], array_keys($services));
        $this->assertStringStartsWith('[', (string) json_encode($services));
    }
}
