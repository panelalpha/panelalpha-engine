<?php

namespace Tests\Integration\System;

use App\System;
use App\System\Privileged\Root;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** The System facade's own answers and what it hands to the host, on a recording host. */
class SystemDelegationTest extends TestCase
{
    private string $homes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homes = sys_get_temp_dir() . '/pa-system-homes-' . bin2hex(random_bytes(6));
        mkdir($this->homes, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->homes));
        parent::tearDown();
    }

    public function test_file_and_directory_checks_follow_the_host_answer(): void
    {
        $system = new RecordingSystem();
        $this->assertTrue($system->fileExists('/etc/app.conf'));
        $this->assertTrue($system->directoryExists('/etc/app'));

        $system->exitCode = 1;
        $this->assertFalse($system->fileExists('/etc/app.conf'));
        $this->assertFalse($system->directoryExists('/etc/app'));
        $this->assertSame(['files.isFile', 'files.isDirectory', 'files.isFile', 'files.isDirectory'], $this->actions($system));
    }

    public function test_a_file_outside_the_homes_is_read_through_a_root_copy(): void
    {
        $system = new RecordingSystem();

        $this->assertSame('', $system->fileGetContents('/etc/app.conf'));
        $this->assertContains('files.copy', $this->actions($system));
        $this->assertSame('/etc/app.conf', LocalRoot::values($this->commandFor($system, 'files.copy'))[0]);
    }

    public function test_writing_a_file_copies_a_staged_file_onto_the_path(): void
    {
        $system = new RecordingSystem();

        $system->filePutContents('/etc/app/app.conf', "key=value\n");

        $copy = LocalRoot::values($this->commandFor($system, 'files.copy'));
        $this->assertSame('/etc/app/app.conf', $copy[1]);
        $this->assertFileDoesNotExist($copy[0]);
    }

    public function test_no_change_webserver_or_update_run_reads_as_none_and_not_running(): void
    {
        $system = new RecordingSystem();
        $system->exitCode = 1;

        $this->assertNull($system->getLatestChangeWebserverInfo());
        $this->assertFalse($system->isChangeWebserverScriptRunning());
        $this->assertNull($system->getLatestUpdateInfo());
        $this->assertFalse($system->isUpdateScriptRunning());
    }

    public function test_a_change_webserver_script_the_host_refuses_raises_its_output(): void
    {
        $system = new RecordingSystem();
        $system->exitCode = 1;
        $system->output = 'webserver busy';

        $this->expectExceptionMessage('Failed to run change webserver script: webserver busy (exit code 1)');

        $system->runChangeWebserverScript('nginx');
    }

    public function test_an_update_run_seeds_its_placeholder_then_enqueues_the_updater(): void
    {
        $system = new RecordingSystem();

        $system->runUpdateScript('LICENSE-KEY');

        $this->assertSame(['host.seedEngineUpdatePlaceholder', 'host.enqueueEngineUpdate'], $this->actions($system));
    }

    public function test_hosting_accounts_are_read_from_the_host_listing_skipping_malformed_lines(): void
    {
        $system = new RecordingSystem();
        $system->output = "alice\t1001\t1001\t/home/alice\t/bin/bash\t1\nbroken line\nbob\t1002\t1003\t/home/bob\t/usr/sbin/nologin\t0\n";

        $this->assertSame([
            'alice' => ['uid' => 1001, 'gid' => 1001, 'home' => '/home/alice', 'shell' => '/bin/bash', 'engine' => true],
            'bob' => ['uid' => 1002, 'gid' => 1003, 'home' => '/home/bob', 'shell' => '/usr/sbin/nologin', 'engine' => false],
        ], $system->hostingAccounts());
        $this->assertSame(['users.hostingAccounts'], $this->actions($system));
    }

    public function test_a_name_held_by_a_home_and_a_project_directory_lists_both(): void
    {
        $system = new class ($this->homes) extends RecordingSystem {
            public function __construct(private string $homes)
            {
            }

            public function homesDirPath(): string
            {
                return $this->homes;
            }

            public function engineDirPath(): string
            {
                return $this->homes . '/engine';
            }
        };
        $system->exitCode = 1;
        mkdir($system->projectHomeDirPath('taken'), 0775, true);
        mkdir($system->projectDirPath('taken'), 0775, true);

        $this->assertSame([System::HOLDER_HOME, System::HOLDER_PROJECT_DIRECTORY], $system->usernameHolders('taken'));
    }

    /** @return list<?string> */
    private function actions(RecordingSystem $system): array
    {
        return array_map(static fn (array|string $cmd): ?string => is_array($cmd) ? Root::askedAction($cmd) : null, $system->commands);
    }

    /** @return list<string> */
    private function commandFor(RecordingSystem $system, string $action): array
    {
        foreach ($system->commands as $cmd) {
            if (is_array($cmd) && Root::askedAction($cmd) === $action) {
                return $cmd;
            }
        }
        $this->fail("No {$action} was asked for.");
    }
}
