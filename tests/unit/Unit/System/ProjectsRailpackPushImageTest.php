<?php

namespace Tests\Unit\System;

use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\HeldImage;
use App\System\Project\Dind\Inner\ImageSeeding;
use App\System\Project\Dind\InnerDocker;
use App\System\Project\Dind\ShellOperations;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * A push builds the Railpack tag its run file names in the target's account.
 * When the target already ran an image under that tag, the push holds it
 * aside first: a failed push puts it back before the target starts again,
 * and a push that went through lets go of it.
 */
class ProjectsRailpackPushImageTest extends TestCase
{
    use InMemoryDatabase;

    private const IMAGE = 'panelalpha-live-app:latest';

    private string $root;

    /** @var list<string> */
    private array $events = [];

    private bool $holdingTagStays = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir().'/pa-push-held-image-'.bin2hex(random_bytes(6));
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

    public function test_a_failed_push_puts_the_targets_own_image_back_before_starting_it_again(): void
    {
        try {
            (new Projects($this->system(failStart: true)))->pushToApp('stg', 'live');
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('start exploded', $e->getMessage());
        }

        $this->assertSame(
            [
                'tag panelalpha-live-app:latest panelalpha-live-app:pre-push',
                'provide images',
                'start',
                'tag panelalpha-live-app:pre-push panelalpha-live-app:latest',
                'rmi -f panelalpha-live-app:pre-push',
                'start',
            ],
            $this->events
        );
    }

    public function test_a_holding_tag_that_cannot_be_dropped_after_the_put_back_does_not_fail_the_restore(): void
    {
        $this->holdingTagStays = true;

        try {
            (new Projects($this->system(failStart: true)))->pushToApp('stg', 'live');
            $this->fail('the push should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('start exploded', $e->getMessage());
        }

        $this->assertSame(
            [
                'tag panelalpha-live-app:latest panelalpha-live-app:pre-push',
                'provide images',
                'start',
                'tag panelalpha-live-app:pre-push panelalpha-live-app:latest',
                'rmi -f panelalpha-live-app:pre-push',
                'start',
            ],
            $this->events
        );
    }

    public function test_a_push_that_went_through_lets_go_of_the_held_image(): void
    {
        (new Projects($this->system(failStart: false)))->pushToApp('stg', 'live');

        $this->assertSame(
            [
                'tag panelalpha-live-app:latest panelalpha-live-app:pre-push',
                'provide images',
                'start',
                'rmi -f panelalpha-live-app:pre-push',
            ],
            $this->events
        );
    }

    private function heldImage(): ?HeldImage
    {
        $seeding = $this->createStub(ImageSeeding::class);
        $seeding->method('hasImage')->willReturn(true);
        $inner = $this->createStub(InnerDocker::class);
        $inner->method('seeding')->willReturn($seeding);
        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($this->innerDockerSystem());
        $dind->method('innerDocker')->willReturn($inner);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return HeldImage::hold($dind, self::IMAGE);
    }

    private function innerDockerSystem(): System
    {
        return new class($this->recorder()) extends System
        {
            public function __construct(private \Closure $record) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array) $cmd;
                ($this->record)(implode(' ', array_slice($cmd, (int) array_search('dind', $cmd, true) + 2)));

                return '';
            }
        };
    }

    private function recorder(): \Closure
    {
        return function (string $event): void {
            $this->events[] = $event;
            if ($this->holdingTagStays && str_starts_with($event, 'rmi ')) {
                throw new \RuntimeException('image is being used');
            }
        };
    }

    private function system(bool $failStart): System
    {
        return new class($this->root, $failStart, $this->recorder(), $this->heldImage(...)) extends System
        {
            public function __construct(
                private string $root,
                private bool $failStart,
                private \Closure $record,
                private \Closure $hold,
            ) {}

            public function project(User $model): Project
            {
                return new class($this, $model, $this->root.'/'.$model->username, $this->failStart, $this->record, $this->hold) extends Project
                {
                    public function __construct(
                        System $system,
                        User $model,
                        private string $home,
                        private bool $failStart,
                        private \Closure $record,
                        private \Closure $hold,
                    ) {
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

                    public function hasUserApp(): bool
                    {
                        return false;
                    }

                    public function holdCopiedImage(string $path): ?HeldImage
                    {
                        return ($this->hold)();
                    }

                    public function provideCopiedImages(?string $path = null): void
                    {
                        ($this->record)('provide images');
                    }

                    public function start(): void
                    {
                        ($this->record)('start');
                        if ($this->failStart) {
                            $this->failStart = false;
                            throw new \RuntimeException('start exploded');
                        }
                    }

                    public function fixPermissions(): void {}
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $path = (string) end($argv);

                return match (array_slice($argv, 0, 3)) {
                    ['sudo', 'test', '-d'] => new HeldImagePushProcess(is_dir($path) ? 0 : 1),
                    ['sudo', 'test', '-e'] => new HeldImagePushProcess(file_exists($path) ? 0 : 1),
                    ['sudo', 'test', '-f'] => new HeldImagePushProcess(is_file($path) ? 0 : 1),
                    ['sudo', 'test', '-L'] => new HeldImagePushProcess(is_link($path) ? 0 : 1),
                    ['sudo', 'ls', '-A'] => new HeldImagePushProcess(0, implode("\n", array_diff(scandir($path) ?: [], ['.', '..']))),
                    ['sudo', 'df', '-P'] => new HeldImagePushProcess(0, "Filesystem 1024-blocks Used Available Capacity Mounted\n/dev/sda1 1 1 999999999 1% /"),
                    default => new HeldImagePushProcess($this->change($argv)),
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

class HeldImagePushProcess extends Process
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
