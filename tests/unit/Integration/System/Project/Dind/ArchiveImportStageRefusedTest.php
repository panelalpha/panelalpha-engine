<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\Source\Files;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

/**
 * An import whose sweep of earlier leftovers cannot list or remove them still goes on, and one whose stage
 * directory cannot be made stops before anything is copied, cleaning up after itself.
 */
class ArchiveImportStageRefusedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-archive-stage-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice/project', 0777, true);
        file_put_contents($this->root . '/stage', 'a file where the stage root should be');
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['homes' => $this->root . '/home']));
        file_put_contents($this->root . '/home/alice/fw.tar.gz', gzencode('not inspected here'));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_stage_directory_that_cannot_be_made_stops_the_import_after_a_failed_sweep(): void
    {
        $system = $this->system();
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'deploy_strategy' => 'static']);
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        try {
            (new Files($runtime, $this->root . '/stage'))->importProjectArchive('/fw.tar.gz');
            $this->fail('An import went on without its stage directory.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Could not create the archive stage directory ' . $this->root . '/stage/', $e->getMessage());
        }

        $actions = array_column($system->asked, 0);
        $this->assertSame(['files.staleDirectoriesStartingWith', 'files.staleDirectoriesStartingWith', 'files.removeTree', 'files.removeTree'], array_values(array_filter(
            $actions,
            static fn (string $action): bool => str_starts_with($action, 'files.'),
        )));
        $this->assertSame([$this->root . '/home/alice/.panelalpha/archive-extract-old'], array_slice($system->asked[array_search('files.removeTree', $actions, true)], 1));
        $this->assertNotContains('account.extractTar', $actions);
    }

    /** The first sweep cannot list, the second lists a leftover it cannot remove; the import's own cleanup is answered. */
    private function system(): System
    {
        return new class ($this->root) extends System {
            /** @var list<list<string>> */
            public array $asked = [];

            private int $sweeps = 0;

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

            /** No process starts here: a streamed command fails as it would on a host that cannot run it. */
            public function runProcessWithCallbacks(string|array $cmd, array $env = [], int $timeout = 600, ?callable $onStart = null, ?callable $onOutput = null, ?StepWatchdog $watchdog = null): Process
            {
                return FakeProcess::failed('no process is started in this test');
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $action = is_array($cmd) ? (string) LocalRoot::action($cmd) : '';
                $values = $action !== '' ? LocalRoot::values($cmd) : [];
                $this->asked[] = [$action, ...$values];
                if ($action === 'files.staleDirectoriesStartingWith' && $this->sweeps++ === 0) {
                    throw new \RuntimeException('find: permission denied');
                }
                if ($action === 'files.staleDirectoriesStartingWith') {
                    return $this->root . "/home/alice/.panelalpha/archive-extract-old\n";
                }
                if ($action === 'files.removeTree' && count($values) === 1) {
                    throw new \RuntimeException('rm: device busy');
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->asked[] = [is_array($cmd) ? (string) LocalRoot::action($cmd) : ''];
                $process = new Process(LocalRoot::argv((array) $cmd));
                $process->run();

                return $process;
            }
        };
    }
}
