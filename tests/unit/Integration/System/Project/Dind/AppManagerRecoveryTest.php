<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Models\User;
use App\System;
use App\System\Filesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppManager;
use App\System\Project\Dind\ShellOperations;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A script an earlier deploy did not leave, or one that names a snippet not written yet, is put
 * in place and run again; a project with no app script to install is refused.
 */
class AppManagerRecoveryTest extends TestCase
{
    private const SCRIPT = '/home/acme/project/' . AppConfig::APP_SCRIPT;

    /** @var list<array{int, string, string}> exit code, stdout, stderr of each run, in order */
    private array $replies = [];

    /** @var list<list<string>> what ran in the account, as the account runs it */
    private array $ran = [];

    /** @var list<array{string, string, string, string}> */
    private array $written = [];

    private int $snippetsInstalled = 0;

    public function test_a_missing_script_is_installed_from_the_app_config_and_run_again(): void
    {
        $this->replies = [
            [127, '', 'bash: ' . self::SCRIPT . ': No such file or directory'],
            [0, '["users:add","users:list"]', ''],
            [0, '{"id":"5"}', ''],
        ];

        $added = $this->manager(AppConfig::fromDirectory(['app' => "#!/bin/bash\necho '{}'\n"]))
            ->addUser('editor', 'editor@example.com', 'Sup3rSecret!', 'editor');

        $this->assertSame(['id' => '5'], $added);
        $this->assertSame([[self::SCRIPT, "#!/bin/bash\necho '{}'\n", '1000:1000', '700']], $this->written);
        $this->assertCount(3, $this->ran);
        $this->assertSame(['bash', self::SCRIPT, 'info'], array_slice($this->ran[1], -3));
        $this->assertSame(['bash', self::SCRIPT, 'users:add', 'editor', 'editor@example.com', 'Sup3rSecret!', 'editor'], array_slice($this->ran[2], -7));
    }

    public function test_a_missing_script_with_no_app_config_to_install_it_from_is_unsupported(): void
    {
        $this->replies = [
            [127, '', 'bash: ' . self::SCRIPT . ': No such file or directory'],
            [127, '', 'bash: ' . self::SCRIPT . ': No such file or directory'],
        ];

        try {
            $this->manager(null)->install('https://shop.example.com', 'Shop', 'admin', 'admin@example.com', 'Sup3rSecret!');
            $this->fail('the install ran without a script');
        } catch (\Exception $e) {
            $this->assertSame('App management is not supported for this application', $e->getMessage());
        }

        $this->assertSame([], $this->written);
        $this->assertSame(['bash', self::SCRIPT, 'install', 'https://shop.example.com', 'Shop', 'admin', 'admin@example.com', 'Sup3rSecret!'], array_slice($this->ran[1], -8));
    }

    public function test_a_missing_snippet_is_written_and_the_command_run_again(): void
    {
        $this->replies = [
            [0, '["roles:list"]', ''],
            [1, '', "MISSING_SNIPPET: wp-config-extra.php\n"],
            [0, '["administrator","editor"]', ''],
        ];

        $roles = $this->manager(null)->listRoles();

        $this->assertSame(['administrator', 'editor'], $roles);
        $this->assertSame(1, $this->snippetsInstalled);
        $this->assertCount(3, $this->ran);
    }

    private function manager(?AppConfig $appConfig): AppManager
    {
        $written = &$this->written;
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('writeFileReplacingPath')->willReturnCallback(
            function (string $path, string $contents, string $owner, string $mode) use (&$written): void {
                $written[] = [$path, $contents, $owner, $mode];
            }
        );
        $system = $this->createStub(System::class);
        $system->method('filesystem')->willReturn($filesystem);
        $system->method('runProcess')->willReturnCallback(function (string|array $cmd): Process {
            $this->ran[] = LocalRoot::inAccount((array) $cmd);
            [$code, $out, $err] = array_shift($this->replies) ?? [0, '', ''];
            $process = new Process(['sh', '-c', 'printf %s "$1"; printf %s "$2" >&2; exit "$3"', 'sh', $out, $err, (string) $code]);
            $process->run();

            return $process;
        });

        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn([]);
        $user->method('getChownString')->willReturn('1000:1000');
        $user->method('getGitRepo')->willReturn('https://github.com/acme/shop');

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('acme');
        $dind->method('homeDirPath')->willReturn('/home/acme');
        $dind->method('userAppDirPath')->willReturn('/home/acme/project');
        $dind->method('userAppComposeEnv')->willReturn([]);
        $dind->method('userModel')->willReturn($user);
        $dind->method('system')->willReturn($system);
        $dind->method('appConfig')->willReturn($appConfig);
        $dind->method('installFileSnippets')->willReturnCallback(function (): void {
            $this->snippetsInstalled++;
        });
        $dind->method('shell')->willReturnCallback(static fn (): ShellOperations => new ShellOperations($dind));

        return new AppManager($dind);
    }
}
