<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A redeploy after a pull, with real git: a submodule the deployed commit already declared
 * and that cannot be fetched now keeps whatever checkout it had with a warning; one the new
 * commit adds fails the redeploy.
 */
class DindSubmoduleRedeployGitTest extends TestCase
{
    private string $root;

    private ?string $home = null;

    private string $author;

    private string $deployed;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir() . '/pa-dind-subgit-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/gitconfig', 0700, true);
        file_put_contents($this->root . '/gitconfig/.gitconfig', "[protocol \"file\"]\n\tallow = always\n[user]\n\tname = t\n\temail = t@example.com\n");
        $this->home = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->root . '/gitconfig');
        $_SERVER['HOME'] = $_ENV['HOME'] = $this->root . '/gitconfig';

        $this->git($this->root, ['init', '--bare', '-b', 'main', 'lib.git']);
        $this->git($this->root, ['clone', 'lib.git', 'lib']);
        file_put_contents($this->root . '/lib/a.txt', "lib\n");
        $this->git($this->root . '/lib', ['add', '-A']);
        $this->git($this->root . '/lib', ['commit', '-m', 'lib']);
        $this->git($this->root . '/lib', ['push', 'origin', 'HEAD:main']);

        $this->git($this->root, ['init', '--bare', '-b', 'main', 'origin.git']);
        $this->git($this->root, ['clone', 'origin.git', 'author']);
        $this->author = $this->root . '/author';
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->git($this->author, ['submodule', 'add', 'file://' . $this->root . '/lib.git', 'lib']);
        $this->git($this->author, ['commit', '-m', 'add lib']);
        $this->git($this->author, ['push', 'origin', 'main']);
        $this->deployed = trim($this->git($this->author, ['rev-parse', 'HEAD']));

        mkdir($this->root . '/home/alice', 0755, true);
        $this->git($this->root, ['clone', 'file://' . $this->root . '/origin.git', $this->projectDir()]);
        $this->git($this->projectDir(), ['submodule', 'update', '--init']);
    }

    protected function tearDown(): void
    {
        if ($this->home !== null) {
            putenv('HOME=' . $this->home);
            $_SERVER['HOME'] = $_ENV['HOME'] = $this->home;
        }
        (new Process(['rm', '-rf', $this->root]))->run();
        DeployLogger::deleteUserLogs('alice');
        parent::tearDown();
    }

    public function test_a_declared_submodule_moved_to_a_commit_that_cannot_be_fetched_keeps_its_checkout(): void
    {
        $this->git($this->author, ['update-index', '--cacheinfo', '160000,' . str_repeat('2', 40) . ',lib']);
        $this->pullCommit('move lib');

        $warnings = $this->redeploy();

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $warnings[0]);
        $this->assertStringContainsString('the submodule checkout the previous deploy had', $warnings[0]);
        $this->assertSame("lib\n", file_get_contents($this->projectDir() . '/lib/a.txt'));
    }

    public function test_a_submodule_the_new_commit_adds_that_cannot_be_fetched_fails_the_redeploy(): void
    {
        $this->addUnreachableSubmodule('vendor/gone');
        $this->pullCommit('add gone');

        try {
            $warnings = $this->redeploy();
            $this->fail('the redeploy went on: ' . implode(' | ', $warnings));
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $e->getMessage());
        }
    }

    /** The deployed commit declared it and the deploy went on without it, as 2.1.1 did; it still cannot be fetched. */
    public function test_a_declared_submodule_that_was_never_fetched_goes_on_without_it(): void
    {
        $this->addUnreachableSubmodule('vendor/gone');
        $this->pullCommit('add gone');
        (new Process(['git', 'submodule', 'update', '--init'], $this->projectDir()))->run();
        $this->deployed = trim($this->git($this->projectDir(), ['rev-parse', 'HEAD']));
        file_put_contents($this->author . '/index.php', "<?php\n");
        $this->git($this->author, ['add', 'index.php']);
        $this->pullCommit('change the app');

        $warnings = $this->redeploy();

        $this->assertCount(1, $warnings);
        $this->assertStringStartsWith(DeployFailureExplainer::SUBMODULE_FAILURE . ': ', $warnings[0]);
        $this->assertStringContainsString('The deploy goes on without vendor/gone: there is no earlier checkout of it to keep.', $warnings[0]);
        $this->assertSame("lib\n", file_get_contents($this->projectDir() . '/lib/a.txt'));
    }

    /** Staged in the author's checkout at lib's commit, with a URL nothing answers. */
    private function addUnreachableSubmodule(string $path): void
    {
        $this->git($this->author, ['submodule', 'add', 'file://' . $this->root . '/lib.git', $path]);
        $this->git($this->author, ['config', '--file', '.gitmodules', "submodule.{$path}.url", 'file://' . $this->root . '/gone.git']);
        $this->git($this->author, ['add', '.gitmodules']);
    }

    private function pullCommit(string $message): void
    {
        $this->git($this->author, ['commit', '-m', $message]);
        $this->git($this->author, ['push', 'origin', 'main']);
        $this->git($this->projectDir(), ['fetch', '--no-recurse-submodules', 'origin']);
        $this->git($this->projectDir(), ['reset', '--hard', 'origin/main']);
    }

    /** @return list<string> the warnings the redeploy's submodule fetch logged */
    private function redeploy(): array
    {
        $logger = DeployLogger::start('alice');
        try {
            (new TestableGitRepository($this->dind(), $this->realGit()))
                ->fetchSubmodules('file://' . $this->root . '/origin.git', null, keepPrevious: true);

            return array_values(array_map(
                static fn (array $l): string => $l['msg'],
                array_filter($logger->read()['lines'], static fn (array $l): bool => $l['level'] === 'warn'),
            ));
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    private function realGit(): callable
    {
        return static function (array $argv, ?string $token, int $timeout = 600): string {
            $p = new Process($argv);
            $p->setTimeout($timeout);
            $p->run();
            if (!$p->isSuccessful()) {
                throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
            }

            return $p->getOutput();
        };
    }

    private function projectDir(): string
    {
        return $this->root . '/home/alice/project';
    }

    private function dind(): Dind
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => 'file://' . $this->root . '/origin.git',
            'deployment_status' => 'success',
            'git_commit' => $this->deployed,
        ]);
        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    /** Runs the app config reads without sudo, against the temporary home. */
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
                return parent::exec(self::withoutSudo($cmd), $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return parent::runProcess(self::withoutSudo($cmd), $env, $timeout);
            }

            /** @return string|list<string> */
            private static function withoutSudo(string|array $cmd): string|array
            {
                return is_array($cmd) && ($cmd[0] ?? null) === 'sudo' ? array_values(array_slice($cmd, 1)) : $cmd;
            }
        };
    }

    /** @param list<string> $args */
    private function git(string $cwd, array $args): string
    {
        $p = new Process(['git', ...$args], $cwd);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ': ' . ($p->getErrorOutput() ?: $p->getOutput()));
        }

        return $p->getOutput();
    }
}
