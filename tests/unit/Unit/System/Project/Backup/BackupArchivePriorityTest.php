<?php

namespace Tests\Unit\System\Project\Backup;

use App\Lib\Backup\ArchivePriority;
use App\Support\JobDeadline;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Backup\HomeFiles;
use App\System\Project\Backup\ProjectFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A backup asks the root broker for its `tar` at the priority the settings ask
 * for, and for the plain archive when they ask for nothing.
 */
class BackupArchivePriorityTest extends TestCase
{
    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    /** tar runs in the root broker, so the priority travels as two of its values. */
    public function test_the_home_and_project_tars_run_at_the_configured_priority(): void
    {
        self::store('10', '3');

        $this->assertSame([
            ['/usr/local/lib/panelalpha/pa-root', '--host', 'files.createArchiveAtPriority', '--', '/home/alice', '/tmp/project.tar.gz', '10', '3', 'project'],
            ['/usr/local/lib/panelalpha/pa-root', '--host', 'files.createArchiveAtPriority', '--', '/home/alice', '/tmp/home.tar.gz', '10', '3', '.', './.incoming', './.pre-push'],
        ], $this->tarsOfBothArchives());
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
    public function test_an_engine_that_asks_for_nothing_asks_the_broker_exactly_as_it_always_did(?string $nice, ?string $ioniceClass): void
    {
        self::store($nice, $ioniceClass);

        $this->assertSame([
            ['/usr/local/lib/panelalpha/pa-root', '--host', 'files.createArchive', '--', '/home/alice', '/tmp/project.tar.gz', 'project'],
            ['/usr/local/lib/panelalpha/pa-root', '--host', 'files.createArchive', '--', '/home/alice', '/tmp/home.tar.gz', '.', './.incoming', './.pre-push'],
        ], $this->tarsOfBothArchives());
    }

    private static function store(?string $nice, ?string $ioniceClass): void
    {
        Setting::setRuntimeSettings([
            ArchivePriority::SETTING_NICE => $nice,
            ArchivePriority::SETTING_IONICE_CLASS => $ioniceClass,
        ]);
    }

    /** @return list<list<string>> */
    private function tarsOfBothArchives(): array
    {
        $system = new class extends System {
            /** @var list<list<string>> */
            public array $ran = [];

            public function __construct() {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = (array) $cmd;

                return '';
            }
        };

        $user = new User();
        $user->username = 'alice';
        $project = new ProjectAggregate($system, $user);
        $deadline = JobDeadline::startingNow();

        (new ProjectFiles($project, $deadline))->archive('/tmp/project.tar.gz');
        (new HomeFiles($project, $deadline))->archive('/tmp/home.tar.gz');

        return $system->ran;
    }
}
