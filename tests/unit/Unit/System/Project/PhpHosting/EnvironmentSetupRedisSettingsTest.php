<?php

namespace Tests\Unit\System\Project\PhpHosting;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting;
use App\System\Project\PhpHosting\EnvironmentSetup;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * The account's Redis settings written as redis/conf.d/custom.conf, copied into place by root.
 */
class EnvironmentSetupRedisSettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_settings_are_copied_into_custom_conf(): void
    {
        $host = new RedisHost;

        $this->apply($host);

        $this->assertSame([
            'run files.makeDirectories',
            "cp {$host->projectDirPath('alice')}/redis/conf.d/custom.conf\nmaxmemory 64mb\nmaxmemory-policy allkeys-lru\n",
        ], $host->journal);
    }

    public function test_settings_that_cannot_be_staged_are_not_copied(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root writes a 0400 file, so staging never fails');
        }
        $host = new RedisHost;
        $umask = umask(0277);
        try {
            $this->apply($host);
            $this->fail('the unwritten settings were copied');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not write a file in ', $e->getMessage());
        } finally {
            umask($umask);
        }
        $this->assertSame(['run files.makeDirectories'], $host->journal);
    }

    private function apply(RedisHost $host): void
    {
        $model = Mockery::mock(ModelsUser::class)->makePartial();
        $model->username = 'alice';
        $model->shouldReceive('getRedisConfig')->andReturn(['maxmemory' => '64mb', 'maxmemory-policy' => 'allkeys-lru']);

        (new EnvironmentSetup)->applyRedisSettings(new PhpHosting(new ProjectAggregate($host, $model)));
    }
}

final class RedisHost extends System
{
    /** @var list<string> */
    public array $journal = [];

    public function __construct() {}

    public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
    {
        if (is_array($cmd) && LocalRoot::action($cmd) === 'files.copy') {
            [$source, $target] = LocalRoot::values($cmd);
            $this->journal[] = "cp {$target}\n".file_get_contents($source);

            return FakeProcess::ok();
        }
        $this->journal[] = 'run '.(is_array($cmd) ? (LocalRoot::action($cmd) ?? implode(' ', $cmd)) : $cmd);

        return FakeProcess::ok();
    }
}
