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
 * An app config file under a folder link. A link that leads nowhere inside the
 * account's container made the account's own `mkdir -p` fail first, with its raw
 * "File exists"; it gets the refusal any link above a written file gets, before
 * anything runs.
 */
class AppConfigSnippetUnderLinkTest extends TestCase
{
    private const PROJECT = '/home/acme/project';

    /** @var list<string> every command the host and the account were asked to run */
    private array $ran = [];

    public function test_a_file_under_a_linked_folder_is_refused_before_anything_runs(): void
    {
        try {
            $this->install('pwn/planted.txt', [self::PROJECT.'/pwn']);
            $this->fail('The file under the link was not refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                "Refusing to write '".self::PROJECT."/pwn/planted.txt': a directory above it is a symlink or leads out of the account's home.",
                $e->getMessage()
            );
        }

        $this->assertSame([], $this->ranWith('mkdir'));
        $this->assertSame([], $this->ranWith('sudo cp'));
    }

    public function test_a_link_further_up_is_found_too(): void
    {
        $this->expectExceptionMessage('a directory above it is a symlink');

        $this->install('vendor/pkg/config/app.php', [self::PROJECT.'/vendor']);
    }

    public function test_a_file_under_real_folders_is_still_written(): void
    {
        $this->install('config/app/settings.yml', []);

        $this->assertCount(1, $this->ranWith("'mkdir' '-p' '".self::PROJECT."/config/app'"));
        $this->assertCount(1, $this->ranWith('sudo cp'));
    }

    /** @return list<string> */
    private function ranWith(string $needle): array
    {
        return array_values(array_filter($this->ran, static fn (string $command): bool => str_contains($command, $needle)));
    }

    /** @param list<string> $links */
    private function install(string $path, array $links): void
    {
        $appConfig = AppConfig::fromDirectory(['files' => [['path' => $path, 'contents' => "A=1\n"]]]);
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
        $dind->method('system')->willReturn($this->system($links));

        $dind->installFileSnippets();
    }

    /** @param list<string> $links */
    private function system(array $links): System
    {
        $ran = &$this->ran;

        return new class($links, $ran) extends System
        {
            /**
             * @param  list<string>  $links
             * @param  list<string>  $ran
             */
            public function __construct(private array $links, private array &$ran) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = implode(' ', (array) $cmd);

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = (array) $cmd;
                $this->ran[] = implode(' ', $argv);
                if (array_slice($argv, 0, 3) === ['sudo', 'test', '-L'] && ! in_array($argv[3], $this->links, true)) {
                    $process = new Process(['php', '-r', 'exit(1);']);
                    $process->run();

                    return $process;
                }

                return SuccessfulProcess::for($cmd);
            }
        };
    }
}
