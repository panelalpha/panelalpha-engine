<?php

namespace Tests\Unit\Limits;

use App\Exceptions\ProblemException;
use App\Http\Requests\UserUpdateRequest;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use Illuminate\Routing\Route;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A container project's account runs its own Docker daemon, which was killed
 * for memory at 112 MB: an update keeps such a project at 256 MB or more, and
 * leaves a traditional project at Docker's own 6 MB floor.
 */
class ContainerMemoryFloorTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        HostMemoryProbe::fake(new HostMemory(3809));
    }

    protected function tearDown(): void
    {
        HostMemoryProbe::fake(null);
        parent::tearDown();
    }

    public function test_an_update_keeps_a_container_project_at_its_floor(): void
    {
        $this->makeUser('dock', ['template' => 'dind']);

        try {
            $this->validatedUpdate('dock', 128);
            $this->fail('128 MB was accepted for a container project');
        } catch (ProblemException $e) {
            $this->assertSame([['memory_limit', 'memory_limit_too_small', 256]], array_map(
                static fn (array $p): array => [$p['field'], $p['code'], $p['min_mb']],
                $e->problems,
            ));
        }
        $this->assertSame(['memory_limit' => 256], $this->validatedUpdate('dock', 256));
    }

    public function test_an_update_leaves_a_traditional_project_at_dockers_floor(): void
    {
        $this->makeUser('plain', ['template' => 'default']);

        $this->assertSame(['memory_limit' => 128], $this->validatedUpdate('plain', 128));
    }

    /** @return array<string, mixed> */
    private function validatedUpdate(string $username, int $memoryMb): array
    {
        $request = UserUpdateRequest::create("/api/projects/{$username}", 'PUT', ['memory_limit' => $memoryMb]);
        $request->setContainer($this->app);
        $request->setRouteResolver(static fn (): Route => (new Route('PUT', 'api/projects/{username}', []))->bind($request));
        $request->validateResolved();

        return $request->validated();
    }
}
