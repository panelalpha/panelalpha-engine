<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\GenerationState;
use App\System\Project\Dind\Generation\GenerationSweep;
use App\System\Project\Dind\ShellOperations;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A crash between `mv ~/project ~/.project-prev` and recording it strands the
 * tree aside with no CHECKOUT entry ({@see CheckoutAside::moveAside()}). The
 * record is now written first, so a crash either side of the move is
 * recoverable, and an aside directory an older engine left with no record is
 * put back by the sweep rather than deleted.
 */
class CheckoutAsideStrandedTest extends TestCase
{
    private string $username = '';

    /** @var list<list<string>> */
    private array $ran = [];

    private string $aside = '/home/u/.project-prev';

    private bool $asideThere = false;

    private bool $projectThere = false;

    private bool $projectEmpty = false;

    /** The user directory was made unusable to model a state write that fails. */
    private bool $stateUnwritable = false;

    /** Whether generation.json existed when the `mv` was about to run. */
    private bool $recordedBeforeMove = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'aside-' . bin2hex(random_bytes(6));
        // As a first deploy leaves it: the account's log directory, which the
        // sweep's deploy lock lives in, is already there.
        @mkdir(DeployLogPaths::userDir($this->username), 0755, true);
    }

    protected function tearDown(): void
    {
        // A directory made unusable for the failed-write case is a plain file.
        @unlink(DeployLogPaths::userDir($this->username));
        DeployLogger::deleteUserLogs($this->username);
        parent::tearDown();
    }

    /** The record names the tree before it is moved: a crash in between is recoverable. */
    public function test_the_checkout_record_is_written_before_the_move(): void
    {
        $this->assertTrue($this->aside()->moveAside(['app-1'], copyBack: false));
        $this->assertTrue($this->recordedBeforeMove, implode("\n", array_map(fn ($c) => implode(' ', $c), $this->ran)));
        $this->assertSame($this->aside, (new GenerationState($this->username))->get(GenerationState::CHECKOUT)['path']);
    }

    /** A state write that fails is reported before anything is moved, so no tree is left unrecorded. */
    public function test_a_failed_state_write_is_reported_before_anything_is_moved(): void
    {
        $this->stateUnwritable = true;

        $this->assertFalse($this->aside()->moveAside(['app-1'], copyBack: false));
        $this->assertFalse($this->ranMatching(['root', 'mv']), 'nothing was moved');
    }

    /** An aside directory no record names, with ~/project gone, is put back. */
    public function test_the_sweep_puts_a_stranded_checkout_back(): void
    {
        $this->asideThere = true;
        $this->projectThere = false;

        $this->assertSame('restored', $this->aside()->reconcileStranded());
        $this->assertTrue($this->ranMatching(['root', 'mv', '-T', '--', $this->aside, '/home/u/project']));
    }

    /** An empty ~/project holds nothing to lose: the stranded tree goes back in its place. */
    public function test_a_stranded_checkout_is_put_back_over_an_empty_project_directory(): void
    {
        $this->asideThere = true;
        $this->projectThere = true;
        $this->projectEmpty = true;

        $this->assertSame('restored', $this->aside()->reconcileStranded());
        $this->assertTrue($this->ranMatching(['root', 'mv', '-T', '--', $this->aside, '/home/u/project']));
    }

    /** Nothing names it and ~/project is there: it is reported, never deleted. */
    public function test_a_stranded_checkout_is_never_deleted_when_there_is_nowhere_to_put_it(): void
    {
        $this->asideThere = true;
        $this->projectThere = true;

        $this->assertSame('not-restorable', $this->aside()->reconcileStranded());
        $this->assertFalse($this->ranMatching(['root', 'rm']));
        $this->assertFalse($this->ranMatching(['root', 'mv']));
    }

    /** The sweep settles it: the stranded tree is put back and the line names it. */
    public function test_the_sweep_reconciles_a_stranded_checkout(): void
    {
        $this->asideThere = true;
        $this->projectThere = false;

        $done = GenerationSweep::settleIfIdle($this->project());

        $this->assertContains('checkout restored', $done ?? []);
        $this->assertTrue($this->ranMatching(['root', 'mv', '-T', '--', $this->aside, '/home/u/project']));
    }

    /** A deploy that failed or was cancelled before its log was finished still holds the lock: it settles under it. */
    public function test_a_deploy_holding_its_own_lock_settles_what_it_moved_aside(): void
    {
        $this->asideThere = true;
        $this->projectThere = true;
        $state = new GenerationState($this->username);
        $state->put(GenerationState::CHECKOUT, ['path' => $this->aside, 'containers' => ['app-1'], 'owner' => GenerationState::owner()]);
        $state->put(GenerationState::STOPPED, ['owner' => GenerationState::owner()]);
        $deploy = DeployLogger::start($this->username);

        $done = GenerationSweep::settleIfIdle($this->project(), false);

        $this->assertContains('checkout removed', $done ?? []);
        $this->assertSame([], $state->all(), 'the checkout record and the stopped mark are gone');
        $deploy->finish(DeployLogger::STATUS_FAILED, 'Archive is not a zip');
    }

    /** A crash after the provisional record, before the move: the live ~/project is never moved or deleted. */
    public function test_a_provisional_record_with_nothing_aside_leaves_the_live_checkout_alone(): void
    {
        $this->asideThere = false;
        $this->projectThere = true;
        (new GenerationState($this->username))->put(GenerationState::CHECKOUT, ['path' => $this->aside, 'containers' => ['app-1']]);

        $this->assertFalse($this->aside()->bringBack(), 'nothing aside to bring back');
        $this->assertFalse($this->ranMatching(['root', 'mv', '-T', '--', '/home/u/project']), 'the live tree was not moved');

        $this->assertNull($this->aside()->settle(false));
        $this->assertNull((new GenerationState($this->username))->get(GenerationState::CHECKOUT), 'the provisional record is dropped');
        $this->assertFalse($this->ranMatching(['root', 'mv', '-T', '--', '/home/u/project']));
    }

    /** An unrecorded aside dir beside a live ~/project is left, not deleted by the next move. */
    public function test_move_aside_refuses_rather_than_delete_an_unrecorded_aside(): void
    {
        $this->asideThere = true;
        $this->projectThere = true;

        $this->assertFalse($this->aside()->moveAside(['app-1'], copyBack: false));
        $this->assertFalse($this->ranMatching(['root', 'rm', '-rf', '--', $this->aside]));
    }

    private function aside(): CheckoutAside
    {
        return new CheckoutAside($this->project());
    }

    /**
     * `account` or `root`, then the command the broker runs.
     *
     * @param  list<string>  $cmd
     * @return list<string>
     */
    private static function described(array $cmd): array
    {
        if (! LocalRoot::isRequest($cmd)) {
            return $cmd;
        }

        return [str_starts_with((string) LocalRoot::action($cmd), 'account.') ? 'account' : 'root', ...LocalRoot::plain($cmd)];
    }

    /** @param list<string> $prefix */
    private function ranMatching(array $prefix): bool
    {
        foreach ($this->ran as $command) {
            if (array_slice($command, 0, count($prefix)) === $prefix) {
                return true;
            }
        }

        return false;
    }

    private function project(): Dind
    {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('directoryExists')->willReturnCallback(
            fn (string $path): bool => $path === $this->aside ? $this->asideThere : $this->projectThere
        );
        $filesystem->method('ls')->willReturnCallback(
            fn (): array => $this->projectEmpty ? [''] : ['index.php']
        );
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            if (is_array($cmd) && ($cmd[1] ?? null) === 'account-docker.containers') {
                return (string) json_encode(array_fill(0, count($cmd) - 4, null));
            }
            $command = is_array($cmd) ? self::described(array_values($cmd)) : [$cmd];
            if (array_slice($command, 0, 4) === ['root', 'mv', '-T', '--']
                && ($command[4] ?? null) === '/home/u/project') {
                $this->recordedBeforeMove = file_exists(GenerationState::path($this->username));
            }
            $this->ran[] = $command;

            return '';
        });

        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);

        $project = $this->createStub(Dind::class);
        $project->method('composeFilePath')->willReturn('/opt/panelalpha/shared-hosting/users/u/docker-compose.yml');
        $project->method('username')->willReturn($this->username);
        $project->method('system')->willReturn($system);
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/u');
        $project->method('userAppDirPath')->willReturn('/home/u/project');
        $project->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($project));
        $project->method('accountDaemon')->willReturnCallback(fn (): AccountDaemon => new AccountDaemon($project));

        if ($this->stateUnwritable) {
            // A file where the account's log directory should be: the state
            // write cannot land, exactly as on a full disk.
            $dir = DeployLogPaths::userDir($this->username);
            @rmdir($dir);
            file_put_contents($dir, '');
        }

        return $project;
    }
}
