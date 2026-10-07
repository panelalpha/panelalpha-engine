<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\ImageTransfer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageTransferConcurrencyTest extends TestCase
{
    public function test_a_minimum_vps_seeds_two_at_a_time_when_idle(): void
    {
        // 2 cores, 3GB box with ~2GB available, panel idle.
        $this->assertSame(2, ImageTransfer::concurrencyFor(2, 0.31, 2048, 4));
    }

    public function test_the_same_vps_falls_back_to_sequential_under_load(): void
    {
        // A core is already spoken for, so take only what is left.
        $this->assertSame(1, ImageTransfer::concurrencyFor(2, 1.60, 2048, 4));
        $this->assertSame(1, ImageTransfer::concurrencyFor(2, 4.00, 2048, 4));
    }

    public function test_low_memory_caps_it_regardless_of_cores(): void
    {
        // 16 cores but only 600MB free: one stream, not sixteen.
        $this->assertSame(1, ImageTransfer::concurrencyFor(16, 0.1, 600, 8));
    }

    public function test_a_large_host_uses_the_room_it_has(): void
    {
        $this->assertSame(
            ImageTransfer::MAX_CONCURRENCY,
            ImageTransfer::concurrencyFor(16, 1.0, 32768, 8)
        );
    }

    public function test_never_more_streams_than_images(): void
    {
        $this->assertSame(2, ImageTransfer::concurrencyFor(16, 0.0, 32768, 2));
        $this->assertSame(1, ImageTransfer::concurrencyFor(16, 0.0, 32768, 0));
    }

    public function test_operator_override_wins_but_is_still_clamped(): void
    {
        // Explicit setting beats the heuristic on a busy 2-core box...
        $this->assertSame(3, ImageTransfer::concurrencyFor(2, 5.0, 512, 4, 3));
        // ...but never past the ceiling or the image count.
        $this->assertSame(ImageTransfer::MAX_CONCURRENCY, ImageTransfer::concurrencyFor(2, 0.0, 8192, 8, 99));
        $this->assertSame(2, ImageTransfer::concurrencyFor(2, 0.0, 8192, 2, 99));
    }

    public function test_unreadable_host_metrics_degrade_to_sequential(): void
    {
        $load = ImageTransfer::parseLoadAvg('');
        $mem = ImageTransfer::parseMemAvailableMb('');
        $cpus = ImageTransfer::parseCpuCount('');

        $this->assertSame(1, $cpus);
        $this->assertSame(0, $mem);
        $this->assertSame(1, ImageTransfer::concurrencyFor($cpus, $load, $mem, 4));
    }

    public function test_parses_real_proc_files(): void
    {
        $this->assertSame(2, ImageTransfer::parseCpuCount(
            "processor\t: 0\nvendor_id\t: GenuineIntel\n\nprocessor\t: 1\nvendor_id\t: GenuineIntel\n"
        ));
        $this->assertSame(0.31, ImageTransfer::parseLoadAvg("0.31 0.96 0.97 1/779 530549\n"));
        $this->assertSame(2048, ImageTransfer::parseMemAvailableMb(
            "MemTotal:        3000000 kB\nMemFree:          500000 kB\nMemAvailable:    2097152 kB\n"
        ));
    }

    public function test_a_digest_pinned_image_is_seeded_like_any_other(): void
    {
        $digest = 'postgres@sha256:' . str_repeat('a', 64);

        $this->assertTrue(ImageTransfer::isSafeImageRef($digest));
        $this->assertSame($digest, ImageTransfer::normalizeImageRef($digest));
    }

    public function test_a_recipe_pinned_image_is_accepted_and_kept_by_its_digest(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);

        $this->assertTrue(ImageTransfer::isSafeImageRef("sharelatex/sharelatex:6.3.0@{$digest}"));
        $this->assertTrue(ImageTransfer::isSafeImageRef("ghcr.io/meeb/tubesync:v0.18.4@{$digest}"));
        $this->assertTrue(ImageTransfer::isSafeImageRef("registry.internal:5000/ns/pg:16@{$digest}"));
        $this->assertSame("sharelatex/sharelatex@{$digest}", ImageTransfer::normalizeImageRef("sharelatex/sharelatex:6.3.0@{$digest}"));
        $this->assertSame("registry.internal:5000/ns/pg@{$digest}", ImageTransfer::preferDigest("registry.internal:5000/ns/pg:16@{$digest}"));
        $this->assertSame("registry.internal:5000/ns/pg@{$digest}", ImageTransfer::preferDigest("registry.internal:5000/ns/pg@{$digest}"));
        $this->assertSame('registry.internal:5000/ns/pg:16', ImageTransfer::preferDigest('registry.internal:5000/ns/pg:16'));
    }

    public function test_a_private_registry_with_a_port_is_seeded_like_any_other(): void
    {
        $this->assertTrue(ImageTransfer::isSafeImageRef('registry.internal:5000/ns/pg:16'));
        $this->assertSame(
            'registry.internal:5000/ns/pg:latest',
            ImageTransfer::normalizeImageRef('registry.internal:5000/ns/pg')
        );
    }

    #[DataProvider('unsafeReferences')]
    public function test_unsafe_references_are_refused(string $image): void
    {
        $this->assertFalse(ImageTransfer::isSafeImageRef($image), $image);
        $this->assertNull(ImageTransfer::normalizeImageRef($image), $image);
    }

    /** @return array<string, array{string}> */
    public static function unsafeReferences(): array
    {
        return [
            'leading dash reads as a flag' => ['-rm:latest'],
            'unexpanded variable' => ['${DB_IMAGE}:1'],
            'whitespace' => ['post gres:16'],
            'shell metacharacter' => ['postgres:16;id'],
            'empty' => [''],
            'short digest beside a tag' => ['postgres:16@sha256:abc'],
            'empty tag before a digest' => ['postgres:@sha256:' . str_repeat('a', 64)],
            'tag after a digest' => ['postgres@sha256:' . str_repeat('a', 64) . ':16'],
            'two digests' => ['postgres@sha256:' . str_repeat('a', 64) . '@sha256:' . str_repeat('b', 64)],
        ];
    }

    public function test_exposed_ports_are_read_from_image_metadata(): void
    {
        $this->assertSame([5432], ImageTransfer::parseExposedPorts('{"5432/tcp":{}}'));
        $this->assertSame([80, 443], ImageTransfer::parseExposedPorts('{"80/tcp":{},"443/tcp":{}}'));
        $this->assertSame([], ImageTransfer::parseExposedPorts('null'));
        $this->assertSame([], ImageTransfer::parseExposedPorts('not json'));
        $this->assertSame([], ImageTransfer::parseExposedPorts(''));
    }
}
