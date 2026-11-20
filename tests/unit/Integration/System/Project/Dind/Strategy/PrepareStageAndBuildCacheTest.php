<?php

namespace Tests\Integration\System\Project\Dind\Strategy;

use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User;
use App\System;
use App\System\Filesystem as SystemFilesystem;
use App\System\Project\Dind;
use App\System\Project\Dind\ShellOperations;
use App\System\Project\Dind\Strategy\PrepareStage;
use App\System\Project\Dind\Strategy\ProjectBuildCache;
use Symfony\Component\Process\Process;
use Tests\Integration\System\Project\SuccessfulProcess;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * A platform's prepare commands run as the account from a script that is removed again, and a build imports the
 * host-built cache of its repository and branch only when the cache registry holds one.
 */
class PrepareStageAndBuildCacheTest extends TestCase
{
    use StrategyHarness;

    private const REPOSITORY = 'https://github.com/acme/app.git';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_prepare_commands_run_from_a_script_that_is_removed_afterwards(): void
    {
        $manifest = PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'detect' => ['file' => 'demo.json'],
            'commands' => [['id' => 'seed', 'stage' => 'prepare', 'run' => 'echo seeded > seed.txt']],
        ], 'demo.yaml');

        $ran = (new PrepareStage($this->streamingDind()))->run($this->dir, $manifest, null);

        $this->assertTrue($ran);
        $script = $this->dir.'/'.AppConfig::SETUP_SCRIPT;
        $events = array_map(static fn (string $event): string => (string) preg_replace('/^\[\d+ log lines\] /', '', $event), $this->events);
        $this->assertCount(3, $events);
        $this->assertSame("write {$script} 1001:1001 644", strtok($events[0], "\n"));
        $this->assertStringContainsString('echo seeded > seed.txt', $events[0]);
        $this->assertStringStartsWith('stream su -s /bin/bash '.$this->username.' -c ', $events[1]);
        $this->assertStringContainsString($script, $events[1]);
        $this->assertStringContainsString('timeout --foreground --kill-after=30 3540 bash', $events[1]);
        $this->assertStringContainsString("rm -f {$script}", $events[2]);
        $this->assertSame(['info: Running prepare commands', 'ok: Prepare commands finished'], array_slice($this->logLines(), -2));
    }

    public function test_a_platform_without_prepare_commands_runs_nothing(): void
    {
        $ran = (new PrepareStage($this->streamingDind()))->run($this->dir, null, null);

        $this->assertFalse($ran);
        $this->assertSame([], $this->events);
    }

    public function test_the_cache_of_the_checked_out_branch_is_imported_when_the_registry_holds_it(): void
    {
        $this->putFile('.git/HEAD', "ref: refs/heads/main\n");
        $ref = (string) BuildCache::ref(self::REPOSITORY, 'main', 'Dockerfile');
        $asked = [];

        $from = $this->cache(['git_repo' => self::REPOSITORY], function (string $url) use (&$asked): bool {
            $asked[] = $url;

            return true;
        })->cacheFrom($this->dir, 'Dockerfile');

        $this->assertSame([BuildCache::cacheFrom($ref)], $from);
        $this->assertCount(1, $asked);
        $this->assertContains("info: Using build cache {$ref} from the cache registry", $this->logLines());
    }

    public function test_a_cache_the_registry_does_not_hold_is_not_imported(): void
    {
        $ref = (string) BuildCache::ref(self::REPOSITORY, 'release', 'Dockerfile');

        $from = $this->cache(['git_repo' => self::REPOSITORY, 'git_branch' => 'release'], static fn (): bool => false)
            ->cacheFrom($this->dir, 'Dockerfile');

        $this->assertSame([], $from);
        $this->assertContains("dim: No build cache {$ref} in the cache registry", $this->logLines());
    }

    public function test_a_project_without_a_repository_has_no_cache_to_import(): void
    {
        $this->assertSame([], $this->cache([], static fn (): bool => true)->cacheFrom($this->dir, 'Dockerfile'));
    }

    public function test_the_branch_comes_only_from_a_symbolic_head(): void
    {
        $this->assertSame('feature/x', ProjectBuildCache::checkedOutBranch("ref: refs/heads/feature/x\n"));
        $this->assertNull(ProjectBuildCache::checkedOutBranch(str_repeat('a', 40)."\n"));
        $this->assertNull(ProjectBuildCache::checkedOutBranch(null));
    }

    /** A Dind whose writes and streamed steps are recorded, nothing run. */
    private function streamingDind(): Dind
    {
        $harness = $this;
        $system = new class($harness) extends System
        {
            public function __construct(private object $harness) {}

            public function filesystem(): SystemFilesystem
            {
                return new class($this, $this->harness) extends SystemFilesystem
                {
                    public function __construct(System $engine, private object $harness)
                    {
                        parent::__construct($engine);
                    }

                    public function writeFileReplacingPath(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->harness->record("write {$path} {$chown} {$chmod}", $contents);
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->harness->record('exec '.implode(' ', LocalRoot::inAccount((array) $cmd) ?: (array) $cmd), '');

                return '';
            }

            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                $this->harness->record('stream '.implode(' ', LocalRoot::inAccount((array) $cmd) ?: (array) $cmd), '');

                return SuccessfulProcess::for($cmd);
            }
        };
        $model = new User;
        $model->username = $this->username;
        $model->details = ['UID' => 1001, 'GID' => 1001];

        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn($this->username);
        $dind->method('userModel')->willReturn($model);
        $dind->method('homeDirPath')->willReturn('/home/'.$this->username);
        $dind->method('composeFilePath')->willReturn('/opt/users/'.$this->username.'/docker-compose.yml');
        $dind->method('system')->willReturn($system);
        $dind->method('shell')->willReturnCallback(fn (): ShellOperations => new ShellOperations($dind));

        return $dind;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  callable(string, string): bool  $exists
     */
    private function cache(array $details, callable $exists): ProjectBuildCache
    {
        $cache = new ProjectBuildCache($this->harnessDind($details));
        (new \ReflectionProperty(ProjectBuildCache::class, 'registry'))->setValue($cache, new BuildCache($exists));

        return $cache;
    }

    /** @return list<string> */
    private function logLines(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['level'].': '.$entry['msg'],
            $this->logger?->entries() ?? []
        );
    }
}
