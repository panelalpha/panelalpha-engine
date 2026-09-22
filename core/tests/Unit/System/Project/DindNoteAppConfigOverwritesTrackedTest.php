<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\GitRepository;
use Tests\TestCase;

/**
 * ADR-0001 #06 (D4) — `Dind::noteAppConfigOverwritesTracked()`'s guard
 * branches: no active deploy log, and an empty path list, must return without
 * ever reaching git.
 *
 * The "logs the exact 'App config overwrites tracked file <path>' line for a
 * path git actually tracks" case goes through a real, docker-wrapped shell
 * (the method always builds its own {@see GitRepository}),
 * so it is exercised end to end by the DinD verification suite
 * (AGENTS.md W11) rather than here; {@see DindGitRepositoryTest} covers the
 * underlying `trackedAmong()`/`hasRepository()` logic this method depends on.
 */
class DindNoteAppConfigOverwritesTrackedTest extends TestCase
{
    /** @var list<string> */
    private array $usernames = [];

    private string $tmpRoot;

    private string $homeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/pa-note-overwrite-'.bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot.'/home';
    }

    protected function tearDown(): void
    {
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_nothing_happens_without_a_running_deploy_log(): void
    {
        $dind = $this->dind($this->username());

        $dind->noteAppConfigOverwritesTracked(['some/tracked/path.txt']);

        // No exception, and — since nothing was running — nothing to have
        // logged to either.
        $this->addToAssertionCount(1);
    }

    public function test_an_empty_path_list_never_reaches_git_even_with_a_running_deploy(): void
    {
        $username = $this->username();
        $dind = $this->dind($username);
        $logger = DeployLogger::start($username);

        $dind->noteAppConfigOverwritesTracked([]);

        $this->assertSame([], $logger->read()['lines']);
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    private function username(): string
    {
        $username = 'note-overwrite-'.bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    private function dind(string $username): Dind
    {
        $model = new ModelsUser;
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ]);

        $runtime = (new ProjectAggregate($this->system(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function system(): System
    {
        $tmpRoot = $this->tmpRoot;
        $homeRoot = $this->homeRoot;

        return new class($tmpRoot, $homeRoot) extends System
        {
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
            ) {}

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
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
