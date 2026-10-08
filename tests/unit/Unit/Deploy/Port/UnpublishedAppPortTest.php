<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Compose\PrimaryPortBinding;
use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Port\UnpublishedAppPort;
use PHPUnit\Framework\TestCase;

class UnpublishedAppPortTest extends TestCase
{
    /** reiverr's docker-compose.yml: the published image, no port anywhere. */
    private const REIVERR = ['services' => ['reiverr-frontend' => [
        'container_name' => 'reiverr-dev',
        'image' => 'ghcr.io/aleksilassila/reiverr:latest',
        'volumes' => ['./backend/config:/usr/src/app/config'],
    ]]];

    private const REIVERR_PROD = "services:\n  reiverr-frontend:\n    container_name: reiverr-prod\n"
        . "    build:\n      context: .\n      target: production\n    ports:\n      - 9494:9494\n";

    private const REIVERR_OVERRIDE = "services:\n  reiverr-frontend:\n    build:\n      context: .\n      target: development\n"
        . "    ports:\n      - 5173:5173\n";

    public function test_the_port_another_compose_file_publishes_for_the_service_is_used(): void
    {
        $found = UnpublishedAppPort::apply(self::REIVERR, static fn (): array => [], [
            'docker-compose.override.yml' => self::REIVERR_OVERRIDE,
            'docker-compose.prod.yml' => self::REIVERR_PROD,
        ]);

        $this->assertNotNull($found);
        $this->assertSame(['reiverr-frontend', 9494, 'the port docker-compose.prod.yml publishes for it'], [$found['service'], $found['port'], $found['source']]);
        $this->assertSame(['9494'], $found['compose']['services']['reiverr-frontend']['expose']);
        // What the run file then gets: published, and the site's port.
        $bound = PrimaryPortBinding::apply($found['compose']);
        $this->assertSame(9494, $bound['published']);
        $this->assertSame(['9494'], array_map('strval', $bound['compose']['services']['reiverr-frontend']['expose']));
        $this->assertSame(['9494:9494'], $bound['compose']['services']['reiverr-frontend']['ports']);
        $this->assertSame(9494, ComposePortScan::ofParsed($bound['compose'])['primary']);
    }

    /** A development file's port is a dev server's (vite 5173), not the app's. */
    public function test_a_development_file_is_not_asked(): void
    {
        $this->assertNull(UnpublishedAppPort::apply(self::REIVERR, null, [
            'docker-compose.override.yml' => self::REIVERR_OVERRIDE,
            'docker-compose.dev.yml' => self::REIVERR_OVERRIDE,
        ]));
    }

    public function test_a_port_variable_comes_first(): void
    {
        $compose = ['services' => ['app' => ['image' => 'acme/app', 'environment' => ['NODE_ENV=production', 'PORT=9494']]]];
        $found = UnpublishedAppPort::apply($compose, static fn (): array => [3000]);

        $this->assertSame([9494, 'its PORT variable'], [$found['port'] ?? null, $found['source'] ?? null]);

        $map = ['services' => ['app' => ['image' => 'acme/app', 'environment' => ['HTTP_PORT' => 8123]]]];
        $this->assertSame(8123, UnpublishedAppPort::apply($map)['port'] ?? null);
    }

    public function test_the_image_expose_is_asked_when_nothing_else_names_the_port(): void
    {
        $asked = [];
        $compose = ['services' => ['app' => ['image' => 'acme/app:2']]];
        $found = UnpublishedAppPort::apply($compose, static function (string $image) use (&$asked): array {
            $asked[] = $image;

            return [5432, 7070];
        });

        $this->assertSame(['acme/app:2'], $asked);
        $this->assertSame([7070, "the image's EXPOSE"], [$found['port'] ?? null, $found['source'] ?? null]);
    }

    public function test_a_published_port_or_a_datastore_is_left_alone(): void
    {
        $published = ['services' => ['app' => ['image' => 'acme/app', 'ports' => ['3000:3000'], 'environment' => ['PORT=9494']]]];
        $this->assertNull(UnpublishedAppPort::apply($published));

        // Only the datastore names a port: the app is still unknown.
        $datastore = ['services' => ['db' => ['image' => 'postgres:16', 'environment' => ['PORT=5432']], 'app' => ['image' => 'acme/app']]];
        $this->assertNull(UnpublishedAppPort::apply($datastore, static fn (): array => []));
    }

    public function test_nothing_found_is_null(): void
    {
        $this->assertNull(UnpublishedAppPort::apply(self::REIVERR, static fn (): array => []));
        $this->assertNull(UnpublishedAppPort::apply(['services' => ['app' => ['image' => 'a', 'environment' => ['PORT=${PORT}']]]]));
    }
}
