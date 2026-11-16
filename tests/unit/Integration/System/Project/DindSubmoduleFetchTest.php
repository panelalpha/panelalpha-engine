<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Git\DeployKey;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\Support\LocalRoot;
use Tests\TestCase;
use Tests\Support\FakeGitRunner;
use Tests\Support\TestableGitRepository;

/**
 * Submodules come down after the clone and after every change of the checkout,
 * limited by the app config when it names them, and one that cannot be
 * fetched is left out with a warning naming it.
 */
class DindSubmoduleFetchTest extends TestCase
{
    private const SUBMODULE = 'packages/client-area';

    private const DEPLOYED = '0123456789abcdef0123456789abcdef01234567';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir() . '/pa-dind-submodules-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/project', 0755, true);
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"packages/client-area\"]\n\tpath = packages/client-area\n");
    }

    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
        parent::tearDown();
    }

    public function test_every_declared_submodule_is_fetched_by_default(): void
    {
        $runner = new FakeGitRunner();

        $this->repository($runner)->fetchSubmodules('https://github.com/org/panel.git', 'pat');

        $this->assertSame(
            ['submodule', 'update', '--init', '--recursive', '--depth=1'],
            array_slice($this->submoduleCommand($runner), -5)
        );
    }

    public function test_the_app_config_limits_the_submodules(): void
    {
        file_put_contents($this->projectDir() . '/panelalpha.yaml', "git:\n  submodules: [packages/client-area]\n");
        $runner = new FakeGitRunner();

        $this->repository($runner)->fetchSubmodules('https://github.com/org/panel.git', 'pat');

        $this->assertSame(['--depth=1', '--', 'packages/client-area'], array_slice($this->submoduleCommand($runner), -3));
    }

    public function test_an_empty_list_fetches_none(): void
    {
        file_put_contents($this->projectDir() . '/panelalpha.yaml', "git:\n  submodules: []\n");
        $runner = new FakeGitRunner();

        $this->repository($runner)->fetchSubmodules('https://github.com/org/panel.git', 'pat');

        $this->assertSame([], $this->submoduleCommands($runner));
    }

    public function test_a_checkout_without_gitmodules_runs_nothing(): void
    {
        unlink($this->projectDir() . '/.gitmodules');
        $runner = new FakeGitRunner();

        $this->repository($runner)->fetchSubmodules('https://github.com/org/panel.git', 'pat');

        $this->assertSame([], $runner->commands);
    }

    public function test_a_submodule_that_cannot_be_fetched_is_left_out_with_a_warning_naming_it(): void
    {
        $failing = $this->failingCheckout(now: [self::SUBMODULE], checkedOut: []);

        $warnings = $this->warningsOf(fn () => $this->repository($failing)->fetchSubmodules('https://github.com/org/panel.git', 'pat'));

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $warnings[0]);
        $this->assertSame(
            DeployFailureExplainer::SUBMODULE_FAILURE . ": could not read Username for 'https://github.com': terminal prompts disabled. "
            . 'The deploy goes on without packages/client-area.',
            $warnings[0]
        );
    }

    /** A rebuild that refuses to drop a checkout is still explained, the SSH case included. */
    public function test_the_submodule_failure_keeps_its_rule_and_advice(): void
    {
        $match = DeployFailureExplainer::match(DeployFailureExplainer::SUBMODULE_FAILURE
            . ": fatal: could not read Username for 'https://github.com': terminal prompts disabled");

        $this->assertSame('submodule-fetch-failed', $match['rule'] ?? null);
        $this->assertStringContainsString('git: {submodules: [...]}', $match['message']);
        $this->assertStringContainsString('A submodule addressed over SSH (git@host:...)', $match['message']);
    }

    /** @return callable(list<string>): string */
    private function failingSubmodules(): callable
    {
        return static function (array $command): string {
            if (in_array('submodule', $command, true)) {
                throw new GitException(
                    "Cloning into '/app/packages/client-area'...\nfatal: could not read Username for 'https://github.com': terminal prompts disabled",
                    400
                );
            }

            return "true\n";
        };
    }

    /** A pull whose moved submodule pointer cannot be fetched keeps the checkout it had, and says so. */
    public function test_a_redeploy_keeps_the_submodule_checkout_it_has_and_warns(): void
    {
        $failing = $this->failingCheckout(now: [self::SUBMODULE], checkedOut: [self::SUBMODULE]);

        $warnings = $this->warningsOf(fn () => $this->repository($failing, ['git_commit' => self::DEPLOYED, 'deployment_status' => 'success'])
            ->fetchSubmodules('https://github.com/org/panel.git', 'pat'));

        $this->assertCount(1, $warnings);
        $this->assertStringEndsWith('The deploy goes on with the submodule checkout it has.', $warnings[0]);
    }

    /** When git cannot tell which submodules have files, the warning cannot name them but the deploy still goes on. */
    public function test_a_deploy_goes_on_when_git_cannot_read_the_submodule_checkouts(): void
    {
        $failing = $this->failingCheckout(now: [self::SUBMODULE], checkedOut: null);

        $warnings = $this->warningsOf(fn () => $this->repository($failing)->fetchSubmodules('https://github.com/org/panel.git', 'pat'));

        $this->assertCount(1, $warnings);
        $this->assertStringEndsWith('The deploy goes on without the submodules that did not come down.', $warnings[0]);
    }

    /**
     * A checkout whose submodule fetch fails: `.gitmodules` declares $now at HEAD, and $checkedOut
     * have files (null: git cannot list them).
     *
     * @param list<string> $now
     * @param ?list<string> $checkedOut
     * @return callable(list<string>): string
     */
    private function failingCheckout(array $now, ?array $checkedOut): callable
    {
        $failing = $this->failingSubmodules();

        return static function (array $command) use ($now, $checkedOut, $failing): string {
            $joined = implode(' ', $command);

            return match (true) {
                str_contains($joined, 'config --blob HEAD:.gitmodules') => self::gitmodulesPaths($now),
                str_contains($joined, 'ls-files --recurse-submodules') && $checkedOut === null
                    => throw new GitException("fatal: not a git repository: 'packages/client-area/.git'", 128),
                str_contains($joined, 'ls-files --recurse-submodules') => implode('', array_map(
                    static fn (string $path): string => "{$path}/index.php\0",
                    $checkedOut,
                )),
                default => $failing($command),
            };
        };
    }

    /** @param list<string> $paths */
    private static function gitmodulesPaths(array $paths): string
    {
        return implode('', array_map(static fn (string $path): string => "submodule.{$path}.path\n{$path}\0", $paths));
    }

    /** @return list<string> */
    private function warningsOf(callable $deploy): array
    {
        $logger = DeployLogger::start('alice');
        try {
            $deploy();

            return array_values(array_map(
                static fn (array $l): string => $l['msg'],
                array_filter($logger->read()['lines'], static fn (array $l): bool => $l['level'] === 'warn'),
            ));
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            DeployLogger::deleteUserLogs('alice');
        }
    }

    /** Without a deploy key ssh has nothing to offer, so an ssh submodule URL is read over https. */
    public function test_an_ssh_submodule_url_is_read_over_https_without_a_deploy_key(): void
    {
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n\turl = git@github.com:org/lib.git\n");
        $runner = new FakeGitRunner();

        $this->repository($runner)->fetchSubmodules('https://github.com/org/panel.git', null);

        $command = $this->submoduleCommand($runner);
        $at = array_search('url.https://github.com/.insteadOf=git@github.com:', $command, true);
        $this->assertNotFalse($at, implode(' ', $command));
        $this->assertSame('-c', $command[$at - 1]);
    }

    public function test_a_project_with_a_deploy_key_keeps_its_ssh_submodule_urls(): void
    {
        file_put_contents($this->projectDir() . '/.gitmodules', "[submodule \"lib\"]\n\tpath = lib\n\turl = git@github.com:org/lib.git\n");
        $runner = new FakeGitRunner();
        $runner->stdout = ['rev-parse --is-inside-work-tree' => "true\n"];

        (new TestableGitRepository($this->dind([DeployKey::DETAIL => ['private_key' => 'k']]), $runner))
            ->fetchSubmodules('https://github.com/org/panel.git', null);

        $this->assertStringNotContainsString('insteadOf', implode(' ', $this->submoduleCommand($runner)));
    }

    /** A pull, a branch change and a push all redeploy through here, after the checkout moved. */
    public function test_a_checkout_redeploy_fetches_submodules_before_it_prepares(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../../core/app/System/Project/Deployment/DindDeployMechanics.php');
        $body = substr($source, (int) strpos($source, 'public function reprepareApplicationFromCheckout('));
        $body = substr($body, 0, (int) strpos($body, "\n    }\n"));

        $fetch = strpos($body, '$this->fetchSubmodulesOfCheckout();');
        $prepare = strpos($body, '$this->prepareApplication();');
        $this->assertNotFalse($fetch);
        $this->assertNotFalse($prepare);
        $this->assertLessThan($prepare, $fetch);
    }

    /**
     * @param (callable(list<string>, ?string, int): string)|FakeGitRunner $execute
     * @param array<string, mixed> $details
     */
    private function repository(callable|FakeGitRunner $execute, array $details = []): TestableGitRepository
    {
        if ($execute instanceof FakeGitRunner) {
            $execute->stdout = ['rev-parse --is-inside-work-tree' => "true\n"];
        }

        return new TestableGitRepository($this->dind($details), $execute);
    }

    /** @return list<string> */
    private function submoduleCommand(FakeGitRunner $runner): array
    {
        $commands = $this->submoduleCommands($runner);
        $this->assertCount(1, $commands);

        return $commands[0];
    }

    /** @return list<list<string>> */
    private function submoduleCommands(FakeGitRunner $runner): array
    {
        return array_values(array_filter($runner->commands, static fn (array $c): bool => in_array('submodule', $c, true)));
    }

    private function projectDir(): string
    {
        return $this->root . '/home/alice/project';
    }

    /** @param array<string, mixed> $details */
    private function dind(array $details = []): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'git_repo' => 'https://github.com/org/panel.git', ...$details]);
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** Runs the app config reads without the broker, against the temporary home. */
    private function system(): System
    {
        return new class ($this->root) extends System {
            public function __construct(private string $root)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return parent::exec(self::withoutBroker($cmd), $this->withLocalHomes($env), $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return parent::runProcess(self::withoutBroker($cmd), $this->withLocalHomes($env), $timeout);
            }

            /**
             * @param array<string, string> $env
             * @return array<string, string>
             */
            private function withLocalHomes(array $env): array
            {
                return [...$env, 'PA_ROOT_LOCAL_ROOTS' => json_encode(['homes' => $this->root . '/home'], JSON_THROW_ON_ERROR)];
            }

            /** @return string|list<string> */
            private static function withoutBroker(string|array $cmd): string|array
            {
                return is_array($cmd) ? LocalRoot::argv($cmd) : $cmd;
            }
        };
    }
}
