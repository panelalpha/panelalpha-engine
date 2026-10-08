<?php

namespace Tests\Feature;

use App\Integrations\Monitoring\PanelAlphaMonitoring;
use App\Lib\Deploy\Telemetry\Telemetry;
use Tests\TestCase;

/**
 * Where a batch of reports is POSTed, and who owns that answer.
 *
 * The route lives on {@see Telemetry} and the config file carries the
 * constant, rather than the literal being written out in both places. That
 * arrangement is the point of these tests: two copies of a default read as
 * belt and braces and are not, because a fallback only applies when the
 * config key is missing — which is exactly the moment nobody is watching for
 * the two to disagree.
 */
class TelemetryEndpointTest extends TestCase
{
    /**
     * The one that would have caught the bug that shipped: the engine posted
     * at `/v1/reports` for as long as that default existed, and nothing has
     * ever served it. The ingest is the shared event route.
     */
    public function test_the_class_names_the_route_the_ingest_serves(): void
    {
        $this->assertSame('/api/v1/events', Telemetry::EVENTS_PATH);
    }

    /**
     * Read from the file rather than the container: the container holds
     * whatever a test or an install has since overridden, and the shipped
     * default is the thing nobody looks at.
     */
    public function test_the_config_default_is_the_class_constant_and_not_a_copy_of_it(): void
    {
        $shipped = require config_path('telemetry.php');

        $this->assertSame(Telemetry::EVENTS_PATH, $shipped['reports_path']);
    }

    public function test_the_default_endpoint_is_the_monitoring_host_plus_that_route(): void
    {
        config(['monitoring.url' => 'https://monitoring.panelalpha.com', 'telemetry.reports_path' => Telemetry::EVENTS_PATH]);

        $this->assertSame('https://monitoring.panelalpha.com/api/v1/events', Telemetry::endpoint());
    }

    /**
     * Telemetry hangs off monitoring, never off Connect.
     *
     * These are two services deployed apart: Connect is an integration the
     * engine calls (WithoutDNS, licensing), monitoring is where it reports.
     * They were one variable for a release, and every report went to Connect,
     * which does not serve the ingest and answers 405 — so the thing worth
     * asserting is that moving Connect moves nothing here.
     */
    public function test_the_route_follows_monitoring_and_not_connect(): void
    {
        config([
            'monitoring.url' => 'http://monitoring.example.test',
            'connect.url' => 'https://connect.example.test',
        ]);

        $this->assertSame('http://monitoring.example.test/api/v1/events', Telemetry::endpoint());
    }

    /** PANELALPHA_MONITORING moves the ingest; that is why it is one variable. */
    public function test_monitoring_can_be_pointed_somewhere_else(): void
    {
        config(['monitoring.url' => 'http://monitoring.example.test']);

        $this->assertSame('http://monitoring.example.test/api/v1/events', Telemetry::endpoint());
    }

    public function test_the_route_can_be_overridden_on_its_own(): void
    {
        config(['monitoring.url' => 'https://monitoring.panelalpha.com', 'telemetry.reports_path' => '/custom/ingest']);

        $this->assertSame('https://monitoring.panelalpha.com/custom/ingest', Telemetry::endpoint());
    }

    /**
     * An emptied override means "nobody set one", never "post at the host
     * root". monitoring.panelalpha.com/ answers 302 to a UI, so a blank path
     * would POST telemetry at a web page and read the redirect as a refusal.
     */
    public function test_an_emptied_route_falls_back_to_the_class_rather_than_the_host_root(): void
    {
        config(['monitoring.url' => 'https://monitoring.panelalpha.com', 'telemetry.reports_path' => '']);

        $this->assertSame('https://monitoring.panelalpha.com/api/v1/events', Telemetry::endpoint());
        $this->assertSame(Telemetry::EVENTS_PATH, Telemetry::eventsPath());
    }

    /**
     * Emptying the monitoring host is a supported answer meaning "report
     * nowhere", and has to stay one: the shipper checks for the empty string
     * and stops before opening a socket.
     */
    public function test_an_install_with_no_monitoring_host_reports_nowhere(): void
    {
        config(['monitoring.url' => '']);

        $this->assertSame('', Telemetry::endpoint());
        $this->assertSame('', PanelAlphaMonitoring::url(Telemetry::EVENTS_PATH));
    }

    /** A host written with a trailing slash must not produce a doubled one. */
    public function test_a_trailing_slash_on_the_host_is_not_doubled(): void
    {
        config(['monitoring.url' => 'https://monitoring.panelalpha.com/', 'telemetry.reports_path' => Telemetry::EVENTS_PATH]);

        $this->assertSame('https://monitoring.panelalpha.com/api/v1/events', Telemetry::endpoint());
    }
}
