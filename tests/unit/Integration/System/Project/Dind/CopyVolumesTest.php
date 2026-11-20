<?php

namespace Tests\Integration\System\Project\Dind;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\ContainerOperations;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\VolumeArchive;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Clone and push copy each named volume through the accounts' own daemons, as a backup does; every request is checked
 * as the broker checks it, so one the broker would refuse fails the test.
 */
class CopyVolumesTest extends TestCase
{
    private const STAGING = '/home/bob/.panelalpha-volumes';

    private const INCOMING = self::STAGING.'/project_db/_data.incoming';

    private const PRE_PUSH = self::STAGING.'/project_db/_data.pre-push';

    /** @var array<string, array<string, string>> */
    private array $volumes = [];

    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    private array $dirs = [];

    /** @var list<string> */
    private array $asked = [];

    /** @var array<string, string> the start of a request => the error it fails with */
    private array $fails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->volumes = [
            'alice' => ['project_db' => 'alice db', 'project_cache' => 'alice cache', str_repeat('ab', 32) => 'image files'],
            'bob' => ['project_db' => 'bob db'],
        ];
    }

    public function test_a_clone_puts_each_named_volume_the_target_has_in_place_through_both_daemons(): void
    {
        $log = Log::spy();

        $this->assertSame(1, $this->account('bob')->copyVolumeDataFrom($this->account('alice')));

        $this->assertSame('alice db', $this->volumes['bob']['project_db']);
        $this->assertArrayNotHasKey('project_cache', $this->volumes['bob']);
        $this->assertSame([], $this->files);
        $this->assertSame([], array_values(array_filter($this->dirs, static fn (string $dir): bool => str_starts_with($dir, '/home/bob/.'))));
        $this->assertSame([
            'account-docker.run alice alpine:3.20 2 project_db /data ro /home/alice/.panelalpha-backup-scratch /out rw tar -C /data -czf /out/_data.incoming .',
            'account-docker.run bob alpine:3.20 2 project_db /data rw '.self::INCOMING.' /archive ro sh -c '.VolumeArchive::volumeReplaceScript(),
        ], $this->runs());
        $this->assertSame([], preg_grep('~/docker/volumes~', $this->asked));
        $log->shouldHaveReceived('warning')->once()->with('Skipping volume data copy; dest volume missing', [
            'volume' => 'project_cache',
            'dest' => 'bob',
        ]);
    }

    public function test_a_source_without_named_volumes_copies_nothing_and_asks_the_target_s_daemon_nothing(): void
    {
        $this->volumes['alice'] = [str_repeat('ab', 32) => 'image files'];

        $this->assertSame(0, $this->account('bob')->copyVolumeDataFrom($this->account('alice')));

        $this->assertSame(['account-docker.listVolumes alice'], array_values(preg_grep('/^account-docker\./', $this->asked)));
        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
    }

    public function test_the_count_is_the_named_volumes_the_daemon_lists(): void
    {
        $this->assertSame(2, $this->account('alice')->countVolumeDataDirs());
        $this->assertSame(['account-docker.listVolumes alice'], $this->asked);
    }

    public function test_a_count_the_daemon_cannot_give_is_none(): void
    {
        $this->fails['account-docker.listVolumes alice'] = 'container alice is not running';

        $this->assertSame(0, $this->account('alice')->countVolumeDataDirs());
    }

    public function test_a_daemon_that_cannot_list_its_volumes_fails_the_copy(): void
    {
        $this->fails['account-docker.listVolumes alice'] = 'container alice is not running';

        $this->expectExceptionMessage('container alice is not running');

        $this->account('bob')->copyVolumeDataFrom($this->account('alice'));
    }

    public function test_a_staging_directory_that_cannot_be_made_stops_the_copy(): void
    {
        $this->fails['files.makeDirectories '.self::STAGING] = 'mkdir: no space left';

        $this->expectExceptionMessage("Failed to create incoming volume directory for 'project_db': mkdir: no space left");

        $this->account('bob')->copyVolumeDataToIncoming($this->account('alice'));
    }

    public function test_a_failed_archive_stops_the_copy(): void
    {
        $this->fails['account-docker.run alice'] = 'tar: read error';

        $this->expectExceptionMessage("Failed to rsync volume data for 'project_db': tar: read error");

        $this->account('bob')->copyVolumeDataFrom($this->account('alice'));
    }

    public function test_a_failed_extract_stops_the_clone_and_leaves_nothing_staged(): void
    {
        $this->fails['account-docker.run bob'] = 'tar: short read';

        try {
            $this->account('bob')->copyVolumeDataFrom($this->account('alice'));
            $this->fail('the clone went on');
        } catch (\RuntimeException $e) {
            $this->assertSame("Failed to rsync volume data for 'project_db': tar: short read", $e->getMessage());
        }
        $this->assertSame([], $this->files);
        $this->assertNotContains(self::STAGING, $this->dirs);
    }

    public function test_a_push_stages_each_volume_beside_the_target_s_home_and_leaves_its_data(): void
    {
        $this->assertSame(1, $this->account('bob')->copyVolumeDataToIncoming($this->account('alice')));

        $this->assertSame([self::INCOMING => 'alice db'], $this->files);
        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
    }

    public function test_a_swap_puts_the_staged_data_in_place_and_archives_the_target_s_aside(): void
    {
        $this->stagedPush();

        $this->account('bob')->swapIncomingVolumes();

        $this->assertSame('alice db', $this->volumes['bob']['project_db']);
        $this->assertSame([self::PRE_PUSH => 'bob db'], $this->files);
        $this->assertSame([
            'account-docker.run bob alpine:3.20 2 project_db /data ro /home/bob/.panelalpha-backup-scratch /out rw tar -C /data -czf /out/_data.pre-push .',
            'account-docker.run bob alpine:3.20 2 project_db /data rw '.self::INCOMING.' /archive ro sh -c '.VolumeArchive::volumeReplaceScript(),
        ], $this->runs());
    }

    public function test_a_swap_that_cannot_archive_the_target_s_data_changes_nothing(): void
    {
        $this->stagedPush();
        $this->fails['account-docker.run bob alpine:3.20 2 project_db /data ro'] = 'tar: no space left';

        try {
            $this->account('bob')->swapIncomingVolumes();
            $this->fail('the swap went on');
        } catch (\RuntimeException $e) {
            $this->assertSame("Failed to move volume 'project_db' aside before swap: tar: no space left", $e->getMessage());
        }
        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
    }

    public function test_a_swap_that_cannot_put_the_data_in_place_is_undone_by_the_restore(): void
    {
        $this->stagedPush();
        $this->fails['account-docker.run bob alpine:3.20 2 project_db /data rw '.self::INCOMING] = 'tar: short read';
        try {
            $this->account('bob')->swapIncomingVolumes();
            $this->fail('the swap went on');
        } catch (\RuntimeException $e) {
            $this->assertSame("Failed to swap incoming volume data for 'project_db': tar: short read", $e->getMessage());
        }
        $this->volumes['bob']['project_db'] = 'half of alice db';

        $this->account('bob')->restoreVolumeBackups();

        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
        $this->assertSame([], $this->files);
    }

    public function test_a_restore_puts_back_what_a_swap_archived_aside(): void
    {
        $this->stagedPush();
        $this->account('bob')->swapIncomingVolumes();

        $this->account('bob')->restoreVolumeBackups();

        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
        $this->assertSame([], $this->files);
        $this->assertNotContains(self::STAGING, $this->dirs);
    }

    public function test_a_restore_drops_data_that_was_only_staged(): void
    {
        $this->stagedPush();

        $this->account('bob')->restoreVolumeBackups();

        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
        $this->assertSame([], $this->files);
        $this->assertSame([], $this->runs());
    }

    public function test_a_restore_that_fails_keeps_what_was_archived_aside(): void
    {
        $this->stagedPush();
        $this->account('bob')->swapIncomingVolumes();
        $this->fails['account-docker.run bob'] = 'tar: no space left';

        try {
            $this->account('bob')->restoreVolumeBackups();
            $this->fail('the restore went on');
        } catch (\RuntimeException $e) {
            $this->assertSame("Failed to restore volume data for 'project_db': tar: no space left", $e->getMessage());
        }
        $this->assertSame([self::PRE_PUSH => 'bob db'], $this->files);
    }

    public function test_a_restore_tries_every_volume_and_keeps_what_it_could_not_put_back(): void
    {
        $this->volumes['alice']['project_files'] = 'alice files';
        $this->volumes['bob']['project_files'] = 'bob files';
        $this->stagedPush();
        $this->account('bob')->swapIncomingVolumes();
        $this->fails['account-docker.run bob alpine:3.20 2 project_db /data rw '.self::PRE_PUSH] = 'tar: no space left';

        try {
            $this->account('bob')->restoreVolumeBackups();
            $this->fail('the restore went on');
        } catch (\RuntimeException $e) {
            $this->assertSame("Failed to restore volume data for 'project_db': tar: no space left", $e->getMessage());
        }
        $this->assertSame('bob files', $this->volumes['bob']['project_files']);
        $this->assertSame([self::PRE_PUSH => 'bob db'], $this->files);
    }

    public function test_discard_removes_what_the_push_kept_aside(): void
    {
        $this->stagedPush();
        $this->account('bob')->swapIncomingVolumes();

        $this->account('bob')->discardVolumeBackups();

        $this->assertSame('alice db', $this->volumes['bob']['project_db']);
        $this->assertSame([], $this->files);
        $this->assertNotContains(self::STAGING, $this->dirs);
    }

    public function test_a_backup_that_cannot_be_removed_is_named(): void
    {
        $this->stagedPush();
        $this->account('bob')->swapIncomingVolumes();
        $this->fails['files.removeIfExists '.self::PRE_PUSH] = 'rm: busy';

        $this->expectExceptionMessage("Failed to remove volume backup 'project_db/_data.pre-push': rm: busy");

        $this->account('bob')->discardVolumeBackups();
    }

    public function test_nothing_staged_is_nothing_to_swap_restore_or_discard(): void
    {
        $bob = $this->account('bob');

        $bob->swapIncomingVolumes();
        $bob->restoreVolumeBackups();
        $bob->discardVolumeBackups();

        $this->assertSame(['files.isDirectory '.self::STAGING], array_values(array_unique($this->asked)));
    }

    public function test_a_staged_volume_with_no_incoming_data_and_the_daemon_s_metadata_are_not_swapped(): void
    {
        $this->made(self::STAGING.'/project_db');
        $this->files[self::STAGING.'/metadata.db'] = 'bolt';

        $this->account('bob')->swapIncomingVolumes();

        $this->assertContains('files.isFile '.self::INCOMING, $this->asked);
        $this->assertNotContains('files.isFile '.self::STAGING.'/metadata.db/_data.incoming', $this->asked);
        $this->assertSame([], $this->runs());
        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
    }

    public function test_a_staging_directory_that_cannot_be_listed_swaps_nothing(): void
    {
        $this->made(self::STAGING.'/project_db');
        $this->files[self::INCOMING] = 'alice db';
        $this->fails['files.listNames'] = 'Permission denied';

        $this->account('bob')->swapIncomingVolumes();

        $this->assertSame([], $this->runs());
        $this->assertSame('bob db', $this->volumes['bob']['project_db']);
        $this->assertArrayHasKey(self::INCOMING, $this->files);
    }

    private function stagedPush(): void
    {
        $this->account('bob')->copyVolumeDataToIncoming($this->account('alice'));
        $this->asked = [];
    }

    /** @return list<string> */
    private function runs(): array
    {
        return array_values(array_filter($this->asked, static fn (string $line): bool => str_starts_with($line, 'account-docker.run ')));
    }

    private function account(string $name): CopyVolumes
    {
        $system = $this->createStub(System::class);
        $system->method('runProcess')->willReturnCallback(fn (array $request): Process => $this->answered($request));
        $system->method('exec')->willReturnCallback(function (array $request): string {
            $process = $this->answered($request);
            if (! $process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }

            return $process->getOutput();
        });
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($name);
        $dind->method('homeDirPath')->willReturn('/home/'.$name);
        $dind->method('system')->willReturn($system);
        $dind->method('accountDaemon')->willReturnCallback(static fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));

        return new CopyVolumes($dind, new ContainerOperations($dind, new ShellOperations($dind)), new Paths($dind));
    }

    /** @param  list<string>  $request */
    private function answered(array $request): Process
    {
        LocalRoot::checked($request);
        $action = (string) LocalRoot::action($request);
        $values = LocalRoot::values($request);
        $line = trim($action.' '.implode(' ', $values));
        $this->asked[] = $line;
        foreach ($this->fails as $start => $error) {
            if (str_starts_with($line, $start)) {
                return FakeProcess::failed($error);
            }
        }

        return match ($action) {
            'account-docker.listVolumes' => FakeProcess::ok((string) json_encode(array_map(
                static fn (string $volume): array => ['Name' => $volume, 'Driver' => 'local'],
                array_keys($this->volumes[$values[0]] ?? []),
            ))),
            'account-docker.run' => $this->ran($values),
            'files.isDirectory' => in_array($values[0], $this->dirs, true) ? FakeProcess::ok() : FakeProcess::failed(),
            'files.isFile' => array_key_exists($values[0], $this->files) ? FakeProcess::ok() : FakeProcess::failed(),
            'files.exists' => in_array($values[0], $this->dirs, true) || array_key_exists($values[0], $this->files) ? FakeProcess::ok() : FakeProcess::failed(),
            'files.listNames' => FakeProcess::ok(implode("\n", $this->entriesOf($values[0]))),
            'files.makeDirectories' => $this->made($values[0]),
            'files.moveOverwriting' => $this->moved($values[0], $values[1]),
            'files.removeIfExists' => $this->removed($values[0], false),
            'files.removeTree' => $this->removed($values[0], true),
            default => FakeProcess::ok(),
        };
    }

    /** @param  list<string>  $values */
    private function ran(array $values): Process
    {
        [$account, , $count] = $values;
        $mounts = [];
        for ($at = 3; $at < 3 + 3 * (int) $count; $at += 3) {
            $mounts[$values[$at + 1]] = $values[$at];
        }
        $argv = array_slice($values, 3 + 3 * (int) $count);
        $volume = $mounts['/data'];
        if ($argv[0] === 'tar') {
            $this->files[$mounts['/out'].'/'.basename($argv[4])] = $this->volumes[$account][$volume];
        } else {
            $this->volumes[$account][$volume] = $this->files[$mounts['/archive']];
        }

        return FakeProcess::ok();
    }

    private function made(string $dir): Process
    {
        for ($at = $dir; $at !== '/home' && $at !== '/'; $at = dirname($at)) {
            $this->dirs = array_values(array_unique([...$this->dirs, $at]));
        }

        return FakeProcess::ok();
    }

    private function moved(string $from, string $to): Process
    {
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);

        return FakeProcess::ok();
    }

    private function removed(string $path, bool $tree): Process
    {
        $under = static fn (string $at): bool => $at === $path || ($tree && str_starts_with($at, $path.'/'));
        $this->files = array_filter($this->files, static fn (string $at): bool => ! $under($at), ARRAY_FILTER_USE_KEY);
        $this->dirs = array_values(array_filter($this->dirs, static fn (string $at): bool => ! $under($at)));

        return FakeProcess::ok();
    }

    /** @return list<string> */
    private function entriesOf(string $dir): array
    {
        $entries = [];
        foreach ([...$this->dirs, ...array_keys($this->files)] as $path) {
            if (dirname($path) === $dir) {
                $entries[] = basename($path);
            }
        }

        return array_values(array_unique($entries));
    }
}
