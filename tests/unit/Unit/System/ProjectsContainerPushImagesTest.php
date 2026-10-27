<?php

namespace Tests\Unit\System;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Projects;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A container push gets the images the pushed run file names (a Railpack
 * build, a base image transfer) from a first copy of the source's home taken
 * while both apps still run; they are stopped only for the copy that has to be
 * consistent. The file and volume commands run for real in a scratch
 * directory; the account's inner compose and the image work are recorded.
 */
class ProjectsContainerPushImagesTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    private object $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
        $this->root = sys_get_temp_dir().'/pa-push-dind-images-'.bin2hex(random_bytes(6));
        $this->record = (object) ['events' => [], 'homeRsyncExits' => []];
        $this->containerHome('live', 'live code');
        $this->containerHome('stg', 'staging code');
        $live = $this->containerProject('live', 1001);
        $stg = $this->containerProject('stg', 2002);
        $stg->staging = $live->id;
        $stg->save();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_images_are_got_before_either_app_is_stopped(): void
    {
        $this->push();

        $this->assertContains('images '.$this->root.'/live/.incoming/project/'.EngineArtifacts::RUN_COMPOSE, $this->record->events);
        $this->assertSame(['rsync home', 'images', 'stop stg', 'stop live', 'rsync home'], $this->milestones($this->record->events));
        $this->assertSame('staging code', file_get_contents($this->root.'/live/project/index.js'));
    }

    public function test_a_file_the_running_source_removes_during_the_first_copy_does_not_fail_the_push(): void
    {
        $this->record->homeRsyncExits = [Project::RSYNC_VANISHED_FILES];

        $this->push();

        $this->assertSame('staging code', file_get_contents($this->root.'/live/project/index.js'));
    }

    public function test_the_copy_taken_once_the_apps_are_stopped_must_be_complete(): void
    {
        $this->record->homeRsyncExits = [0, Project::RSYNC_VANISHED_FILES];

        try {
            $this->push();
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to rsync source home into .incoming', $e->getMessage());
        }
        $this->assertSame('live code', file_get_contents($this->root.'/live/project/index.js'));
    }

    private function push(): void
    {
        (new Projects($this->system()))->pushToApp('stg', 'live');
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    private function milestones(array $events): array
    {
        $milestones = [];
        foreach ($events as $event) {
            if (in_array($event, ['rsync home', 'stop stg', 'stop live'], true)) {
                $milestones[] = $event;
            } elseif (str_starts_with($event, 'images ')) {
                $milestones[] = 'images';
            }
        }

        return $milestones;
    }

    private function containerProject(string $username, int $uid): User
    {
        return $this->makeUser($username, [
            'template' => 'dind',
            'deploy_strategy' => Strategies::RAILPACK,
            'UID' => $uid,
            'GID' => $uid,
            'mysql_prefix' => $username.'_',
        ]);
    }

    private function containerHome(string $username, string $code): void
    {
        mkdir($this->root."/{$username}/project", 0777, true);
        file_put_contents($this->root."/{$username}/project/index.js", $code);
    }

    private function system(): System
    {
        return new class($this->root, $this->record) extends System
        {
            public function __construct(private string $root, private object $record) {}

            public function project(User $model): Project
            {
                return new class($this, $model, $this->root.'/'.$model->username, $this->record) extends Project
                {
                    public function __construct(System $system, User $model, private string $home, private object $record)
                    {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function fixPermissions(): void {}

                    public function provideCopiedImages(?string $path = null): void
                    {
                        $this->record->events[] = 'images '.$path;
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void {}
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return $this->runProcess($cmd)->getOutput();
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return is_array($cmd) ? $this->apply(array_values($cmd)) : new ContainerPushImagesProcess(0);
            }

            /**
             * @param  list<string>  $argv
             */
            private function apply(array $argv): Process
            {
                if (in_array('dind', $argv, true)) {
                    return $this->innerCompose($argv);
                }
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => new ContainerPushImagesProcess(is_dir($path) ? 0 : 1),
                    ['sudo', 'test', '-e'] => new ContainerPushImagesProcess(file_exists($path) ? 0 : 1),
                    ['sudo', 'test', '-f'] => new ContainerPushImagesProcess(is_file($path) ? 0 : 1),
                    ['sudo', 'test', '-L'] => new ContainerPushImagesProcess(is_link($path) ? 0 : 1),
                    ['sudo', 'ls', '-A'], ['sudo', 'ls', '-1'] => new ContainerPushImagesProcess(0, implode("\n", array_diff(scandir($path) ?: [], ['.', '..']))),
                    ['sudo', 'df', '-P'] => new ContainerPushImagesProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new ContainerPushImagesProcess($this->change($argv)),
                };
            }

            /**
             * @param  list<string>  $argv
             */
            private function innerCompose(array $argv): Process
            {
                $inner = (string) end($argv);
                $username = $argv[array_search('/bin/bash', $argv, true) + 1] ?? '';
                if (str_contains($inner, "'stop'")) {
                    $this->record->events[] = "stop {$username}";
                }

                return new ContainerPushImagesProcess(0);
            }

            /**
             * @param  list<string>  $argv
             */
            private function change(array $argv): int
            {
                $args = array_values(array_filter(array_slice($argv, 2), static fn (string $a): bool => ! str_starts_with($a, '-')));
                if (($argv[1] ?? '') === 'rsync' && str_ends_with($args[1], '/.incoming/')) {
                    return $this->rsyncHome($argv, $args[0], $args[1]);
                }
                match ($argv[1] ?? '') {
                    'mkdir' => is_dir($args[0]) || mkdir($args[0], 0777, true),
                    'rm' => array_map(static fn (string $p) => exec('rm -rf '.escapeshellarg($p)), $args),
                    'rmdir' => rmdir($args[0]),
                    'mv' => rename($args[0], str_ends_with($args[1], '/') ? $args[1].basename($args[0]) : $args[1]),
                    default => null,
                };

                return 0;
            }

            /**
             * @param  list<string>  $argv
             */
            private function rsyncHome(array $argv, string $from, string $to): int
            {
                $this->record->events[] = 'rsync home';
                $excluded = array_map(
                    static fn (string $arg): string => rtrim(substr($arg, strlen('--exclude=')), '/'),
                    array_filter($argv, static fn (string $arg): bool => str_starts_with($arg, '--exclude=')),
                );
                foreach (array_diff(scandir($from) ?: [], ['.', '..', ...$excluded]) as $entry) {
                    exec('cp -a '.escapeshellarg(rtrim($from, '/').'/'.$entry).' '.escapeshellarg($to));
                }

                return array_shift($this->record->homeRsyncExits) ?? 0;
            }
        };
    }
}

class ContainerPushImagesProcess extends Process
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
