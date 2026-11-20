<?php

namespace Tests\Integration\Deploy\CacheManager;

use App\Exceptions\ProblemException;
use App\Http\Requests\SystemImageStoreRequest;
use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\OperatorImageHost;
use App\Lib\Deploy\CacheManager\OperatorImageRef;
use App\Lib\Deploy\CacheManager\OperatorImages;
use App\Lib\Deploy\CacheManager\PublicImageLookup;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Models\OperatorImage;
use App\System;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The operator image input: which names, Dockerfiles and build arguments are refused, with the host work faked.
 */
class OperatorImageInputTest extends TestCase
{
    use InMemoryDatabase;

    private const DOCKERFILE = "FROM debian:bookworm-slim\nRUN true\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Queue::fake();
    }

    public function test_operator_naming_an_image_without_a_tag_is_refused(): void
    {
        foreach (['mybase', 'registry.example.com:5000/mybase', 'MyBase:1'] as $ref) {
            $this->assertSame(OperatorImageRef::INVALID, OperatorImageRef::problem($ref, 'build', [])['code'], $ref);
        }
        $this->assertNull(OperatorImageRef::problem('mybase:1', 'build', []));
    }

    public function test_operator_naming_an_image_after_the_engine_is_refused_as_reserved(): void
    {
        $this->assertSame(OperatorImageRef::RESERVED, OperatorImageRef::problem('panelalpha/app:1', 'build', [])['code']);
        $this->assertSame(OperatorImageRef::RESERVED, OperatorImageRef::problem('docker.io/panelalpha/app:1', 'build', [])['code']);
        $this->assertSame(OperatorImageRef::RESERVED, OperatorImageRef::problem('debian:12', 'build', ['debian:12'])['code']);
    }

    public function test_dockerfile_without_a_from_line_is_refused(): void
    {
        $this->assertProblem(OperatorImages::DOCKERFILE_INVALID, ['dockerfile' => "RUN true\n"]);
        $this->assertProblem(OperatorImages::DOCKERFILE_INVALID, ['dockerfile' => "# FROM debian\nRUN true\n"]);
        $this->assertSame(OperatorImage::STATUS_PENDING, $this->create(['ref' => 'a:2'])->status);
    }

    public function test_dockerfile_over_64_kb_is_refused(): void
    {
        $pad = fn (int $bytes): string => self::DOCKERFILE.'#'.str_repeat('x', $bytes - strlen(self::DOCKERFILE) - 1);

        $this->assertProblem(OperatorImages::DOCKERFILE_INVALID, ['dockerfile' => $pad(65537)]);
        $this->assertSame(OperatorImage::STATUS_PENDING, $this->create(['ref' => 'a:2', 'dockerfile' => $pad(65536)])->status);
    }

    public function test_more_than_50_build_args_are_refused(): void
    {
        $args = fn (int $n): array => array_combine(array_map(fn (int $i): string => "ARG_{$i}", range(1, $n)), array_fill(0, $n, 'v'));

        $this->assertProblem(OperatorImages::BUILD_ARGS_INVALID, ['build_args' => $args(51)]);
        $this->assertCount(50, $this->create(['ref' => 'a:2', 'build_args' => $args(50)])->build_args);
    }

    public function test_request_takes_every_image_field_unchecked_for_the_service_to_judge(): void
    {
        $rules = (new SystemImageStoreRequest)->rules();

        $this->assertSame(['ref', 'source', 'dockerfile', 'build_args', 'why'], array_keys($rules));
        $this->assertTrue(Validator::make(['ref' => 'a:1', 'build_args' => ['A' => 'b']], $rules)->passes());
    }

    /** @param array<string, mixed> $input */
    private function create(array $input): OperatorImage
    {
        return $this->images()->create($input + ['ref' => 'a:1', 'source' => 'build', 'dockerfile' => self::DOCKERFILE]);
    }

    private function images(): OperatorImages
    {
        return new OperatorImages($this->host(), new PublicImageLookup(fn (): ?int => 404));
    }

    private function host(): OperatorImageHost
    {
        return new class extends OperatorImageHost
        {
            public function __construct()
            {
                parent::__construct(new System, new DindImageStore, new CacheRegistry(static fn (): ?array => null), new RegistryImageConfig(static fn (): ?string => null));
            }

            public function isOnHost(string $ref): bool
            {
                return false;
            }
        };
    }

    /** @param array<string, mixed> $input */
    private function assertProblem(string $code, array $input): void
    {
        try {
            $this->create($input);
        } catch (ProblemException $e) {
            $this->assertStringContainsString($code, json_encode($e->problems) ?: '');

            return;
        }
        $this->fail("expected the problem {$code}");
    }
}
