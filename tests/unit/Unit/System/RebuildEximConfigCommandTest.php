<?php

namespace Tests\Unit\System;

use App\System;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** system:exim:rebuild has to exit non-zero when the rebuild fails (engine#796). */
class RebuildEximConfigCommandTest extends TestCase
{
    private function stub(\Throwable $failure = null): void
    {
        $this->app->instance(System::class, new class ($failure) extends System {
            public function __construct(private ?\Throwable $failure)
            {
            }

            public function exim(): \App\System\Services\Exim
            {
                return new class ($this->failure) extends \App\System\Services\Exim {
                    public function __construct(private ?\Throwable $failure)
                    {
                    }

                    public function rebuildEximConfig(): void
                    {
                        if ($this->failure !== null) {
                            throw $this->failure;
                        }
                    }

                    public function rebuildNetworks(): void
                    {
                        if ($this->failure !== null) {
                            throw $this->failure;
                        }
                    }
                };
            }
        });
    }

    public function test_a_successful_rebuild_exits_zero(): void
    {
        $this->stub();
        $exit = Artisan::call('system:exim:rebuild');

        $this->assertSame(0, $exit);
    }

    public function test_a_failed_rebuild_exits_non_zero_and_prints_the_message(): void
    {
        $this->stub(new \RuntimeException('apply failed'));
        $exit = Artisan::call('system:exim:rebuild');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('apply failed', Artisan::output());
    }

    public function test_the_networks_variant_exits_non_zero_on_failure(): void
    {
        $this->stub(new \RuntimeException('networks failed'));
        $exit = Artisan::call('system:exim:rebuild', ['--networks' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('networks failed', Artisan::output());
    }

    public function test_an_error_that_is_not_an_exception_still_exits_non_zero(): void
    {
        // A TypeError used to slip past catch (\Exception) and exit 0.
        $this->stub(new \TypeError('bad call'));
        $exit = Artisan::call('system:exim:rebuild');

        $this->assertSame(1, $exit);
    }
}
