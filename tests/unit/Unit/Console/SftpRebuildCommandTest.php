<?php

namespace Tests\Unit\Console;

use App\System;
use App\System\Services\Sftp;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SftpRebuildCommandTest extends TestCase
{
    public function test_a_failed_rebuild_exits_non_zero_with_the_reason(): void
    {
        $this->app->instance(System::class, $this->systemWhoseRebuild(fails: true));

        $exit = Artisan::call('system:sftp:rebuild');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('the sftp container is not running', Artisan::output());
    }

    public function test_a_successful_rebuild_exits_zero(): void
    {
        $this->app->instance(System::class, $this->systemWhoseRebuild(fails: false));

        $this->assertSame(0, Artisan::call('system:sftp:rebuild'));
    }

    private function systemWhoseRebuild(bool $fails): System
    {
        return new class ($fails) extends System {
            public function __construct(private bool $fails)
            {
            }

            public function sftp(): Sftp
            {
                return new class ($this, $this->fails) extends Sftp {
                    public function __construct(System $system, private bool $fails)
                    {
                        parent::__construct($system);
                    }

                    public function rebuildSftpAccounts(): void
                    {
                        if ($this->fails) {
                            throw new \RuntimeException('the sftp container is not running');
                        }
                    }
                };
            }
        };
    }
}
