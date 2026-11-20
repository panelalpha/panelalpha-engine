<?php

namespace Tests\Integration\System\Project\Dind;

use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Run files AppPortAlignment leaves alone before it measures anything: no
 * generated file, no first port to read, or one that does not parse.
 */
class AppPortAlignmentRunFileTest extends TestCase
{
    private const RUN_PATH = '/home/acme/project/docker-compose.panelalpha.yml';

    /** @var list<string> */
    private array $ran = [];

    public function test_a_project_that_runs_its_own_compose_file_is_not_realigned(): void
    {
        $this->assertFalse($this->alignTo("services:\n  app:\n    ports:\n      - \"8080:8080\"\n", '/home/acme/project/docker-compose.yml'));
        $this->assertSame([], $this->ran);
    }

    /** @return iterable<string, array{string}> */
    public static function runFilesWithoutAFirstPort(): iterable
    {
        yield 'empty' => [''];
        yield 'not a mapping' => ["just text\n"];
        yield 'no app service' => ["services:\n  web:\n    ports:\n      - \"8080:8080\"\n"];
        yield 'no ports' => ["services:\n  app:\n    image: nginx\n"];
        yield 'ports not a list' => ["services:\n  app:\n    ports: \"8080:8080\"\n"];
        yield 'long-form port' => ["services:\n  app:\n    ports:\n      - target: 8080\n        published: 8080\n"];
        yield 'unparsable port' => ["services:\n  app:\n    ports:\n      - \"not-a-port\"\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('runFilesWithoutAFirstPort')]
    public function test_a_run_file_without_a_first_port_is_left_alone(string $yaml): void
    {
        $this->assertFalse($this->alignTo($yaml));
        $this->assertSame([], $this->ran);
    }

    public function test_a_run_file_that_does_not_parse_is_logged_and_left_alone(): void
    {
        Log::spy();

        $this->assertFalse($this->alignTo("services:\n  app: [\n"));
        $this->assertSame([], $this->ran);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message): bool => str_starts_with($message, 'Could not align the published app port: ')
        );
    }

    private function alignTo(string $runFile, string $toRun = self::RUN_PATH): bool
    {
        $ran = &$this->ran;
        $system = new class ($runFile, $ran) extends System {
            /** @param list<string> $ran */
            public function __construct(private string $runFile, private array &$ran)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                return new class ($this, $this->runFile) extends SystemFilesystem {
                    public function __construct(System $engine, private string $runFile)
                    {
                        parent::__construct($engine);
                    }

                    public function isSymlink(string $path): bool
                    {
                        return false;
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->runFile;
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->ran[] = is_array($cmd) ? implode(' ', $cmd) : $cmd;

                return '';
            }
        };

        $dind = $this->createStub(Dind::class);
        $dind->method('system')->willReturn($system);
        $dind->method('username')->willReturn('acme');
        $dind->method('composeFilePath')->willReturn('/home/acme/docker-compose.yml');
        $dind->method('userAppComposeFilePath')->willReturn(self::RUN_PATH);
        $dind->method('userAppComposeFileToRun')->willReturn($toRun);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return (new AppPortAlignment($dind, static function (int $seconds): void {
        }))->alignTo('next1');
    }
}
