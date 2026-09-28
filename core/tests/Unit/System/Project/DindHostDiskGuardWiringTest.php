<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Both entry points every deploy passes through refuse on a nearly full host
 * before doing any work (engine#87).
 */
class DindHostDiskGuardWiringTest extends TestCase
{
    public function test_prepare_refuses_on_a_full_host_before_touching_the_project(): void
    {
        config(['deploy.host_min_free' => '3G']);
        $system = $this->fullHost();

        try {
            $this->dind($system)->prepareFromSources();
            $this->fail('prepareFromSources() went ahead on a host with 200M free');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Deploy refused before it started', $e->getMessage());
        }
        $this->assertSame([], $system->ran);
    }

    public function test_pre_check_refuses_on_a_full_host_before_the_clone(): void
    {
        config(['deploy.host_min_free' => '3G']);
        $system = $this->fullHost();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('below the 3G a deploy needs');

        $this->dind($system)->preCheckFromSources();
    }

    private function dind(System $system): Dind
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
            'git_repo' => 'https://github.com/example/app.git',
            'git_branch' => 'main',
        ]);

        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function fullHost(): System
    {
        return new class extends System {
            /** @var list<string> */
            public array $ran = [];

            public function __construct()
            {
            }

            public function engineDirPath(): string
            {
                return sys_get_temp_dir() . '/pa-disk-guard-engine';
            }

            public function homesDirPath(): string
            {
                return sys_get_temp_dir() . '/pa-disk-guard-home';
            }

            public function execOnHost(string|array $cmd, array $env = []): string
            {
                $path = is_array($cmd) ? (string) end($cmd) : '';

                return "Filesystem 1024-blocks Used Available Capacity Mounted on\n"
                    . '/dev/sda1 157286400 150000000 ' . (200 * 1024) . " 99% {$path}\n";
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                throw new \LogicException('nothing should run on a full host');
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->ran[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                throw new \LogicException('nothing should run on a full host');
            }
        };
    }
}
