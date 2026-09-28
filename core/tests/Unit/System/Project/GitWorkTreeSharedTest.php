<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\GitRepository;
use App\System\Project\Git as ProjectGit;
use App\System\Project\Git\WorkTree;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Git and GitRepository were forks of one another. These hold them to one
 * implementation: everything but the four hooks has to come from WorkTree.
 */
class GitWorkTreeSharedTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;

    /** The only things a subclass is allowed to supply. */
    private const HOOKS = ['user', 'system', 'homeDirPath', 'runCommand'];

    /** Hooks with a default in WorkTree that only one subclass replaces. */
    private const OPTIONAL_HOOKS = ['fetchAndSyncFreshInit'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-worktree-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        mkdir($this->homeRoot . '/alice/project', 0777, true);
        mkdir($this->homeRoot . '/alice/public_html', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_both_work_trees_share_one_implementation(): void
    {
        $this->assertTrue(is_subclass_of(ProjectGit::class, WorkTree::class));
        $this->assertTrue(is_subclass_of(GitRepository::class, WorkTree::class));
    }

    public function test_neither_subclass_reimplements_shared_behaviour(): void
    {
        $shared = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(WorkTree::class))->getMethods()
        );
        $shared = array_diff($shared, self::HOOKS, self::OPTIONAL_HOOKS);

        foreach ([ProjectGit::class, GitRepository::class] as $class) {
            $own = array_map(
                fn (\ReflectionMethod $m): string => $m->getName(),
                array_filter(
                    (new ReflectionClass($class))->getMethods(),
                    fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class
                )
            );

            $overridden = array_intersect($own, $shared);
            $this->assertSame(
                [],
                array_values($overridden),
                $class . ' re-implements shared work-tree behaviour: ' . implode(', ', $overridden)
            );
        }
    }

    public function test_every_hook_is_supplied_by_both_subclasses(): void
    {
        foreach ([ProjectGit::class, GitRepository::class] as $class) {
            foreach (self::HOOKS as $hook) {
                $method = (new ReflectionClass($class))->getMethod($hook);
                $this->assertSame(
                    $class,
                    $method->getDeclaringClass()->getName(),
                    "{$class} must supply {$hook}()"
                );
            }
        }
    }

    public function test_the_strategy_constants_are_the_same_for_both(): void
    {
        foreach (['STRATEGY_FF', 'STRATEGY_FORCE', 'STRATEGY_PUSH_FIRST'] as $name) {
            $this->assertSame(
                constant(ProjectGit::class . '::' . $name),
                constant(GitRepository::class . '::' . $name)
            );
        }
    }

    /**
     * A fresh `git init` is synced on connect by the panel path only. The
     * deploy-ingest path has never done it: it reports `connected: true` over
     * an empty repository until a separate pull.
     */
    public function test_only_the_panel_path_syncs_a_fresh_init(): void
    {
        foreach (['panel api', 'deploy ingest'] as $which) {
            $runner = new FakeGitRunner();
            $runner->stdout = [
                'status --porcelain' => '',
                'config --get remote.origin.url' => "https://github.com/org/repo.git\n",
                'branch --show-current' => "main\n",
                'rev-parse --abbrev-ref @{upstream}' => '',
            ];

            $tree = $which === 'panel api'
                ? new TestableProjectGit($this->aggregate(), 'public_html', $runner)
                : new TestableGitRepository($this->dindProject(), $runner);

            $tree->connect('https://github.com/org/repo.git', 'main', null);

            $joined = array_map(fn (array $c): string => implode(' ', $c), $runner->commands);
            $this->assertTrue(
                $this->contains($joined, 'init'),
                "{$which}: expected a git init"
            );
            $syncs = $which === 'panel api';
            $this->assertSame(
                $syncs,
                $this->contains($joined, 'fetch origin'),
                "{$which}: fetch origin on a fresh init"
            );
            $this->assertSame(
                $syncs,
                $this->contains($joined, 'reset --hard origin/main'),
                "{$which}: sync to the remote branch on a fresh init"
            );
        }
    }

    /** @param list<string> $joined */
    private function contains(array $joined, string $needle): bool
    {
        foreach ($joined as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function model(string $template): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->domain = 'alice.example.test';
        $model->setDetails(['template' => $template]);

        return $model;
    }

    private function aggregate(): ProjectAggregate
    {
        return new ProjectAggregate($this->system(), $this->model('wordpress'));
    }

    private function dindProject(): Dind
    {
        $runtime = (new ProjectAggregate($this->system(), $this->model('dind')))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        return new class ($this->tmpRoot, $this->homeRoot) extends System {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return $this->homesRoot;
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
