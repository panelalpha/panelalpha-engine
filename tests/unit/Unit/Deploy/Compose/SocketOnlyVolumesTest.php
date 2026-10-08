<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceHardener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A service whose only mount was the Docker socket.
 *
 * The socket is stripped from every kept compose service, which is right --
 * handing a tenant the host's daemon is handing them the host. What was wrong
 * is what it left behind: an empty PHP array, which dumps as `volumes: {  }`,
 * a map rather than a sequence. Compose then refuses the entire file with
 * `services.traefik.volumes must be a array`, and the deploy fails on a file
 * the engine wrote itself, before any build starts.
 *
 * PhotoPrism found it: its traefik service mounts the socket and nothing else,
 * and upstream's compose.yaml is valid — the invalid YAML was ours.
 */
class SocketOnlyVolumesTest extends TestCase
{
    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function harden(array $service): array
    {
        return ServiceHardener::harden('traefik', $service);
    }

    public function test_a_service_mounting_only_the_socket_declares_no_volumes(): void
    {
        $hardened = $this->harden([
            'image' => 'traefik:v3',
            'volumes' => ['/var/run/docker.sock:/var/run/docker.sock'],
        ]);

        $this->assertArrayNotHasKey(
            'volumes',
            $hardened,
            'an emptied list must be dropped, not left as an empty array'
        );
    }

    /**
     * The actual failure, reproduced at the level it appeared: Compose reads
     * YAML, and an empty PHP array is not an empty YAML sequence.
     */
    public function test_the_rendered_yaml_has_no_empty_volumes_mapping(): void
    {
        $hardened = $this->harden([
            'image' => 'traefik:v3',
            'volumes' => ['/var/run/docker.sock:/var/run/docker.sock'],
        ]);

        $yaml = Yaml::dump(['services' => ['traefik' => $hardened]], 6, 2);

        $this->assertStringNotContainsString('volumes: {', $yaml);
        $this->assertStringNotContainsString('volumes: []', $yaml);
    }

    /** The socket still goes, which is the point of the filter. */
    public function test_the_socket_is_still_stripped_when_other_mounts_remain(): void
    {
        $hardened = $this->harden([
            'image' => 'traefik:v3',
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock',
                './data:/data',
            ],
        ]);

        $this->assertSame(['./data:/data'], $hardened['volumes']);
    }

    /** A service that declares no volumes is untouched. */
    public function test_a_service_without_volumes_is_unchanged(): void
    {
        $hardened = $this->harden(['image' => 'redis:7']);

        $this->assertArrayNotHasKey('volumes', $hardened);
    }

    /** Ordinary volumes survive intact and stay a list. */
    public function test_ordinary_volumes_are_preserved_as_a_sequence(): void
    {
        $hardened = $this->harden([
            'image' => 'mariadb:11',
            'volumes' => ['dbdata:/var/lib/mysql'],
        ]);

        $this->assertSame(['dbdata:/var/lib/mysql'], $hardened['volumes']);

        // Round-tripped rather than pattern-matched: the dumper may render a
        // short list inline, and what Compose cares about is that it parses
        // back as a sequence.
        $parsed = Yaml::parse(Yaml::dump(['services' => ['traefik' => $hardened]], 6, 2));
        $this->assertSame(['dbdata:/var/lib/mysql'], $parsed['services']['traefik']['volumes']);
        $this->assertArrayHasKey(0, $parsed['services']['traefik']['volumes']);
    }
}
