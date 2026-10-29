<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Unit\System\Project\SuccessfulProcess;

/**
 * An app config's `files:` land in ~/project, which every uid on the host can
 * traverse. A root `.env` among them holds what the account's Compose passes
 * to the app, so it is owner-only like every other root `.env` the engine
 * writes; the app reads it through `env_file:`, never from the file.
 */
class AppConfigEnvSnippetModeTest extends TestCase
{
    private const PROJECT = '/home/acme/project';

    /** @var array<string, string> path => mode it was given before being renamed there */
    private array $modes = [];

    public function test_a_root_env_snippet_is_owner_only(): void
    {
        $this->install(['.env']);
        $this->assertSame('600', $this->modes[self::PROJECT.'/.env']);

        $this->modes = [];
        $this->install(['./.env']);
        $this->assertSame('600', $this->modes[self::PROJECT.'/.env']);
    }

    public function test_other_snippets_keep_their_mode(): void
    {
        $this->install(['api/.env', '.env.example', 'config/app.yml']);

        $this->assertSame('644', $this->modes[self::PROJECT.'/api/.env']);
        $this->assertSame('644', $this->modes[self::PROJECT.'/.env.example']);
        $this->assertSame('644', $this->modes[self::PROJECT.'/config/app.yml']);
    }

    /** @param list<string> $paths */
    private function install(array $paths): void
    {
        $appConfig = AppConfig::fromDirectory([
            'files' => array_map(static fn (string $path): array => ['path' => $path, 'contents' => "A=1\n"], $paths),
        ]);
        $model = new User;
        $model->username = 'acme';
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->getMockBuilder(Dind::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['appConfig', 'userAppDirPath', 'userModel', 'username', 'composeFilePath', 'shell', 'system'])
            ->getMock();
        $dind->expects($this->once())->method('appConfig')->willReturn($appConfig);
        $dind->method('userAppDirPath')->willReturn(self::PROJECT);
        $dind->method('userModel')->willReturn($model);
        $dind->method('username')->willReturn('acme');
        $dind->method('composeFilePath')->willReturn('/opt/panelalpha/shared-hosting/users/acme/docker-compose.yml');
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('system')->willReturn($this->system());

        $dind->installFileSnippets();
    }

    private function system(): System
    {
        $modes = &$this->modes;

        return new class($modes) extends System
        {
            /** @var array<string, string> */
            private array $staged = [];

            /** @param array<string, string> $modes */
            public function __construct(private array &$modes) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = (array) $cmd;
                if (array_slice($argv, 0, 2) === ['sudo', 'chmod']) {
                    $this->staged[$argv[3]] = $argv[2];
                }
                if (array_slice($argv, 0, 3) === ['sudo', 'mv', '-T'] && isset($this->staged[$argv[3]])) {
                    $this->modes[$argv[4]] = $this->staged[$argv[3]];
                }

                return '';
            }

            /** A file in an account's home is renamed into place by the account-home helper, under its lexical path. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                if (array_slice($argv, 0, 3) === ['sudo', 'test', '-L']) {
                    $notALink = new Process(['php', '-r', 'exit(1);']);
                    $notALink->run();

                    return $notALink;
                }
                $rename = array_search('rename', $argv, true);
                if (array_slice($argv, 0, 2) === ['sudo', 'python3'] && $rename !== false
                    && isset($this->staged[$argv[$rename + 2]])) {
                    $this->modes[$argv[$rename + 3]] = $this->staged[$argv[$rename + 2]];
                }

                return SuccessfulProcess::for($cmd);
            }
        };
    }
}
