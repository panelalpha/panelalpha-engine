<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\PortsReport;

/**
 * Which port the engine would proxy, and where that number came from.
 *
 * The sources disagree often enough that reporting one number would be a
 * guess. What is asserted here is the precedence — platform, then compose,
 * then the Dockerfile's EXPOSE — and that the losing answers are still
 * reported, so a disagreement is visible rather than silently resolved.
 */
class PortsReportTest extends ReportTestCase
{
    public function test_the_platform_hint_outranks_every_file(): void
    {
        $this->write('docker-compose.yml', "services:\n  web:\n    image: app\n    ports:\n      - \"3000:3000\"\n");
        $this->write('Dockerfile', "FROM node\nEXPOSE 8080\n");

        $report = PortsReport::of($this->tmpDir, ['port_hint' => 4000]);

        $this->assertSame(4000, $report['primary']);
        $this->assertSame('platform', $report['source']);
        // The others are still shown, so the disagreement is visible.
        $this->assertSame([3000], $report['compose']);
        $this->assertSame(8080, $report['dockerfile_expose']);
    }

    public function test_compose_wins_when_no_platform_claimed_a_port(): void
    {
        $this->write('docker-compose.yml', "services:\n  web:\n    image: app\n    ports:\n      - \"3000:3000\"\n");
        $this->write('Dockerfile', "FROM node\nEXPOSE 8080\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame(3000, $report['primary']);
        $this->assertSame('compose', $report['source']);
    }

    public function test_the_dockerfile_expose_is_the_last_resort(): void
    {
        $this->write('Dockerfile', "FROM node\nEXPOSE 8080\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame(8080, $report['primary']);
        $this->assertSame('dockerfile', $report['source']);
        $this->assertSame([], $report['compose']);
    }

    public function test_a_project_that_names_no_port_anywhere_reports_none(): void
    {
        $report = PortsReport::of($this->tmpDir, []);

        $this->assertNull($report['primary']);
        $this->assertNull($report['source']);
        $this->assertSame([], $report['compose']);
        $this->assertNull($report['dockerfile_expose']);
    }

    public function test_it_finds_the_compose_file_the_decision_did_not_name(): void
    {
        $this->write('compose.yaml', "services:\n  web:\n    image: app\n    ports:\n      - \"5000:5000\"\n");

        $this->assertSame(5000, PortsReport::of($this->tmpDir, [])['primary']);
    }

    public function test_a_compose_path_that_no_longer_exists_falls_back_to_the_directory(): void
    {
        $this->write('docker-compose.yml', "services:\n  web:\n    image: app\n    ports:\n      - \"3000:3000\"\n");

        $report = PortsReport::of($this->tmpDir, ['compose_path' => $this->tmpDir . '/gone.yml']);

        $this->assertSame([3000], $report['compose']);
    }

    /**
     * A datastore's published port is not the application's. Reporting 5432
     * as the primary would point the proxy at Postgres and leave the site
     * unreachable.
     */
    public function test_a_datastore_port_is_not_offered_as_the_applications(): void
    {
        $this->write('docker-compose.yml', <<<YAML
        services:
          web:
            image: app
            ports:
              - "8000:8000"
          db:
            image: postgres:16
            ports:
              - "5432:5432"
        YAML);

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame(8000, $report['primary']);
        $this->assertNotContains(5432, $report['compose']);
    }

    public function test_it_reads_the_dockerfile_the_decision_named(): void
    {
        $this->write('Dockerfile.web', "FROM node\nEXPOSE 7000\n");

        $report = PortsReport::of($this->tmpDir, ['dockerfile' => 'Dockerfile.web']);

        $this->assertSame(7000, $report['dockerfile_expose']);
        $this->assertSame('dockerfile', $report['source']);
    }

    public function test_a_trailing_slash_on_the_project_dir_changes_nothing(): void
    {
        $this->write('Dockerfile', "FROM node\nEXPOSE 8080\n");

        $this->assertSame(8080, PortsReport::of($this->tmpDir . '/', [])['dockerfile_expose']);
    }

    /**
     * Fusion ships a packaging Dockerfile that copies a binary CI built, and
     * EXPOSEs 8080. Detection refuses to build it, so its EXPOSE is not a
     * port this project publishes and must not be reported as one.
     */
    public function test_an_unbuildable_dockerfile_contributes_no_exposed_port(): void
    {
        $this->write('Dockerfile', "FROM alpine\nEXPOSE 8080\nCOPY build/fusion-\${TARGETOS} ./fusion\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertNull($report['dockerfile_expose']);
        $this->assertNull($report['primary']);
        $this->assertNull($report['source']);
    }

    /** The same file, with the binary it copies present, is fine. */
    public function test_a_buildable_dockerfile_still_contributes_its_exposed_port(): void
    {
        mkdir($this->tmpDir . '/build');
        touch($this->tmpDir . '/build/fusion-linux');
        $this->write('Dockerfile', "FROM alpine\nEXPOSE 8080\nCOPY build/fusion-\${TARGETOS} ./fusion\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame(8080, $report['dockerfile_expose']);
        $this->assertSame('dockerfile', $report['source']);
    }

    /**
     * The engine proxies one port. Everything else the compose file publishes
     * is reachable only through a proxy rule the caller has to ask for, so the
     * report says so rather than listing every port as if they were equal.
     */
    public function test_only_the_primary_is_routed_and_the_rest_say_how(): void
    {
        $this->write('docker-compose.yml', "services:\n  web:\n    image: app\n    ports:\n      - \"8080:8080\"\n      - \"9001:9001\"\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame([8080], $report['routed']);
        $this->assertSame(9001, $report['unrouted'][0]['port']);
        $this->assertSame('secondary', $report['unrouted'][0]['reason']);
        $this->assertTrue($report['unrouted'][0]['routable']);
        $this->assertStringContainsString('proxy_rule_create', $report['unrouted'][0]['hint']);
        // The flat list callers already read is unchanged.
        $this->assertSame([8080, 9001], $report['compose']);
    }

    /**
     * A datastore port is refused, not merely absent — and never offered as
     * something a proxy rule could fix.
     */
    public function test_a_datastore_port_is_reported_as_refused(): void
    {
        $this->write('docker-compose.yml', "services:\n  web:\n    image: app\n    ports:\n      - \"8080:8080\"\n  db:\n    image: postgres:16\n    ports:\n      - \"5432:5432\"\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame([8080], $report['routed']);
        $this->assertSame([], $report['unrouted']);
        $this->assertSame(
            [['port' => 5432, 'reason' => 'datastore', 'service' => 'PostgreSQL', 'routable' => false]],
            $report['refused']
        );
    }

    /**
     * Detection keeps a non-web port in the list — it is a real published port
     * — but nothing should ever be routed to it, so it is flagged rather than
     * offered like an ordinary second web port.
     */
    public function test_a_non_web_port_is_unrouted_and_not_routable(): void
    {
        $this->write('docker-compose.yml', "services:\n  git:\n    image: gitea\n    ports:\n      - \"8080:8080\"\n      - \"22:22\"\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertSame([8080], $report['routed']);
        $this->assertSame(22, $report['unrouted'][0]['port']);
        $this->assertSame('non_web', $report['unrouted'][0]['reason']);
        $this->assertSame('SSH', $report['unrouted'][0]['service']);
        $this->assertFalse($report['unrouted'][0]['routable']);
        $this->assertArrayNotHasKey('hint', $report['unrouted'][0]);
    }

    /** Nothing detected means nothing routed, rather than an empty primary. */
    public function test_nothing_is_routed_when_no_port_was_found(): void
    {
        $this->write('docker-compose.yml', "services:\n  app:\n    image: app\n");

        $report = PortsReport::of($this->tmpDir, []);

        $this->assertNull($report['primary']);
        $this->assertSame([], $report['routed']);
        $this->assertSame([], $report['unrouted']);
        $this->assertSame([], $report['refused']);
    }
}
