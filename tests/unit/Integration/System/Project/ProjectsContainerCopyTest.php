<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\Domain as DomainModel;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\CopyVolumes;
use App\System\Project\Domain as DomainProject;
use App\System\Project\PhpHosting\WordPress\ContainerSiteCopy;
use App\System\Project\Runtime;
use App\System\Projects;
use Illuminate\Support\Facades\Log;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\FakeWordPressSite;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** A staging copy of a container project: images first, then the run file and env, then the app's own start. */
class ProjectsContainerCopyTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    /** @var list<string> */
    private array $steps = [];

    /** @var array{stdout: string, stderr: string, exit_code: int} */
    private array $startResult = ['stdout' => '', 'stderr' => '', 'exit_code' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->root = sys_get_temp_dir() . '/pa-container-copy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/live', 0777, true);
        mkdir($this->root . '/stg/project', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_container_copy_moves_images_while_the_source_runs_and_takes_the_port_its_run_file_publishes(): void
    {
        file_put_contents($this->root . '/stg/project/docker-compose.panelalpha.yml', "services:\n  app:\n    image: app\n    ports:\n      - \"8000:3000\"\n");
        $this->stagingPair();

        $this->projects()->copy('live', 'stg');

        $this->assertSame([
            'stg copyHomeDirFrom live while running',
            'stg provideCopiedImages',
            'stg copyHomeDirFrom live',
            'stg repointCopiedRunFile live',
            'stg adoptCopiedEnvVars live',
            'stg repointCopiedAppFiles live',
            'stg startUserApp',
        ], $this->steps);
        $stg = User::findByUsernameOrFail('stg');
        $this->assertSame(8000, $stg->getAppPort());
        $this->assertSame('active', $stg->status);
    }

    public function test_a_copy_whose_run_file_publishes_no_port_keeps_the_sources(): void
    {
        $this->stagingPair(['app_port' => 8080]);

        $this->projects()->copy('live', 'stg');

        $this->assertSame(8080, User::findByUsernameOrFail('stg')->getAppPort());
    }

    public function test_a_copy_whose_app_does_not_start_fails_with_the_apps_output(): void
    {
        $this->startResult = ['stdout' => 'starting', 'stderr' => 'port 3000 in use', 'exit_code' => 1];
        $this->stagingPair();

        try {
            $this->projects()->copy('live', 'stg');
            $this->fail('a copy whose app did not start succeeded');
        } catch (\RuntimeException $e) {
            $this->assertSame('port 3000 in use starting', $e->getMessage());
        }

        $stg = User::findByUsernameOrFail('stg');
        $this->assertSame('port 3000 in use starting', $stg->getDetails()['error'] ?? null);
        $this->assertSame('failed', $stg->asyncStatus()['staging'] ?? null);
    }

    public function test_a_start_that_says_nothing_still_fails_the_copy(): void
    {
        $this->startResult = ['stdout' => '', 'stderr' => '', 'exit_code' => 137];
        $this->stagingPair();

        $this->expectExceptionMessage('Failed to start application.');
        $this->projects()->copy('live', 'stg');
    }

    public function test_a_copied_wordpress_on_another_projects_database_is_left_as_it_is(): void
    {
        Log::spy();
        $this->stagingPair();

        $this->projects(databaseOwner: 999)->copy('live', 'stg');

        $this->assertSame('active', User::findByUsernameOrFail('stg')->status);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === "WordPress in 'stg' uses a database another project owns; its addresses were not rewritten."
        )->once();
    }

    public function test_a_clone_into_a_pending_project_is_refused_before_anything_is_copied(): void
    {
        $this->stagingPair();
        $live = User::findByUsernameOrFail('live');
        $stg = User::findByUsernameOrFail('stg');

        try {
            $this->projects()->clone($live, $stg);
            $this->fail('a clone into a pending project went ahead');
        } catch (\RuntimeException $e) {
            $this->assertSame("Project 'stg' is pending.", $e->getMessage());
        }

        $this->assertSame([], $this->steps);
        $this->assertSame('failed', User::findByUsernameOrFail('stg')->getDetails()['deployment_status'] ?? null);
    }

    public function test_a_copy_into_a_project_without_its_domain_row_fails_before_it_is_served(): void
    {
        $this->stagingPair();
        DomainModel::query()->where('domain', 'staging.shop.test')->delete();

        try {
            $this->projects()->copy('live', 'stg');
            $this->fail('a copy without a domain row was served');
        } catch (\RuntimeException $e) {
            $this->assertSame('Destination has no domain row.', $e->getMessage());
        }

        $this->assertNotContains('stg startUserApp', $this->steps);
    }

    public function test_a_staging_project_cannot_be_the_source_of_another_staging_copy(): void
    {
        $this->stagingPair();
        $stg = User::findByUsernameOrFail('stg');
        $stg->status = 'active';
        $stg->save();
        $third = $this->makeUser('third', ['template' => 'dind']);
        $third->staging = $stg->id;
        $third->save();

        $this->expectExceptionMessage("Project 'stg' is a staging project and cannot create staging.");
        Projects::assertCanCopyIntoStaging($stg, $third);
    }

    /** @param array<string, mixed> $liveDetails */
    private function stagingPair(array $liveDetails = []): void
    {
        $live = $this->makeUser('live', ['template' => 'dind', ...$liveDetails], 'shop.example');
        $this->makeMainDomain($live, 'shop.example');
        $stg = $this->makeUser('stg', ['template' => 'dind'], 'staging.shop.test');
        $stg->staging = $live->id;
        $stg->status = 'pending';
        $stg->save();
        $this->makeMainDomain($stg, 'staging.shop.test');
    }

    private function projects(?int $databaseOwner = null): Projects
    {
        $site = new FakeWordPressSite(
            ['DB_NAME' => 'live_app'],
            ['home' => 'https://shop.example', 'siteurl' => 'https://shop.example'],
        );
        $siteCopy = new ContainerSiteCopy(
            static fn (User $user): \App\System\Project\PhpHosting\WordPress\WordPress => $site->wordPress(),
            static fn (string $database): ?int => $databaseOwner,
        );

        return new class ($this->system(), $siteCopy) extends Projects {
            public function __construct(System $system, private ContainerSiteCopy $fakeSiteCopy)
            {
                parent::__construct($system);
            }

            protected function containerSiteCopy(): ContainerSiteCopy
            {
                return $this->fakeSiteCopy;
            }
        };
    }

    private function system(): System
    {
        $steps = &$this->steps;
        $startResult = &$this->startResult;

        return new class ($this->root, $steps, $startResult) extends System {
            /**
             * @param list<string> $steps
             * @param array{stdout: string, stderr: string, exit_code: int} $startResult
             */
            public function __construct(private string $root, private array &$steps, private array &$startResult)
            {
            }

            public function project(User $model): Project
            {
                $steps = &$this->steps;
                $startResult = &$this->startResult;

                return new ContainerCopyProject($this, $model->username, $this->root . '/' . $model->username, $steps, $startResult);
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null,
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? array_values($cmd) : [$cmd];
                $plain = LocalRoot::isRequest($argv) ? LocalRoot::plain($argv) : $argv;
                $path = (string) end($plain);

                return FakeProcess::of(match (array_slice($plain, 0, 2)) {
                    ['test', '-d'] => is_dir($path) ? 0 : 1,
                    ['test', '-e'] => file_exists($path) ? 0 : 1,
                    ['test', '-f'] => is_file($path) ? 0 : 1,
                    ['test', '-L'] => is_link($path) ? 0 : 1,
                    default => 0,
                });
            }
        };
    }
}

/** @internal A container project whose every step is recorded, with a Dind runtime that only knows its home. */
final class ContainerCopyProject extends Project
{
    /**
     * @param list<string> $steps
     * @param array{stdout: string, stderr: string, exit_code: int} $startResult
     */
    public function __construct(
        private System $host,
        private string $name,
        private string $home,
        private array &$steps,
        private array &$startResult,
    ) {
    }

    public function system(): System
    {
        return $this->host;
    }

    public function homeDirPath(): string
    {
        return $this->home;
    }

    public function runtime(): Runtime
    {
        $runtime = Mockery::mock(Dind::class);
        $runtime->shouldReceive('homeDirPath')->andReturn($this->home);
        $runtime->shouldReceive('system')->andReturn($this->host);

        return $runtime;
    }

    public function domain(DomainModel $domainModel): DomainProject
    {
        return new class ($this, $domainModel) extends DomainProject {
            public function create(): void
            {
            }
        };
    }

    public function copyVolumesForClone(): ?CopyVolumes
    {
        return null;
    }

    public function copyVolumeDataFrom(Project $source): int
    {
        return 0;
    }

    public function anonymousVolumeWarnings(): array
    {
        return [];
    }

    public function hasUserApp(): bool
    {
        return true;
    }

    public function startUserApp(bool $forceRecreate = false): array
    {
        $this->steps[] = "{$this->name} startUserApp";

        return $this->startResult;
    }

    public function createDirectories(): void
    {
    }

    public function createLinuxUser(): array
    {
        return ['UID' => 2001, 'GID' => 2002];
    }

    public function createFromTemplate(): void
    {
    }

    public function start(): void
    {
    }

    public function fixPermissions(): void
    {
    }

    public function configureQuota(): bool
    {
        return true;
    }

    public function waitForAllRunning(int $tries = 12, int $intervalSeconds = 5): void
    {
    }

    public function copyHomeDirFrom(Project $source, bool $sourceRunning = false): void
    {
        $this->steps[] = "{$this->name} copyHomeDirFrom " . basename($source->homeDirPath()) . ($sourceRunning ? ' while running' : '');
    }

    public function copyProjectConfigFrom(Project $source): void
    {
    }

    public function provideCopiedImages(?string $path = null): void
    {
        $this->steps[] = "{$this->name} provideCopiedImages";
    }

    public function repointCopiedRunFile(User $source, ?string $path = null): void
    {
        $this->steps[] = "{$this->name} repointCopiedRunFile {$source->username}";
    }

    public function adoptCopiedEnvVars(User $source): void
    {
        $this->steps[] = "{$this->name} adoptCopiedEnvVars {$source->username}";
    }

    public function repointCopiedAppFiles(User $source): void
    {
        $this->steps[] = "{$this->name} repointCopiedAppFiles {$source->username}";
    }

    public function syncGeneratedProxyRules(): void
    {
    }
}
