<?php

namespace Tests\Integration\System\Project;

use App\Lib\Backup\ArchivePriority;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;

/**
 * The volume helper container archives and restores at the priority
 * the settings ask for. `nice` and `ionice` go inside the container, in front
 * of the program it runs, not in front of the request that starts it.
 */
class VolumeArchivePriorityTest extends TestCase
{
    private const HOME = '/home/alice';

    private const SCRATCH = self::HOME.'/.panelalpha-backup-scratch';

    /** @var list<list<string>> */
    private array $ran = [];

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_volume_archive_runs_at_the_configured_priority(): void
    {
        self::store('10', '3');
        $this->archive()->tarVolume('project_data', self::HOME.'/data.tar.gz');

        $this->assertContains(
            self::archiveRequest(['nice', '-n', '10', 'ionice', '-c', '3', 'tar', '-C', '/data', '-czf', '/out/data.tar.gz', '.']),
            $this->ran,
        );
    }

    public function test_a_volume_restore_runs_at_the_configured_priority(): void
    {
        self::store('10', '3');
        $this->archive()->restoreVolume('project_data', self::HOME.'/data.tar.gz');

        $this->assertContains(
            self::restoreRequest(['nice', '-n', '10', 'ionice', '-c', '3', 'sh', '-c', VolumeArchive::volumeRestoreScript()]),
            $this->ran,
        );
    }

    /**
     * Every way an engine can ask for nothing: never set, empty, and values
     * that are ignored.
     *
     * @return array<string, array{?string, ?string}>
     */
    public static function unsetPriorities(): array
    {
        return [
            'never set' => [null, null],
            'empty' => ['', ''],
            'values out of range' => ['20', '1'],
            'words' => ['low', 'idle'],
        ];
    }

    #[DataProvider('unsetPriorities')]
    public function test_an_engine_that_asks_for_nothing_archives_exactly_as_it_always_did(?string $nice, ?string $ioniceClass): void
    {
        self::store($nice, $ioniceClass);
        $this->archive()->tarVolume('project_data', self::HOME.'/data.tar.gz');

        $this->assertContains(self::archiveRequest(['tar', '-C', '/data', '-czf', '/out/data.tar.gz', '.']), $this->ran);
    }

    #[DataProvider('unsetPriorities')]
    public function test_an_engine_that_asks_for_nothing_restores_exactly_as_it_always_did(?string $nice, ?string $ioniceClass): void
    {
        self::store($nice, $ioniceClass);
        $this->archive()->restoreVolume('project_data', self::HOME.'/data.tar.gz');

        $this->assertContains(self::restoreRequest(['sh', '-c', VolumeArchive::volumeRestoreScript()]), $this->ran);
    }

    /** @param list<string> $argv */
    public function note(array $argv): void
    {
        $this->ran[] = $argv;
    }

    private static function store(?string $nice, ?string $ioniceClass): void
    {
        Setting::setRuntimeSettings([
            ArchivePriority::SETTING_NICE => $nice,
            ArchivePriority::SETTING_IONICE_CLASS => $ioniceClass,
        ]);
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function archiveRequest(array $argv): array
    {
        return Privileged::accountDocker('alice')->run('alpine:3.20', [['project_data', '/data', 'ro'], [self::SCRATCH, '/out', 'rw']], $argv);
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function restoreRequest(array $argv): array
    {
        return Privileged::accountDocker('alice')->run('alpine:3.20', [['project_data', '/data', 'rw'], [self::SCRATCH.'/data.tar.gz', '/archive', 'ro']], $argv);
    }

    private function archive(): VolumeArchive
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'deploy_strategy' => 'express',
            'UID' => 1000,
            'GID' => 1000,
        ]);

        return new VolumeArchive(new Dind(new ProjectAggregate($this->system(), $model)));
    }

    private function system(): System
    {
        return new class ($this) extends System {
            public function __construct(private readonly VolumeArchivePriorityTest $test) {}

            public function projectHomeDirPath(string $username): string
            {
                return '/home/'.$username;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->note((array) $cmd);

                return '';
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->test->note((array) $cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->test->note((array) $cmd);

                return FakeProcess::ok();
            }
        };
    }
}
