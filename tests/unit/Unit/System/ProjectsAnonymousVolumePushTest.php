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
 * A push of a container project's stored data leaves its anonymous volumes
 * out, and says so in its result instead of starting the target on an empty
 * one in silence.
 */
class ProjectsAnonymousVolumePushTest extends TestCase
{
    use InMemoryDatabase;

    public const WARNING = 'Anonymous volume abababababab (db:/var/lib/mysql) holds data that was not copied.';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-push-anon-volume-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/live/project', 0777, true);
        mkdir($this->root.'/stg/project', 0777, true);
        $live = $this->makeUser('live', ['template' => 'dind', 'UID' => 1001, 'GID' => 1001, 'mysql_prefix' => 'live_']);
        $stg = $this->makeUser('stg', ['template' => 'dind', 'UID' => 2002, 'GID' => 2002, 'mysql_prefix' => 'stg_']);
        $stg->staging = $live->id;
        $stg->save();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_push_of_the_stored_data_names_the_anonymous_volumes_it_left_out(): void
    {
        $result = (new Projects($this->system()))->pushToApp('stg', 'live');

        $this->assertSame([self::WARNING], $result->warnings);
    }

    public function test_a_files_only_push_copies_no_volume_and_warns_about_none(): void
    {
        $result = (new Projects($this->system()))->pushToApp('stg', 'live', databases: false);

        $this->assertSame([], $result->warnings);
    }

    private function system(): System
    {
        return new class($this->root) extends System
        {
            public function __construct(private string $root) {}

            public function project(User $model): Project
            {
                return new class($this, $model, $this->root.'/'.$model->username) extends Project
                {
                    public function __construct(System $system, User $model, private string $home)
                    {
                        parent::__construct($system, $model);
                    }

                    public function homeDirPath(): string
                    {
                        return $this->home;
                    }

                    public function copyVolumesForClone(): ?CopyVolumes
                    {
                        return null;
                    }

                    public function anonymousVolumeWarnings(): array
                    {
                        return str_ends_with($this->home, '/stg')
                            ? [ProjectsAnonymousVolumePushTest::WARNING]
                            : [];
                    }

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function start(): void {}

                    public function fixPermissions(): void {}
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => new AnonymousVolumePushProcess(is_dir($path) ? 0 : 1),
                    ['sudo', 'test', '-e'] => new AnonymousVolumePushProcess(file_exists($path) ? 0 : 1),
                    ['sudo', 'test', '-f'] => new AnonymousVolumePushProcess(is_file($path) ? 0 : 1),
                    ['sudo', 'test', '-L'] => new AnonymousVolumePushProcess(is_link($path) ? 0 : 1),
                    ['sudo', 'ls', '-A'] => new AnonymousVolumePushProcess(0, implode("\n", array_diff(scandir($path) ?: [], ['.', '..']))),
                    ['sudo', 'df', '-P'] => new AnonymousVolumePushProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new AnonymousVolumePushProcess($this->change($argv)),
                };
            }

            /**
             * @param  list<string>  $argv
             */
            private function change(array $argv): int
            {
                $args = array_values(array_filter(array_slice($argv, 2), static fn (string $a): bool => ! str_starts_with($a, '-')));
                match ($argv[1] ?? '') {
                    'mkdir' => is_dir($args[0]) || mkdir($args[0], 0777, true),
                    'rm' => array_map(static fn (string $p) => exec('rm -rf '.escapeshellarg($p)), $args),
                    'rsync' => exec('cp -a '.escapeshellarg($args[0].'.').' '.escapeshellarg($args[1])),
                    'mv' => rename($args[0], str_ends_with($args[1], '/') ? $args[1].basename($args[0]) : $args[1]),
                    default => null,
                };

                return 0;
            }
        };
    }
}

class AnonymousVolumePushProcess extends Process
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
