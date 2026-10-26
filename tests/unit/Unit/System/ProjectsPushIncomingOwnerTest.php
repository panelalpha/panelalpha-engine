<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind\CopyVolumes;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The staged copy of the source home is rsynced with the source account's owner,
 * so it has to belong to the target before anything runs as the target on it.
 */
class ProjectsPushIncomingOwnerTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-push-incoming-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/live', 0777, true);
        mkdir($this->root . '/stg', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_incoming_home_belongs_to_the_target_before_its_volumes_are_prepared(): void
    {
        $this->dindStagingPair();
        $record = new \ArrayObject();

        (new Projects($this->system($record)))->pushToApp('stg', 'live');

        $log = $record->getArrayCopy();
        $rsync = $this->firstIndex($log, fn (string $line): bool => str_starts_with($line, 'sudo rsync -a --delete'));
        $handOver = array_search($this->expectedHandOver(), $log, true);
        $volumeStep = $this->firstIndex($log, fn (string $line): bool => $line === 'volumes live', $rsync);

        $this->assertIsInt($handOver, 'the incoming home is never handed to the target');
        $this->assertGreaterThan($rsync, $handOver);
        $this->assertLessThan($volumeStep, $handOver);
        $this->assertSame('completed', User::findByUsernameOrFail('live')->getDetails()['async_status']['push'] ?? null);
    }

    private function expectedHandOver(): string
    {
        $incoming = $this->root . '/live/.incoming';

        return sprintf(
            'sudo find %s -mindepth 0 -not \( -path %s -prune \) -not \( -path %s -prune \) -exec chown -h 1001:1001 {} +',
            escapeshellarg($incoming),
            escapeshellarg($incoming . '/.pre-push-db'),
            escapeshellarg($incoming . '/docker/*'),
        );
    }

    /**
     * @param list<string> $log
     */
    private function firstIndex(array $log, callable $matches, int $after = -1): int
    {
        foreach ($log as $index => $line) {
            if ($index > $after && $matches($line)) {
                return $index;
            }
        }

        $this->fail('expected step never ran: ' . implode("\n", $log));
    }

    private function dindStagingPair(): void
    {
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'mysql_prefix' => 'live_']);
        $stg = $this->makeUser('stg', ['template' => 'dind', 'UID' => 2002, 'GID' => 2002, 'mysql_prefix' => 'stg_']);
        $stg->staging = $live->id;
        $stg->save();
    }

    private function system(\ArrayObject $record): System
    {
        return new class ($record, $this->root) extends System {
            public function __construct(private \ArrayObject $record, private string $root)
            {
            }

            public function project(User $model): Project
            {
                return new class ($this, $model, $this->root . '/' . $model->username, $this->record) extends Project {
                    public function __construct(
                        System $system,
                        User $model,
                        private string $home,
                        private \ArrayObject $record,
                    ) {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        $this->record[] = 'volumes ' . $this->username();

                        return null;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void
                    {
                    }
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $this->record[] = implode(' ', $argv);

                return $this->answer($argv);
            }

            /**
             * @param list<string> $argv
             */
            private function answer(array $argv): Process
            {
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => new PushIncomingProcess(is_dir($path) ? 0 : 1),
                    ['sudo', 'test', '-e'] => new PushIncomingProcess(file_exists($path) ? 0 : 1),
                    ['sudo', 'test', '-L'] => new PushIncomingProcess(is_link($path) ? 0 : 1),
                    ['sudo', 'mkdir', '-p'] => new PushIncomingProcess((is_dir($path) || mkdir($path, 0777, true)) ? 0 : 1),
                    ['sudo', 'rm', '-rf'] => new PushIncomingProcess($this->remove(array_slice($argv, 3))),
                    ['sudo', 'rmdir', $path] => new PushIncomingProcess(rmdir($path) ? 0 : 1),
                    ['sudo', 'df', '-P'] => new PushIncomingProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new PushIncomingProcess(0),
                };
            }

            /**
             * @param list<string> $paths
             */
            private function remove(array $paths): int
            {
                foreach ($paths as $path) {
                    exec('rm -rf ' . escapeshellarg($path));
                }

                return 0;
            }
        };
    }
}

class PushIncomingProcess extends Process
{
    public function __construct(private int $code, private string $output = '')
    {
        parent::__construct(['true']);
    }

    public function isSuccessful(): bool
    {
        return $this->code === 0;
    }

    public function getExitCode(): ?int
    {
        return $this->code;
    }

    public function getErrorOutput(): string
    {
        return '';
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
