<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\MovedImages;
use PHPUnit\Framework\TestCase;

/**
 * Bitnami took its versioned tags off `bitnami/` and keeps them under `bitnamilegacy/`: a service on one runs it from
 * there, when Docker Hub no longer has it where the file says and has it where it went.
 */
class MovedImagesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $services
     * @param  list<string>  $published
     * @return array{services: array<mixed>, moved: list<array{service: string, from: string, to: string}>}
     */
    private function apply(array $services, array $published, array $env = []): array
    {
        return MovedImages::apply($services, $env, static fn (string $image): bool => in_array($image, $published, true));
    }

    public function test_a_withdrawn_bitnami_tag_runs_from_bitnamilegacy(): void
    {
        $result = $this->apply(['valkey' => ['image' => 'bitnami/valkey:8.0', 'ports' => ['6379']]], ['bitnamilegacy/valkey:8.0']);

        $this->assertSame('bitnamilegacy/valkey:8.0', $result['services']['valkey']['image']);
        $this->assertSame([['service' => 'valkey', 'from' => 'bitnami/valkey:8.0', 'to' => 'bitnamilegacy/valkey:8.0']], $result['moved']);
    }

    public function test_an_interpolated_or_untagged_reference_is_resolved_first(): void
    {
        $result = $this->apply(
            ['kafka' => ['image' => 'docker.io/bitnami/kafka:${KAFKA_VERSION:-2.8.1}'], 'minio' => ['image' => 'bitnami/minio']],
            ['bitnamilegacy/kafka:2.8.1', 'bitnamilegacy/minio:latest']
        );

        $this->assertSame('bitnamilegacy/kafka:2.8.1', $result['services']['kafka']['image']);
        $this->assertSame('bitnamilegacy/minio:latest', $result['services']['minio']['image']);
    }

    public function test_a_tag_bitnami_still_publishes_is_kept(): void
    {
        $result = $this->apply(['redis' => ['image' => 'bitnami/redis:latest']], ['bitnami/redis:latest', 'bitnamilegacy/redis:latest']);

        $this->assertSame('bitnami/redis:latest', $result['services']['redis']['image']);
        $this->assertSame([], $result['moved']);
    }

    public function test_nothing_moves_when_the_new_place_lacks_the_tag_or_cannot_be_asked(): void
    {
        $this->assertSame([], $this->apply(['pg' => ['image' => 'bitnami/postgresql:99']], [])['moved']);
        $this->assertSame([], MovedImages::apply(['pg' => ['image' => 'bitnami/postgresql:16']], [], static fn (): ?bool => null)['moved']);
    }

    public function test_other_namespaces_and_built_services_are_left_alone(): void
    {
        $this->assertNull(MovedImages::movedTo('quay.io/bitnami/redis:7'));
        $this->assertNull(MovedImages::movedTo('postgres:16'));
        $this->assertSame([], $this->apply(['app' => ['image' => 'bitnami/app:1', 'build' => '.']], ['bitnamilegacy/app:1'])['moved']);
    }
}
