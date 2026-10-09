<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;

/**
 * Root's docker commands in the account never read /root, which an account
 * sysbox started unmapped cannot enter; the account user's read its own home.
 */
class AccountDockerConfigTest extends TestCase
{
    private const COMPOSE = '/opt/users/alice/docker-compose.yml';

    private const ROOT_CONFIG = 'DOCKER_CONFIG=/home/alice/.panelalpha-docker';

    private const LOGINS = '/home/alice/.panelalpha-registry-x';

    /** @var list<list<string>> */
    private array $commands = [];

    public function test_compose_and_build_run_by_root_get_the_engines_config(): void
    {
        $shell = new ShellOperations($this->project());

        foreach ([['up', '-d', '--build'], ['build'], ['pull', '--policy', 'missing']] as $rest) {
            $argv = $shell->wrap(['env', 'PWD=/home/alice/project', 'docker', 'compose', ...$rest]);

            $this->assertSame(
                ['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-e', self::ROOT_CONFIG, '-T', 'dind', 'env'],
                array_slice($argv, 0, 11)
            );
        }
    }

    public function test_registry_logins_still_win_over_it(): void
    {
        $shell = new ShellOperations($this->project());
        $command = AppLauncher::withDockerConfig(['env', 'PWD=/home/alice/project', 'docker', 'compose', 'build'], self::LOGINS);

        $argv = $shell->wrap($command);

        // `env` sets its own after the exec's: the login directory is what docker reads.
        $this->assertSame(
            ['-e', self::ROOT_CONFIG, '-T', 'dind', 'env', 'DOCKER_CONFIG=' . self::LOGINS, 'PWD=/home/alice/project', 'docker', 'compose', 'build'],
            array_slice($argv, 6)
        );
    }

    public function test_commands_run_as_the_account_read_its_own_config(): void
    {
        $project = $this->project();
        $shell = new ShellOperations($project);

        $shell->execAsUserQuiet(['env', 'PWD=/home/alice/project', 'docker', 'compose', 'ps']);

        $this->assertSame(['sudo', 'docker', 'compose', '-f', self::COMPOSE, 'exec', '-T', 'dind', 'su'], array_slice($this->commands[0], 0, 9));
        $this->assertNotContains(self::ROOT_CONFIG, $shell->wrap(['docker', 'ps'], true));
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
        $project->method('composeFilePath')->willReturn(self::COMPOSE);

        return $project;
    }
}
