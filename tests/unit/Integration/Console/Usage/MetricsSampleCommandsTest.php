<?php

namespace Tests\Integration\Console\Usage;

use App\Lib\Host\ProjectDisk;
use App\Lib\Host\ProjectStats;
use App\Lib\Project\ClassicAppType;
use App\Lib\Usage\ProjectBandwidth;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** The scheduled sampling commands run their sampler once and say what it wrote only when asked to be verbose. */
class MetricsSampleCommandsTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /** @return array<string, array{string, class-string, string, mixed, string}> */
    public static function samplers(): array
    {
        return [
            'disk' => ['metrics:projects:disk', ProjectDisk::class, 'sample', 3, '3 project(s).'],
            'bandwidth' => ['metrics:projects:bandwidth', ProjectBandwidth::class, 'sample', 4, '4 project(s).'],
            'stats' => ['metrics:projects:sample', ProjectStats::class, 'sample', ['projects' => 2, 'containers' => 5], '2 project(s), 5 container(s).'],
            'app type' => ['projects:app-type:sample', ClassicAppType::class, 'sweep', 1, '1 project(s) changed type.'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('samplers')]
    public function test_a_sampler_runs_once_and_reports_when_verbose(string $command, string $class, string $method, mixed $result, string $line): void
    {
        $sampler = Mockery::mock($class);
        $sampler->shouldReceive($method)->twice()->andReturn($result);
        $this->app->instance($class, $sampler);

        $this->artisan($command, ['-v' => true])->expectsOutput($line)->assertExitCode(0);
        $this->artisan($command)->doesntExpectOutput($line)->assertExitCode(0);
    }

    public function test_rollup_with_no_samples_writes_no_rows(): void
    {
        $this->artisan('metrics:projects:rollup', ['-v' => true])
            ->expectsOutput('0 hourly row(s).')
            ->assertExitCode(0);
    }
}
