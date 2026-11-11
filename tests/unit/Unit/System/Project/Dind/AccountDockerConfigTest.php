<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * Root's docker commands for the account never read /root, which an account
 * sysbox started unmapped cannot enter; the account user's read its own home.
 */
class AccountDockerConfigTest extends TestCase
{
    private const ROOT_CONFIG = ['DOCKER_CONFIG' => '/home/alice/.panelalpha-docker'];

    private const LOGINS = '/home/alice/.panelalpha-registry-x';

    /** @var list<list<string>> */
    private array $commands = [];

    public function test_compose_and_build_run_by_root_get_the_engines_config(): void
    {
        $shell = new ShellOperations($this->project());

        foreach ([['up', '-d', '--build'], ['build'], ['pull', '--policy', 'missing']] as $rest) {
            $argv = $shell->wrap(['env', 'PWD=/home/alice/project', 'docker', 'compose', ...$rest]);

            $this->assertSame([...self::ROOT_CONFIG, 'PWD' => '/home/alice/project'], LocalRoot::accountEnvironment($argv));
            $this->assertSame(['docker', 'compose', ...$rest], LocalRoot::composeCommand($argv));
            $this->assertSame('root', LocalRoot::values($argv)[1]);
        }
    }

    public function test_registry_logins_still_win_over_it(): void
    {
        $shell = new ShellOperations($this->project());
        $command = AppLauncher::withDockerConfig(['env', 'PWD=/home/alice/project', 'docker', 'compose', 'build'], self::LOGINS);

        $argv = $shell->wrap($command);

        $this->assertSame(['DOCKER_CONFIG' => self::LOGINS, 'PWD' => '/home/alice/project'], LocalRoot::accountEnvironment($argv));
        $this->assertSame(['docker', 'compose', 'build'], LocalRoot::composeCommand($argv));
    }

    public function test_commands_run_as_the_account_read_its_own_config(): void
    {
        $project = $this->project();
        $shell = new ShellOperations($project);

        $shell->execAsUserQuiet(['env', 'PWD=/home/alice/project', 'docker', 'compose', 'ps']);

        $this->assertSame(['PWD' => '/home/alice/project'], LocalRoot::accountEnvironment($this->commands[0]));
        $this->assertSame('', LocalRoot::values($this->commands[0])[1]);
        $this->assertSame([], LocalRoot::accountEnvironment($shell->wrap(['docker', 'ps'], true)));
    }

    public function test_the_directory_is_in_the_home(): void
    {
        $this->assertSame('/home/alice/.panelalpha-docker', (new ShellOperations($this->project()))->dockerConfigDir());
    }

    private function project(): Dind
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (string|array $cmd): string {
            $this->commands[] = (array) $cmd;

            return '';
        });
        $user = $this->createStub(User::class);
        $user->method('getChownString')->willReturn('1000:1000');

        $project = $this->createStub(Dind::class);
        $project->method('system')->willReturn($system);
        $project->method('username')->willReturn('alice');
        $project->method('userModel')->willReturn($user);
        $project->method('homeDirPath')->willReturn('/home/alice/');

        return $project;
    }
}
