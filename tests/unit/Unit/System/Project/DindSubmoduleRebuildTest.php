<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git\Exception as GitException;
use Tests\TestCase;

/**
 * A rebuild clones ~/project afresh, so it has no submodule checkout to keep:
 * one the replaced checkout declared but never had is left out with a warning,
 * one it had checked out, or one new in the commit, fails the rebuild.
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
        $logger = DeployLogger::start('alice');
        try {
            $this->repository('success', before: ['lib'], checkedOut: [])->cloneConfiguredRepository(static function (): void {});
            $warnings = $this->warnings($logger);
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $warnings[0]);
        $this->assertStringContainsString('The deploy goes on without lib: there is no earlier checkout of it to keep.', $warnings[0]);
    }

    public function test_a_rebuild_that_would_lose_a_submodule_checkout_fails(): void
    {
        try {
            $this->repository('success', before: ['lib'], checkedOut: ['lib'])->cloneConfiguredRepository(static function (): void {});
            $this->fail('a rebuild that drops a submodule the app had must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage());
            $this->assertStringContainsString('the checkout of lib the previous deploy had cannot be kept', $e->getMessage());
            $this->assertSame('submodule-fetch-failed', DeployFailureExplainer::match($e->getMessage())['rule'] ?? null);
        }
    }

    public function test_a_rebuild_whose_commit_adds_a_submodule_it_cannot_fetch_fails(): void
    {
        try {
            $this->repository('success', before: [], checkedOut: [])->cloneConfiguredRepository(static function (): void {});
            $this->fail('a submodule new in the commit must fail the rebuild');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage());
            $this->assertStringNotContainsString('cannot be kept', $e->getMessage());
        }
    }

    /** A clone into an emptied ~/project has no checkout to keep, the first deploy's or any other. */
    public function test_a_clone_into_an_emptied_project_still_fails_on_a_submodule_it_cannot_fetch(): void
    {
        foreach (['first deploy' => null, 'deployed project' => 'success'] as $case => $deploymentStatus) {
            $this->wiped = false;
            try {
                $this->repository($deploymentStatus, before: ['lib'], checkedOut: [])->cloneConfiguredRepository();
                $this->fail("{$case}: the clone went on without lib");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage(), $case);
            }
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
                if (is_array($cmd) && array_slice($cmd, 0, 3) === ['sudo', 'find', '/home/alice/project']) {
                    ($this->wipe)();
                }

                return '';
            }
        };
    }
}
