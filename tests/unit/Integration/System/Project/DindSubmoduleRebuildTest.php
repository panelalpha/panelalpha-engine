<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Support\TestableGitRepository;

/**
 * A rebuild clones ~/project afresh: a submodule it cannot fetch is left out
 * with a warning naming it, unless the running app has a checkout of it the
 * re-clone would drop, which fails the rebuild and leaves that app running.
 */
class DindSubmoduleRebuildTest extends TestCase
{
    private const REPO = 'https://github.com/org/panel.git';

    private const DEPLOYED = '0123456789abcdef0123456789abcdef01234567';

    private string $checkout;

    private bool $wiped = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->checkout = sys_get_temp_dir() . '/pa-dind-rebuild-' . bin2hex(random_bytes(4));
        mkdir($this->checkout, 0755, true);
        file_put_contents($this->checkout . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n\turl = ../lib.git\n");
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->checkout);
        DeployLogger::deleteUserLogs('alice');
        parent::tearDown();
    }

    public function test_a_rebuild_goes_on_without_a_submodule_the_previous_checkout_declared_but_never_had(): void
    {
        $warnings = $this->warningsOf(fn () => $this->repository('success', before: ['lib'], checkedOut: [])
            ->cloneConfiguredRepository(static function (): void {}));

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $warnings[0]);
        $this->assertStringEndsWith('The deploy goes on without lib.', $warnings[0]);
    }

    public function test_a_rebuild_that_would_lose_the_running_apps_submodule_checkout_fails(): void
    {
        try {
            $this->repository('success', before: ['lib'], checkedOut: ['lib'])->cloneConfiguredRepository(static function (): void {});
            $this->fail('a rebuild that drops a submodule the app had must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage());
            $this->assertStringContainsString('the checkout of lib the running app has cannot be kept', $e->getMessage());
            $this->assertSame('submodule-fetch-failed', DeployFailureExplainer::match($e->getMessage())['rule'] ?? null);
        }
    }

    public function test_a_rebuild_whose_commit_adds_a_submodule_it_cannot_fetch_goes_on_without_it(): void
    {
        $warnings = $this->warningsOf(fn () => $this->repository('success', before: [], checkedOut: [])
            ->cloneConfiguredRepository(static function (): void {}));

        $this->assertCount(1, $warnings);
        $this->assertStringEndsWith('The deploy goes on without lib.', $warnings[0]);
    }

    /** A project whose app never ran has no checkout to protect, so even one it had checked out is left out. */
    public function test_a_rebuild_of_a_project_that_never_ran_goes_on_without_the_submodule(): void
    {
        $warnings = $this->warningsOf(fn () => $this->repository('failed', before: ['lib'], checkedOut: ['lib'])
            ->cloneConfiguredRepository(static function (): void {}));

        $this->assertCount(1, $warnings);
        $this->assertStringEndsWith('The deploy goes on without lib.', $warnings[0]);
    }

    /** A clone into an emptied ~/project has no checkout to keep, the first deploy's or any other. */
    public function test_a_clone_into_an_emptied_project_goes_on_without_a_submodule_it_cannot_fetch(): void
    {
        foreach (['first deploy' => null, 'deployed project' => 'success'] as $case => $deploymentStatus) {
            $this->wiped = false;
            $warnings = $this->warningsOf(fn () => $this->repository($deploymentStatus, before: ['lib'], checkedOut: ['lib'])
                ->cloneConfiguredRepository());

            $this->assertCount(1, $warnings, $case);
            $this->assertStringEndsWith('The deploy goes on without lib.', $warnings[0], $case);
        }
    }

    /** @return list<string> */
    private function warningsOf(callable $deploy): array
    {
        $logger = DeployLogger::start('alice');
        try {
            $deploy();

            return $this->warnings($logger);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    /** @return list<string> */
    private function warnings(DeployLogger $logger): array
    {
        return array_values(array_map(
            static fn (array $l): string => $l['msg'],
            array_filter($logger->read()['lines'], static fn (array $l): bool => $l['level'] === 'warn'),
        ));
    }

    /**
     * @param list<string> $before the submodules the replaced checkout declared
     * @param list<string> $checkedOut those of them it had checked out
     */
    private function repository(?string $deploymentStatus, array $before, array $checkedOut): TestableGitRepository
    {
        return new TestableGitRepository(
            $this->dind($deploymentStatus),
            $this->recloneWithUnfetchableSubmodule($before, $checkedOut),
            null,
            $this->checkout,
        );
    }

    /**
     * Until ~/project is wiped, git there answers for the checkout being replaced, at any commit;
     * from then on, for the fresh clone, which declares `lib` and has nothing checked out. The
     * submodule fetch fails.
     *
     * @param list<string> $before
     * @param list<string> $checkedOut
     */
    private function recloneWithUnfetchableSubmodule(array $before, array $checkedOut): callable
    {
        return function (array $command) use ($before, $checkedOut): string {
            $joined = implode(' ', $command);
            if (in_array('submodule', $command, true)) {
                throw new GitException("fatal: repository 'https://github.com/org/lib.git/' not found", 400);
            }

            return match (true) {
                str_contains($joined, 'config --blob ') => implode('', array_map(
                    static fn (string $path): string => "submodule.{$path}.path\n{$path}\0",
                    $this->wiped ? ['lib'] : $before,
                )),
                str_contains($joined, 'ls-files --recurse-submodules') => $this->wiped ? '' : implode('', array_map(
                    static fn (string $path): string => "{$path}/index.php\0",
                    $checkedOut,
                )),
                str_contains($joined, 'rev-parse --is-inside-work-tree') => "true\n",
                str_contains($joined, 'config --get remote.origin.url') => self::REPO . "\n",
                str_contains($joined, 'branch --show-current') => "main\n",
                default => '',
            };
        };
    }

    private function dind(?string $deploymentStatus): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(array_filter([
            'template' => 'dind',
            'git_repo' => self::REPO,
            'deployment_status' => $deploymentStatus,
            'git_commit' => self::DEPLOYED,
        ]));

        $runtime = (new ProjectAggregate($this->recordingSystem(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** ~/project's moves and wipes are not run: the fake clone above stands in for them, and learns of the wipe. */
    private function recordingSystem(): System
    {
        return new class (fn () => $this->wiped = true) extends System {
            public function __construct(private \Closure $wipe)
            {
            }

            public function homesDirPath(): string
            {
                return '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (is_array($cmd) && LocalRoot::isRequest($cmd) && array_slice(LocalRoot::plain($cmd), 0, 2) === ['find', '/home/alice/project']) {
                    ($this->wipe)();
                }

                return '';
            }

            /** No file the account could hold is there. */
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $missing = new Process(['php', '-r', 'exit(1);']);
                $missing->run();

                return $missing;
            }
        };
    }
}
