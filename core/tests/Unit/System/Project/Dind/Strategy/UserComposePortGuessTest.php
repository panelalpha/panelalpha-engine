<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\DeployStrategy;
use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\AccountSecrets;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Qdrant's recipe: `port: 6333`, and a compose publishing 6333 on a datastore
 * image the port scan refuses. The domain goes to the recipe's port, so the
 * "routed to 8080, which is a guess" warning was false twice.
 */
class UserComposePortGuessTest extends TestCase
{
    private const PROJECT_DIR = '/home/acme/project';

    private const GUESS = 'No service publishes a port and none names one, so the domain is routed to 8080, which is a guess.';

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->username = 'portguess' . bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
    }

    public function test_no_guess_is_reported_when_the_recipes_port_routes_the_domain(): void
    {
        $lines = $this->refresh(['deploy_strategy' => 'compose', 'deploy_port' => 6333]);

        $this->assertSame([], array_filter($lines, static fn (string $l): bool => str_starts_with($l, self::GUESS)), implode("\n", $lines));
    }

    public function test_the_guess_is_still_reported_without_a_recipe_port(): void
    {
        $lines = $this->refresh(['deploy_strategy' => 'compose']);

        $this->assertNotSame([], array_filter($lines, static fn (string $l): bool => str_starts_with($l, self::GUESS)), implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $details
     * @return list<string>
     */
    private function refresh(array $details): array
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $files = [$clientPath => "services:\n  qdrant:\n    image: qdrant/qdrant\n    ports:\n      - 6333:6333\n"];
        $logger = DeployLogger::start($this->username);
        $logger->stage(DeployLogger::STAGE_RUNNING);

        $system = new class ($files) extends System {
            /** @param array<string, string> $files */
            public function __construct(private array $files)
            {
            }

            public function filesystem(): SystemFilesystem
            {
                $files = $this->files;

                return new class ($this, $files) extends SystemFilesystem {
                    /** @param array<string, string> $files */
                    public function __construct(System $engine, private array $files)
                    {
                        parent::__construct($engine);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? '';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $process = new Process(['php', '-r', 'exit(0);']);
                $process->run();

                return $process;
            }
        };

        $model = new User();
        $model->username = $this->username;
        $model->details = ['UID' => 1001, 'GID' => 1001] + $details;

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('system')->willReturn($system);
        $dind->method('publicAppUrl')->willReturn(null);
        $dind->method('userAppComposeFilePath')->willReturn(self::PROJECT_DIR . '/docker-compose.panelalpha.yml');
        $dind->method('userAppExistingComposeFilePath')->willReturn($clientPath);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));
        $dind->method('projectTree')->willReturnCallback(fn (): ProjectFiles => new ProjectFiles($dind));
        $dind->method('strategy')->willReturn(new class ($dind) extends DeployStrategy {
            public function secrets(): AccountSecrets
            {
                return new class ($this) extends AccountSecrets {
                    public function __construct(object $unused)
                    {
                    }

                    public function userEnvVars(): array
                    {
                        return [];
                    }

                    public function for(string $purpose): string
                    {
                        return 'stub-secret:' . $purpose;
                    }
                };
            }
        });

        (new UserComposeStrategy($dind))->refreshRunFile(self::PROJECT_DIR, '1001:1001');

        return array_map(static fn (array $entry): string => $entry['msg'], $logger->entries());
    }
}
