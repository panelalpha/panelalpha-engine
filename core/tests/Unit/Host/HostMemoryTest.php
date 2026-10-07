<?php

namespace Tests\Unit\Host;

use App\Exceptions\ProblemException;
use App\Http\Controllers\ServerMetricsController;
use App\Http\Requests\UserStoreRequest;
use App\Lib\Deploy\Compose\ServiceLimits;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Host\ProjectMemory;
use App\Rules\AccountMemoryLimit;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * How much memory a project may have: the host's RAM less what is kept for
 * the engine. A small host's MemTotal is 3809 MB.
 */
class HostMemoryTest extends TestCase
{
    private function smallHost(): HostMemory
    {
        return new HostMemory(3809);
    }

    protected function tearDown(): void
    {
        HostMemoryProbe::fake(null);
        parent::tearDown();
    }

    public function test_a_project_may_have_the_host_less_the_engine(): void
    {
        $memory = $this->smallHost();

        $this->assertSame(512, $memory->engineMb);
        $this->assertSame(3297, $memory->maxProjectMb(), '3809 - 512');
        $this->assertSame(['total_mb' => 3809, 'engine_mb' => 512, 'max_project_mb' => 3297], $memory->toArray());
    }

    public function test_the_engine_reserve_is_configurable(): void
    {
        config(['deploy.engine_memory' => 16384]);

        $this->assertSame(16384, HostMemoryProbe::current()->engineMb);
        $this->assertSame(16000, HostMemoryProbe::fromReadings("MemTotal:       32768000 kB\n", 16000)->maxProjectMb());
    }

    public function test_it_reads_memtotal_and_ignores_what_is_free(): void
    {
        $memory = HostMemoryProbe::fromReadings("MemTotal:        3900696 kB\nMemFree:          100000 kB\nMemAvailable:     200000 kB\n");

        $this->assertSame([3809, 512], [$memory->totalMb, $memory->engineMb]);
    }

    public function test_a_project_without_a_limit_gets_the_maximum(): void
    {
        HostMemoryProbe::fake($this->smallHost());

        $this->assertSame(3297, ProjectMemory::defaultMb());
        $this->assertSame(3297, ProjectMemory::resolve(null));
        $this->assertSame(3297, ProjectMemory::resolve(0));
        $this->assertSame(512, ProjectMemory::resolve(512));
    }

    public function test_a_configured_default_is_kept_but_never_above_the_maximum(): void
    {
        config(['deploy.project_memory_default' => 2048]);
        $this->assertSame(2048, ProjectMemory::defaultMb($this->smallHost()));

        config(['deploy.project_memory_default' => 8192]);
        $this->assertSame(3297, ProjectMemory::defaultMb($this->smallHost()));
    }

    /** A project from before limits were required is sized like one created with the default. */
    public function test_a_project_without_a_limit_sizes_its_services_and_build_from_the_default(): void
    {
        HostMemoryProbe::fake(new HostMemory(4608));
        $mb = ProjectMemory::resolve(null);

        $this->assertSame(4096, $mb);
        $this->assertSame('3584m', ServiceLimits::memoryFor('app', [], $mb));
        $this->assertSame(2867, ServiceLimits::nodeHeapMbForAccount($mb));
        $this->assertSame('2304m', DindEngine::buildMemory('', new HostMemory(4608))->limit);
    }

    public function test_no_project_may_exceed_the_maximum(): void
    {
        $memory = $this->smallHost();

        $this->assertNull(ProjectMemory::problem(3297, $memory));
        $problem = ProjectMemory::problem(3298, $memory);
        $this->assertSame('memory_limit_too_large', $problem['code'] ?? null);
        $this->assertSame(3297, $problem['max_mb'] ?? null);
        $this->assertStringContainsString('greater than 3297 MB', $problem['message'] ?? '');
    }

    /** No floor of ours: a tiny static site may run on very little. */
    public function test_any_positive_limit_that_fits_is_accepted(): void
    {
        $this->assertNull(ProjectMemory::problem(32, $this->smallHost()));
    }

    public function test_the_rule_reports_the_problem_with_its_code(): void
    {
        $rule = new AccountMemoryLimit($this->smallHost());
        $failed = null;
        $rule->validate('memory_limit', 4096, function (string $m) use (&$failed) {
            $failed = $m;
        });

        $this->assertNotNull($failed);
        $this->assertSame('memory_limit_too_large', $rule->problem()['code'] ?? null);
        $this->assertSame('memory_limit', $rule->problem()['field'] ?? null);
    }

    /** Through the create request: an omitted limit becomes the maximum, and passes. */
    public function test_a_created_project_without_a_limit_gets_the_maximum(): void
    {
        HostMemoryProbe::fake($this->smallHost());
        $request = UserStoreRequest::create('/api/users', 'POST', ['username' => 'shop4a2f', 'email' => 'ops@example.com']);
        $request->setContainer($this->app);
        $request->validateResolved();

        $this->assertSame(3297, $request->validated()['memory_limit']);
    }

    /** Through the create request: the maximum passes, one MB more does not. */
    public function test_the_create_request_refuses_only_above_the_maximum(): void
    {
        HostMemoryProbe::fake($this->smallHost());
        $validator = function (int $mb) {
            $request = UserStoreRequest::create('/api/users', 'POST', ['username' => 'shop4a2f', 'memory_limit' => $mb]);
            $request->setContainer($this->app);

            return Validator::make(['username' => 'shop4a2f', 'memory_limit' => $mb], $request->rules());
        };

        $this->assertFalse($validator(3297)->fails());
        $errors = $validator(3298)->errors()->toArray();
        $this->assertStringContainsString('greater than 3297 MB', $errors['memory_limit'][0] ?? '');
    }

    /** A clone or staging copy keeps its source's limit; it is refused only when that no longer fits. */
    public function test_a_copy_is_refused_only_above_the_maximum(): void
    {
        HostMemoryProbe::fake($this->smallHost());
        ProjectMemory::assertFits(3297);

        try {
            ProjectMemory::assertFits(4096);
            $this->fail('4096 MB does not fit a 3809 MB host');
        } catch (ProblemException $e) {
            $this->assertSame('memory_limit_too_large', $e->problems[0]['code'] ?? null);
        }
    }

    public function test_the_metrics_report_the_ceiling_and_the_default(): void
    {
        HostMemoryProbe::fake($this->smallHost());
        $data = (new ServerMetricsController())->current()->getData(true)['data'];

        $this->assertSame([
            'total_mb' => 3809,
            'engine_mb' => 512,
            'max_project_mb' => 3297,
            'default_project_mb' => 3297,
        ], $data['memory_budget']);
    }
}
