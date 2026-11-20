<?php

namespace Tests\Integration\Console;

use App\System;
use App\System\Project;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * `project:orphan:list` and `project:orphan:delete` are the operator's way out of a name held without a project row: the
 * list says what holds it, and the delete takes `--force` like every other command that removes something.
 */
class ProjectOrphanCommandsTest extends TestCase
{
    use InMemoryDatabase;

    /** @var array<string, array{uid: int, gid: int, home: string, shell: string, engine: bool}> */
    private array $accounts = [];

    /** @var list<string> */
    private array $homes = [];

    /** @var array<string, list<string>> */
    private array $holders = [];

    /** @var list<string> */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->app->instance(System::class, $this->system());
    }

    public function test_the_list_names_what_holds_a_name_and_the_command_that_frees_it(): void
    {
        $this->accounts = ['fe25orph' => $this->account()];
        $this->homes = ['fe25orph'];
        $this->holders = ['fe25orph' => [System::HOLDER_ACCOUNT, System::HOLDER_HOME]];

        $this->assertSame(0, Artisan::call('project:orphan:list'));

        $this->assertStringContainsString('fe25orph', $output = Artisan::output());
        $this->assertStringContainsString('account, home directory', $output);
        $this->assertStringContainsString('pae project:orphan:delete fe25orph --force', $output);
    }

    public function test_the_delete_without_a_name_asks_for_one(): void
    {
        $this->assertSame(1, Artisan::call('project:orphan:delete', ['name' => ' ', '--force' => true]));
        $this->assertStringContainsString('Give at least one project name to free.', Artisan::output());
    }

    public function test_the_hosts_own_admin_is_not_listed(): void
    {
        $this->accounts = ['ubuntu' => ['uid' => 1000, 'gid' => 1000, 'home' => '/home/ubuntu', 'shell' => '/bin/bash', 'engine' => false]];
        $this->homes = ['ubuntu'];

        $this->assertSame(0, Artisan::call('project:orphan:list'));
        $this->assertStringContainsString('No name is held without a project.', $output = Artisan::output());
        $this->assertStringNotContainsString('userdel', $output);
    }

    public function test_a_host_that_holds_no_name_says_so(): void
    {
        $this->assertSame(0, Artisan::call('project:orphan:list'));
        $this->assertStringContainsString('No name is held without a project.', Artisan::output());
    }

    public function test_the_delete_without_force_frees_nothing(): void
    {
        $this->accounts = ['fe25orph' => $this->account()];
        $this->holders = ['fe25orph' => [System::HOLDER_ACCOUNT]];

        $exit = Artisan::call('project:orphan:delete', ['name' => 'fe25orph', '--no-interaction' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --force', Artisan::output());
        $this->assertSame([], $this->removed);
    }

    public function test_the_delete_frees_the_name_and_says_so(): void
    {
        $this->accounts = ['fe25orph' => $this->account()];
        $this->holders = ['fe25orph' => [System::HOLDER_ACCOUNT]];

        $this->artisan('project:orphan:delete fe25orph --force')
            ->expectsOutputToContain("'fe25orph' is free to be a project again.")
            ->assertExitCode(0);

        $this->assertSame(['account torn down'], $this->removed);
    }

    public function test_a_name_a_project_row_owns_is_left_to_the_project_delete(): void
    {
        $this->makeUser('held');
        $this->holders = ['held' => [System::HOLDER_ACCOUNT]];

        $this->artisan('project:orphan:delete held --force')
            ->expectsOutputToContain("A project named 'held' still exists")
            ->assertExitCode(1);

        $this->assertSame([], $this->removed);
    }

    /** @return array{uid: int, gid: int, home: string, shell: string, engine: bool} */
    private function account(): array
    {
        return ['uid' => 1004, 'gid' => 1004, 'home' => '/home/fe25orph', 'shell' => '/usr/sbin/nologin', 'engine' => true];
    }

    private function system(): System
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('tearDownLinuxIsolation')->andReturnUsing(function (): void {
            $this->removed[] = 'account torn down';
        });

        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('hostingAccounts')->andReturnUsing(fn (): array => $this->accounts);
        $system->shouldReceive('usernameHolders')->andReturnUsing(fn (string $name): array => $this->holders[$name] ?? []);
        $system->shouldReceive('homesDirPath')->andReturn('/home');
        $system->shouldReceive('projectsDirPath')->andReturn('/opt/panelalpha/shared-hosting/users');
        $system->shouldReceive('projectHomeDirPath')->andReturnUsing(fn (string $name): string => '/home/'.$name);
        $system->shouldReceive('projectDirPath')
            ->andReturnUsing(fn (string $name): string => '/opt/panelalpha/shared-hosting/users/'.$name);
        $system->shouldReceive('project')->andReturn($project);
        $system->shouldReceive('runProcess')->andReturnUsing(fn (array $cmd): FakeProcess => match (true) {
            LocalRoot::action($cmd) === 'files.listAllNames' && LocalRoot::values($cmd)[0] === '/home' => FakeProcess::ok(implode("\n", $this->homes)."\n"),
            LocalRoot::action($cmd) === 'files.isDirectory' => FakeProcess::failed(),
            default => FakeProcess::ok(),
        });

        return $system;
    }
}
