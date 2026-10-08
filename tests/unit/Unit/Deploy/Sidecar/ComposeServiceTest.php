<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\ComposeService;
use PHPUnit\Framework\TestCase;

/**
 * One compose service, read the way the rest of the engine asks about it.
 *
 * Everything downstream - is this a datastore, which port is the app's, what
 * credentials does it want - starts here, and compose accepts several
 * spellings of each answer. Note that ports() reports the *container* side:
 * what the service itself listens on, regardless of what the host publishes.
 */
class ComposeServiceTest extends TestCase
{
    public function test_the_image_is_read_and_trimmed(): void
    {
        $this->assertSame('postgres:16', ComposeService::of(['image' => '  postgres:16 '])->image());
    }

    public function test_a_service_with_no_image_reports_none(): void
    {
        $this->assertSame('', ComposeService::of(['build' => '.'])->image());
        $this->assertSame('', ComposeService::of([])->image());
    }

    public function test_the_image_family_drops_the_registry_and_the_tag(): void
    {
        // What identifies the engine is the repository name, not where it is
        // hosted or which tag this stack pinned.
        $this->assertSame('postgres', ComposeService::familyOf('postgres:16'));
        $this->assertSame('postgres', ComposeService::familyOf('docker.io/library/postgres:16-alpine'));
        $this->assertSame('postgres', ComposeService::familyOf('ghcr.io/acme/postgres'));
        $this->assertSame('mariadb', ComposeService::familyOf('MariaDB:11'));
    }

    public function test_a_digest_pinned_image_still_names_its_family(): void
    {
        $this->assertSame(
            'postgres',
            ComposeService::familyOf('postgres@sha256:0000000000000000000000000000000000000000000000000000000000000000')
        );
    }

    public function test_an_empty_image_has_no_family(): void
    {
        $this->assertSame('', ComposeService::of([])->imageFamily());
    }

    public function test_a_published_port_reports_the_container_side(): void
    {
        // The host side is an operator's choice; the container side is what
        // the service actually listens on, and the only one worth matching
        // against a known datastore port.
        $this->assertSame([5432], ComposeService::of(['ports' => ['15432:5432']])->ports());
    }

    public function test_every_short_form_is_read(): void
    {
        $service = ComposeService::of(['ports' => ['5432', '15432:5432/tcp', '127.0.0.1:6379:6379']]);

        $this->assertSame([5432, 6379], $service->ports());
    }

    public function test_the_long_form_is_read(): void
    {
        $service = ComposeService::of([
            'ports' => [['target' => 5432, 'published' => 15432, 'protocol' => 'tcp']],
        ]);

        $this->assertSame([5432], $service->ports());
    }

    public function test_exposed_ports_are_read_too(): void
    {
        $this->assertSame([3306], ComposeService::of(['expose' => [3306]])->ports());
    }

    public function test_observed_ports_lead_the_list(): void
    {
        // What the running container reports beats what the file guessed.
        $service = ComposeService::of(['ports' => ['8080:8080']]);

        $this->assertSame([5432, 8080], $service->ports([5432]));
    }

    public function test_a_service_declaring_no_ports_reports_none(): void
    {
        $this->assertSame([], ComposeService::of(['image' => 'postgres:16'])->ports());
    }

    public function test_an_unresolved_variable_is_not_a_port(): void
    {
        // `${DB_PORT}` with no .env beside the file. Better no port than 0.
        $this->assertSame([], ComposeService::of(['ports' => ['${DB_PORT}']])->ports());
    }

    public function test_environment_keys_are_read_from_both_spellings(): void
    {
        // Compose takes a map or a list of KEY=value strings, and repos use
        // both. The keys identify the engine when the image name does not.
        $map = ComposeService::of(['environment' => ['POSTGRES_PASSWORD' => 'x', 'postgres_db' => 'app']]);
        $list = ComposeService::of(['environment' => ['POSTGRES_PASSWORD=x', 'POSTGRES_DB=app']]);

        $this->assertSame(['POSTGRES_PASSWORD', 'POSTGRES_DB'], $map->environmentKeys());
        $this->assertSame(['POSTGRES_PASSWORD', 'POSTGRES_DB'], $list->environmentKeys());
    }

    public function test_a_list_entry_with_no_value_is_still_a_key(): void
    {
        // `- POSTGRES_PASSWORD` passes the host's variable through.
        $this->assertSame(
            ['POSTGRES_PASSWORD'],
            ComposeService::of(['environment' => ['POSTGRES_PASSWORD']])->environmentKeys()
        );
    }

    public function test_a_service_with_no_environment_reports_no_keys(): void
    {
        $this->assertSame([], ComposeService::of(['image' => 'redis:7'])->environmentKeys());
        $this->assertSame([], ComposeService::of(['environment' => 'not-a-map'])->environmentKeys());
    }

    public function test_dependencies_are_read_from_both_spellings(): void
    {
        $list = ComposeService::of(['depends_on' => ['db', 'Redis']]);
        $map = ComposeService::of(['depends_on' => ['db' => ['condition' => 'service_healthy']]]);

        $this->assertSame(['db', 'redis'], $list->dependencyNames());
        $this->assertSame(['db'], $map->dependencyNames());
    }

    public function test_links_are_dependencies_too(): void
    {
        $service = ComposeService::of(['depends_on' => ['db'], 'links' => ['Jackett', 'cache:redis']]);

        $this->assertSame(['db', 'jackett', 'cache'], $service->dependencyNames());
    }

    public function test_a_service_with_no_dependencies_reports_none(): void
    {
        $this->assertSame([], ComposeService::of(['image' => 'redis:7'])->dependencyNames());
        $this->assertSame([], ComposeService::of(['depends_on' => 'db'])->dependencyNames());
    }
}
