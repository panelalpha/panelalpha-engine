<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use App\Console\Commands\Stats\StatsUpdateCommand;
use App\Console\Kernel;
use App\Integrations\Statistics\Statistics;
use App\Models\Domain;
use App\System;
use App\System\Services\Webserver;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

class StatsUpdateCommandTest extends TestCase
{
    public function test_schedule_is_daily_and_does_not_overlap(): void
    {
        $schedule = new Schedule();
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $method->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            static fn ($item): bool => str_contains((string) $item->command, 'stats:update')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_project_and_domain_filters_are_options(): void
    {
        $definition = (new StatsUpdateCommand())->getDefinition();
        $this->assertTrue($definition->hasOption('project'));
        $this->assertTrue($definition->hasOption('domain'));
    }

    public function test_update_ingests_candidate_domains_from_current_webserver_logs(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);
        $this->app->instance(System::class, $this->systemDouble('/engine', 'nginx'));

        $example = new Domain();
        $example->domain = 'example.com';
        $other = new Domain();
        $other->domain = 'other.com';

        $command = new class (collect([$example, $other])) extends StatsUpdateCommand {
            public function __construct(private Collection $rows)
            {
                parent::__construct();
            }

            protected function candidateDomains(): Collection
            {
                return $this->rows;
            }
        };
        $command->setLaravel($this->app);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $this->assertSame(['example.com', 'other.com'], $fake->ingested);
    }

    public function test_overlapping_run_is_skipped(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);
        $lockPath = storage_path('app/stats-update.lock');
        if (!is_dir(dirname($lockPath))) {
            mkdir(dirname($lockPath), 0775, true);
        }
        $hold = fopen($lockPath, 'c');
        $this->assertNotFalse($hold);
        $this->assertTrue(flock($hold, LOCK_EX | LOCK_NB));

        try {
            $command = new class (collect([])) extends StatsUpdateCommand {
                public function __construct(private Collection $rows)
                {
                    parent::__construct();
                }

                protected function candidateDomains(): Collection
                {
                    return $this->rows;
                }
            };
            $command->setLaravel($this->app);
            $status = $command->run(new ArrayInput([]), new BufferedOutput());
            $this->assertSame(0, $status);
            $this->assertSame([], $fake->ingested);
        } finally {
            flock($hold, LOCK_UN);
            fclose($hold);
        }
    }

    private function systemDouble(string $engineRoot, string $webserver): System
    {
        return new class ($engineRoot, $webserver) extends System {
            public function __construct(
                private string $engineRoot,
                private string $slug,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function webserver(): Webserver
            {
                $slug = $this->slug;

                return new class ($slug) extends Webserver {
                    public function __construct(private string $slug)
                    {
                    }

                    public function getCurrentWebserver(): string
                    {
                        return $this->slug;
                    }
                };
            }
        };
    }
}
