<?php

namespace Tests\Unit\Console;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Project\ArchiveDrop;
use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * `pae project:deploy-archive`: the archive is checked, put into the project,
 * deployed by the same rebuild the API runs, and removed whatever happens.
 */
class DeployArchiveCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $archive;

    /** @var list<array{0: string, 1: mixed}> */
    private array $calls = [];

    private ?\Throwable $runFails = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->bootInMemoryDatabase();
        $this->archive = sys_get_temp_dir() . '/pa-archive-test-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($this->archive, 'PK');
        $this->bindDoubles();
    }

    protected function tearDown(): void
    {
        @unlink($this->archive);
        parent::tearDown();
    }

    public function test_the_archive_is_deployed_through_the_rebuild_and_removed(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);

        $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--quiet-deploy' => true])
            ->expectsOutputToContain('Deployed ' . $this->archive . ' to alice.')
            ->assertExitCode(0);

        $this->assertSame(['put', 'assertArchive', 'run', 'remove'], array_column($this->calls, 0));
        $this->assertSame('/.panelalpha/archives/drop.zip', $this->calls[2][1]);
    }

    public function test_env_vars_are_merged_into_the_project_before_the_deploy(): void
    {
        $this->makeUser('alice', ['template' => 'dind', 'env_vars' => ['KEEP' => '1']]);

        $this->artisan('project:deploy-archive', [
            '--project' => 'alice', '--file' => $this->archive, '--quiet-deploy' => true, '--env-var' => ['FOO=bar'],
        ])->assertExitCode(0);

        $this->assertSame(['KEEP' => '1', 'FOO' => 'bar'], User::findByUsername('alice')?->getEnvVars());
    }

    public function test_a_failed_deploy_still_removes_the_archive(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $this->runFails = new \RuntimeException('build failed');

        try {
            $this->artisan('project:deploy-archive', ['--project' => 'alice', '--file' => $this->archive, '--quiet-deploy' => true])->run();
            $this->fail('The deploy failure should reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('build failed', $e->getMessage());
        }

        $this->assertSame('remove', $this->calls[array_key_last($this->calls)][0]);
    }

    public function test_a_classic_project_is_refused_before_anything_is_put(): void
    {
        $this->makeUser('bob', ['template' => 'default']);

        $this->expectException(ValidationException::class);
        try {
            $this->artisan('project:deploy-archive', ['--project' => 'bob', '--file' => $this->archive])->run();
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    public function test_a_git_project_is_refused_before_anything_is_put(): void
    {
        $this->makeUser('carol', ['template' => 'dind', 'git_repo' => 'https://github.com/owner/repo.git']);

        try {
            $this->artisan('project:deploy-archive', ['--project' => 'carol', '--file' => $this->archive])->run();
            $this->fail('A git project should be refused.');
        } catch (ProblemException $e) {
            $this->assertSame('archive_on_git_project', $e->problems[0]['code'] ?? null);
        }
        $this->assertSame([], $this->calls);
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badInvocations')]
    public function test_a_bad_invocation_is_a_plain_error_and_nothing_is_put(array $options, string $message): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $options = array_map(fn (mixed $value): mixed => $value === '@archive' ? $this->archive : $value, $options);

        $this->artisan('project:deploy-archive', $options)
            ->expectsOutputToContain($message)
            ->assertExitCode(1);
        $this->assertSame([], $this->calls);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInvocations(): array
    {
        return [
            'no project' => [['--file' => '@archive'], '--project is required'],
            'no file' => [['--project' => 'alice'], '--file is required'],
            'missing file' => [['--project' => 'alice', '--file' => '/nonexistent/app.zip'], 'Archive not found'],
            'unsupported format' => [['--project' => 'alice', '--file' => __FILE__], 'Unsupported archive format'],
            'malformed env var' => [['--project' => 'alice', '--file' => '@archive', '--env-var' => ['NOEQUALS']], 'expects KEY=VALUE'],
        ];
    }

    private function bindDoubles(): void
    {
        $test = $this;
        $this->app->instance(ArchiveDrop::class, new class ($test) extends ArchiveDrop {
            public function __construct(private DeployArchiveCommandTest $test)
            {
            }

            public function put(User $user, string $file, string $extension): string
            {
                $this->test->record('put', $extension);

                return '/.panelalpha/archives/drop' . $extension;
            }

            public function remove(User $user, string $zipPath): void
            {
                $this->test->record('remove', $zipPath);
            }
        });
        $this->app->instance(ProjectRebuild::class, new class ($test) extends ProjectRebuild {
            public function __construct(private DeployArchiveCommandTest $test)
            {
            }

            public function assertArchive(User $user, string $zipPath): void
            {
                $this->test->record('assertArchive', $zipPath);
            }

            public function whileIdle(User $user, callable $start): mixed
            {
                return $start();
            }

            public function openLog(User $user, string $action): ?DeployLogger
            {
                return null;
            }

            public function run(User $user, string $action, ?DeployLogger $logger, ?string $zipPath, array $git = []): array
            {
                $this->test->record('run', $zipPath);
                $this->test->failRunIfAsked();

                return [];
            }
        });
    }

    public function record(string $call, mixed $argument): void
    {
        $this->calls[] = [$call, $argument];
    }

    public function failRunIfAsked(): void
    {
        if ($this->runFails !== null) {
            throw $this->runFails;
        }
    }
}
