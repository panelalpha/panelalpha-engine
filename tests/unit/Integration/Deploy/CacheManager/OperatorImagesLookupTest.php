<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\OperatorImageHost;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Lib\Deploy\CacheManager\PublicImageLookup;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;
use App\System;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Finding an operator image, a publish the registry does not confirm, and an over-long note.
 */
class OperatorImagesLookupTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    private function images(): OperatorImages
    {
        return new OperatorImages(new UnlistedPushHost, new PublicImageLookup(static fn (): ?int => 404));
    }

    public function test_an_image_is_found_by_id_and_by_ref(): void
    {
        $image = OperatorImage::query()->create(['ref' => 'acme-base:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_READY]);

        $this->assertTrue($image->is(OperatorImages::findOrFail($image->id)));
        $this->assertTrue($image->is(OperatorImages::findByRefOrFail('acme-base:1')));
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Operator image 404 not found.');

        OperatorImages::findOrFail(404);
    }

    public function test_an_unknown_ref_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Operator image 'acme-base:9' not found.");

        OperatorImages::findByRefOrFail('acme-base:9');
    }

    public function test_a_push_the_registry_does_not_list_fails_the_image(): void
    {
        $image = OperatorImage::query()->create(['ref' => 'acme-base:1', 'source' => 'pull', 'status' => OperatorImage::STATUS_PENDING]);

        try {
            $this->images()->publish($image, false, static function (): void {});
            $this->fail('an unlisted push should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('The push finished, but the cache registry does not list acme-base:1.', $e->getMessage());
        }

        $this->assertSame(OperatorImage::STATUS_FAILED, $image->fresh()->status);
        $this->assertSame('The push finished, but the cache registry does not list acme-base:1.', $image->fresh()->error);
        $this->assertNull($image->fresh()->digest);
    }

    public function test_a_note_longer_than_a_column_is_refused(): void
    {
        try {
            $this->images()->create(['ref' => 'acme-base:1', 'source' => 'pull', 'why' => str_repeat('x', 256)]);
            $this->fail('an over-long note should be refused');
        } catch (ProblemException $e) {
            $this->assertStringContainsString(OperatorImages::WHY_INVALID, (string) json_encode($e->problems ?? []));
        }
        $this->assertSame(0, OperatorImage::query()->count());
    }

    public function test_make_builds_the_service_for_the_default_engine(): void
    {
        $this->assertInstanceOf(OperatorImages::class, OperatorImages::make());
    }
}

class UnlistedPushHost extends OperatorImageHost
{
    public function __construct()
    {
        parent::__construct(new System, new DindImageStore, new CacheRegistry(static fn (): ?array => null), new RegistryImageConfig(static fn (): ?string => null));
    }

    public function acquire(OperatorImage $image, bool $fresh, callable $output): void {}

    public function publish(string $ref, callable $output): void {}

    public function measure(string $ref): array
    {
        return ['digest' => null, 'size' => null];
    }
}
