<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ProjectOrphans;
use App\System;
use App\System\Project;
use Mockery;
use RuntimeException;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A name held without a project row is listed with what holds it when something engine-made holds it, and freed only
 * when everything holding it is the engine's own: an account `users.create` made, a home carrying the engine's trace or
 * belonging to such an account, and a container Compose started from the project's directory.
 */
class ProjectOrphansTest extends TestCase
{
    use InMemoryDatabase;

    /** @var array<string, array{uid: int, gid: int, home: string, shell: string, engine: bool}> */
    private array $accounts = [];

    /** @var list<string> */
    private array $homes = [];

    /** @var list<string> */
    private array $traced = [];

    /** @var list<string> */
    private array $directories = [];

    /** @var list<string> */
    private array $containers = [];

    /** @var list<string> */
    private array $accountContainers = [];

    /** @var list<string> */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_every_holder_of_every_orphaned_name_is_listed(): void
    {
        $this->accounts = [
            'held' => $this->account(1004, engine: true),
            'mixed' => $this->account(1005, engine: false),
        ];
        $this->homes = ['held', 'homeonly', 'owned'];
        $this->traced = ['homeonly'];
        $this->directories = ['held', 'dironly', 'mixed'];
        $this->containers = ['held', 'boxonly'];
        $this->accountContainers = ['held', 'boxonly'];
        $this->makeUser('owned');

        $this->assertSame([
            'boxonly' => [System::HOLDER_CONTAINER],
            'dironly' => [System::HOLDER_PROJECT_DIRECTORY],
            'held' => [
                System::HOLDER_ACCOUNT,
                System::HOLDER_HOME,
                System::HOLDER_PROJECT_DIRECTORY,
                System::HOLDER_CONTAINER,
            ],
            'homeonly' => [System::HOLDER_HOME],
            'mixed' => [System::HOLDER_ACCOUNT, System::HOLDER_PROJECT_DIRECTORY],
        ], (new ProjectOrphans($this->system()))->all());
    }

    public function test_a_name_only_the_hosts_own_account_and_files_hold_is_not_listed(): void
    {
        $this->accounts = ['ubuntu' => $this->account(1000, engine: false)];
        $this->homes = ['ubuntu', 'bare'];

        $this->assertSame([], (new ProjectOrphans($this->system()))->all());
    }

    public function test_the_hosts_own_account_is_not_an_orphan_and_nothing_is_removed(): void
    {
        $this->accounts = ['ubuntu' => $this->account(1000, engine: false)];
        $this->homes = ['ubuntu'];

        try {
            (new ProjectOrphans($this->system()))->delete('ubuntu');
            $this->fail('A name nothing engine-made holds was expected to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Nothing this engine made holds 'ubuntu'", $e->getMessage());
        }

        $this->assertSame([], $this->removed);
    }

    public function test_a_bare_home_without_the_engines_trace_is_left_with_the_step_to_remove_it(): void
    {
        $this->homes = ['bare'];

        try {
            (new ProjectOrphans($this->system()))->delete('bare');
            $this->fail('A home the engine did not make was expected to be left alone.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was removed', $e->getMessage());
            $this->assertStringContainsString('`rm -rf /home/bare`', $e->getMessage());
        }

        $this->assertSame([], $this->removed);
    }

    public function test_a_bare_home_with_the_engines_trace_is_freed(): void
    {
        $this->homes = ['bare'];
        $this->traced = ['bare'];

        (new ProjectOrphans($this->system()))->delete('bare');

        $this->assertSame(['account torn down'], $this->removed);
    }

    public function test_a_home_without_the_trace_is_the_engines_when_its_account_is(): void
    {
        $this->accounts = ['held' => $this->account(1004, engine: true)];
        $this->homes = ['held'];

        (new ProjectOrphans($this->system()))->delete('held');

        $this->assertSame(['account torn down'], $this->removed);
    }

    public function test_what_the_engine_made_goes_container_first_and_the_account_before_its_home(): void
    {
        $this->accounts = ['held' => $this->account(1004, engine: true)];
        $this->homes = ['held'];
        $this->directories = ['held'];
        $this->containers = ['held'];
        $this->accountContainers = ['held'];

        (new ProjectOrphans($this->system()))->delete('held');

        $this->assertSame(['container held', 'account torn down'], $this->removed);
    }

    public function test_an_account_the_engine_did_not_make_stops_the_delete(): void
    {
        $this->accounts = ['held' => $this->account(1004, engine: false)];
        $this->homes = ['held'];
        $this->directories = ['held'];

        try {
            (new ProjectOrphans($this->system()))->delete('held');
            $this->fail('A foreign account was expected to stop the delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('something this engine did not make', $e->getMessage());
            $this->assertStringContainsString('`userdel held`', $e->getMessage());
        }

        $this->assertSame([], $this->removed);
    }

    public function test_a_container_the_engine_did_not_start_stops_the_delete(): void
    {
        $this->accounts = ['held' => $this->account(1004, engine: true)];
        $this->containers = ['held'];

        try {
            (new ProjectOrphans($this->system()))->delete('held');
            $this->fail('A foreign container was expected to stop the delete.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('something this engine did not make', $e->getMessage());
            $this->assertStringContainsString('`docker rm -f held`', $e->getMessage());
        }

        $this->assertSame([], $this->removed);
    }

    public function test_a_name_a_project_owns_is_left_to_the_project_delete(): void
    {
        $this->accounts = ['held' => $this->account(1004, engine: true)];
        $this->makeUser('held');

        try {
            (new ProjectOrphans($this->system()))->delete('held');
            $this->fail('A name a project row owns was expected to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("A project named 'held' still exists", $e->getMessage());
            $this->assertStringContainsString('delete that project to free it', $e->getMessage());
        }

        $this->assertSame([], $this->removed);
    }

    public function test_only_the_holders_the_engine_did_not_make_need_an_operator_step(): void
    {
        $this->accounts = [
            'held' => $this->account(1004, engine: true),
            'foreign' => $this->account(1005, engine: false),
        ];
        $this->containers = ['box'];
        $orphans = new ProjectOrphans($this->system());

        $this->traced = ['loose'];
        $this->assertSame([], $orphans->foreignSteps('held', [System::HOLDER_ACCOUNT]));
        $this->assertSame([], $orphans->foreignSteps('loose', [System::HOLDER_HOME]));
        $this->assertSame(['rm -rf /home/stray'], $orphans->foreignSteps('stray', [System::HOLDER_HOME]));
        $this->assertSame(['userdel foreign'], $orphans->foreignSteps('foreign', [System::HOLDER_ACCOUNT]));
        $this->assertSame(['docker rm -f box'], $orphans->foreignSteps('box', [System::HOLDER_CONTAINER]));
    }

    public function test_a_name_nothing_holds_is_refused_rather_than_quietly_done(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Nothing on this server holds 'held'.");
        (new ProjectOrphans($this->system()))->delete('held');
    }

    /** @return array{uid: int, gid: int, home: string, shell: string, engine: bool} */
    private function account(int $uid, bool $engine): array
    {
        return [
            'uid' => $uid,
            'gid' => $uid,
            'home' => '/home/'.$uid,
            'shell' => '/usr/sbin/nologin',
            'engine' => $engine,
        ];
    }

    private function system(): System
    {
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('tearDownLinuxIsolation')->andReturnUsing(function (): void {
            $this->removed[] = 'account torn down';
        });

        $system = Mockery::mock(System::class)->makePartial();
        $system->shouldReceive('hostingAccounts')->andReturnUsing(fn (): array => $this->accounts);
        $system->shouldReceive('homesDirPath')->andReturn('/home');
        $system->shouldReceive('projectsDirPath')->andReturn('/opt/panelalpha/shared-hosting/users');
        $system->shouldReceive('projectHomeDirPath')->andReturnUsing(fn (string $name): string => '/home/'.$name);
        $system->shouldReceive('projectDirPath')
            ->andReturnUsing(fn (string $name): string => '/opt/panelalpha/shared-hosting/users/'.$name);
        $system->shouldReceive('usernameHolders')->andReturnUsing(fn (string $name): array => $this->holdersOf($name));
        $system->shouldReceive('project')->andReturn($project);
        $system->shouldReceive('exec')->andReturnUsing(function (array $cmd): string {
            $this->removed[] = 'container '.LocalRoot::values($cmd)[0];

            return '';
        });
        $system->shouldReceive('runProcess')->andReturnUsing(fn (array $cmd): FakeProcess => $this->answer($cmd));

        return $system;
    }

    /** @return list<string> */
    private function holdersOf(string $name): array
    {
        $holders = [];
        if (isset($this->accounts[$name])) {
            $holders[] = System::HOLDER_ACCOUNT;
        }
        if (in_array($name, $this->homes, true)) {
            $holders[] = System::HOLDER_HOME;
        }
        if (in_array($name, $this->directories, true)) {
            $holders[] = System::HOLDER_PROJECT_DIRECTORY;
        }
        if (in_array($name, $this->containers, true)) {
            $holders[] = System::HOLDER_CONTAINER;
        }

        return $holders;
    }

    /** @param  list<string>  $cmd */
    private function answer(array $cmd): FakeProcess
    {
        $values = LocalRoot::values($cmd);

        return match (LocalRoot::action($cmd)) {
            'files.listAllNames' => FakeProcess::ok(implode("\n", $values[0] === '/home' ? $this->homes : $this->directories)."\n"),
            'docker.containersLabelled' => FakeProcess::ok(implode("\n", array_map(
                static fn (string $name): string => "{$name}\trunning\t/opt/panelalpha/shared-hosting/users/{$name}",
                $this->containers
            ))),
            'docker.containerNamedWithLabel' => FakeProcess::ok(
                in_array($values[0], $this->accountContainers, true) ? $values[0]."\n" : ''
            ),
            'files.isDirectory' => in_array($values[0], array_map(
                static fn (string $name): string => "/home/{$name}/".Project::ENGINE_TRACE,
                $this->traced
            ), true) ? FakeProcess::ok() : FakeProcess::failed(),
            default => FakeProcess::ok(),
        };
    }
}
