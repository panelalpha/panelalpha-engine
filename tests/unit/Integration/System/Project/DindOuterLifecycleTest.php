<?php

namespace Tests\Integration\System\Project;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Dind\AccountImage;
use App\Models\User as ModelsUser;
use App\System;
use App\System\AccountContainers;
use App\System\Filesystem;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeProcess;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class DindOuterLifecycleTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $templateRoot;

    /** @var list<string|list<string>> */
    private array $executed = [];

    /** True: the host's `alice` was not started from the account directory. */
    private bool $foreignOnly = false;

    private bool $accountImageOnHost = true;

    private static ?self $current = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$current = $this;
        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-outer-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->templateRoot = $this->tmpRoot . '/templates/user/dind/project';
        putenv('PA_ROOT_LOCAL_ROOTS=' . json_encode(['engine' => $this->tmpRoot, 'homes' => $this->homeRoot]));
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice', 0777, true);
        mkdir($this->templateRoot, 0777, true);
        file_put_contents($this->templateRoot . '/docker-compose.yml', "services:\n  dind:\n    image: test\n");
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        DeployLogger::deleteUserLogs('alice');
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_materialize_writes_compose_from_template(): void
    {
        $model = $this->dindModel();
        $system = $this->recordingSystem();
        $project = $this->dindWithStubbedTemplate($system, $model);

        mkdir($this->homeRoot . '/alice/.panelalpha', 0777, true);
        is_dir($system->projectDirPath('alice')) || mkdir($system->projectDirPath('alice'), 0777, true);

        $project->materialize();

        $this->assertFileExists($project->composeFilePath());
        $this->assertSame('dind', $project->kind());
    }

    public function test_start_failure_can_be_cleaned_with_remove(): void
    {
        $model = $this->dindModel();
        $executed = [];
        $system = new class ($this->tmpRoot, $this->homeRoot, $this->templateRoot, $executed) extends System {
            /** @param list<string|list<string>> $executed */
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private string $templateRoot,
                private array &$executed,
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

            public function projectFilesTemplateDirPath(?string $template = null): string
            {
                return $this->templateRoot;
            }

            public function isUidExists(string $username): bool
            {
                return false;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);

                return "1000\n";
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->executed[] = $cmd;
                if (is_array($cmd) && (DindOuterLifecycleTest::asRun($cmd)[0] ?? '') === 'docker') {
                    throw new \Exception('compose up failed');
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $this->executed[] = $cmd;

                return DindOuterLifecycleTest::hostAnswer($cmd);
            }

            public function filesystem(): Filesystem
            {
                return new Filesystem($this);
            }

            public function php(): System\Services\Php
            {
                return new class ($this) extends System\Services\Php {
                    public function listAvailablePhpVersions(): array
                    {
                        return ['8.3'];
                    }
                };
            }
        };

        $project = $this->dindWithStubbedTemplate($system, $model);
        is_dir($system->projectDirPath('alice')) || mkdir($system->projectDirPath('alice'), 0777, true);
        $project->materialize();

        // remove() logs the data-root wipe the fake refuses; keep that off the test log.
        Log::spy();
        try {
            $project->start();
            $this->fail('Expected start to throw');
        } catch (\Exception) {
            $project->remove();
        }

        $downSeen = false;
        $rmSeen = false;
        foreach ($executed as $cmd) {
            $flat = is_array($cmd) ? implode(' ', self::asRun($cmd)) : $cmd;
            if (str_contains($flat, ' down') && str_contains($flat, 'docker compose')) {
                $downSeen = true;
            }
            if (str_contains($flat, 'docker.forceRemoveContainer') && str_contains($flat, 'alice')) {
                $rmSeen = true;
            }
        }
        $this->assertTrue($downSeen);
        $this->assertTrue($rmSeen);
    }

    public function test_start_and_stop_target_outer_compose_only(): void
    {
        $system = $this->recordingSystem();
        $project = $this->dind($system, $this->dindModel());
        file_put_contents($project->composeFilePath(), "services: {}\n");

        $project->start();
        $project->stop();

        $this->assertContains(
            ['docker', 'compose', '-f', $project->composeFilePath(), 'up', '-d', '--remove-orphans'],
            $this->ran()
        );
        $this->assertContains(
            ['docker', 'compose', '-f', $project->composeFilePath(), 'down'],
            $this->ran()
        );
    }

    public function test_tear_down_runs_outer_compose_down_and_force_removes_container(): void
    {
        $system = $this->recordingSystem();
        $project = $this->dind($system, $this->dindModel());
        file_put_contents($project->composeFilePath(), "services: {}\n");

        $project->tearDown();

        $this->assertContains(
            ['docker', 'compose', '-f', $project->composeFilePath(), 'down', '-v', '--remove-orphans'],
            $this->ran()
        );
        $this->assertContains([LocalRoot::CLIENT, 'docker.forceRemoveContainer', '--', 'alice'], $this->ran());
    }

    public function test_a_deploy_is_told_when_the_account_image_has_to_be_fetched(): void
    {
        $this->accountImageOnHost = false;
        $deploy = DeployLogger::start('alice');
        $project = $this->dind($this->recordingSystem(), $this->dindModel());

        $project->buildIfMissing();

        $this->assertContains(
            ['info', 'The account image '.AccountImage::REF.' is not on this host: pulling it, or building it if the pull fails'],
            array_map(static fn (array $line): array => [$line['level'], $line['msg']], $deploy->entries()),
        );
        $this->assertContains(AccountImage::ensureCommand($project->project()->projectDirPath()), $this->executed);
    }

    public function test_an_account_image_already_on_the_host_is_not_announced(): void
    {
        $deploy = DeployLogger::start('alice');
        $project = $this->dind($this->recordingSystem(), $this->dindModel());

        $project->buildIfMissing();

        $this->assertStringNotContainsString('account image', implode("\n", array_column($deploy->entries(), 'msg')));
    }

    public function test_without_a_deploy_the_image_is_not_probed_twice(): void
    {
        $project = $this->dind($this->recordingSystem(), $this->dindModel());

        $project->buildIfMissing();

        $this->assertNotContains(AccountImage::presentArgv(), $this->executed);
    }

    public function test_materialize_does_not_invoke_deploy_strategy(): void
    {
        $model = $this->dindModel();
        $system = $this->recordingSystem();
        $project = $this->dindWithStubbedTemplate($system, $model);
        is_dir($system->projectDirPath('alice')) || mkdir($system->projectDirPath('alice'), 0777, true);

        $project->materialize();

        foreach ($this->executed as $cmd) {
            $flat = is_array($cmd) ? implode(' ', $cmd) : $cmd;
            $this->assertStringNotContainsStringIgnoringCase('deploy', $flat);
            $this->assertStringNotContainsString('clone', $flat);
        }
    }

    /**
     * A host container that only shares the account's name is not the
     * account's: delete used to `docker rm -f` it by name.
     */
    public function test_tear_down_leaves_a_foreign_container_with_the_account_name(): void
    {
        $this->foreignOnly = true;
        $system = $this->recordingSystem();
        $project = $this->dind($system, $this->dindModel());
        file_put_contents($project->composeFilePath(), "services: {}\n");

        $project->tearDown();

        $this->assertContains([
            LocalRoot::CLIENT, 'docker.containerNamedWithLabel', '--', 'alice',
            AccountContainers::WORKING_DIR_LABEL, $system->projectDirPath('alice'),
        ], $this->ran());
        $this->assertNotContains([LocalRoot::CLIENT, 'docker.forceRemoveContainer', '--', 'alice'], $this->ran());
    }

    /**
     * The host: an `alice` container exists; it carries the account's Compose
     * label unless the test made it foreign.
     *
     * @param string|list<string> $cmd
     */
    public static function hostAnswer(string|array $cmd): FakeProcess
    {
        if ($cmd === AccountImage::presentArgv()) {
            return (self::$current?->accountImageOnHost ?? true) ? FakeProcess::ok() : FakeProcess::failed();
        }
        if (LocalRoot::action((array) $cmd) === 'docker.containerNamedWithLabel') {
            $foreign = self::$current?->foreignOnly ?? false;

            return FakeProcess::ok($foreign ? '' : "alice\n");
        }

        return FakeProcess::forCommand($cmd);
    }

    /**
     * @param string|list<string> $cmd
     * @return string|list<string>
     */
    public static function asRun(string|array $cmd): string|array
    {
        if (!is_array($cmd) || !LocalRoot::isRequest($cmd) || LocalRoot::isNative($cmd)) {
            return $cmd;
        }

        return LocalRoot::plain($cmd);
    }

    /** @return list<string|list<string>> */
    private function ran(): array
    {
        return array_map(self::asRun(...), $this->executed);
    }

    private function dind(System $system, ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function dindWithStubbedTemplate(System $system, ModelsUser $model): Dind
    {
        $templateRoot = $this->templateRoot;
        $aggregate = new ProjectAggregate($system, $model);

        return new class ($aggregate, $templateRoot) extends Dind {
            public function __construct(
                ProjectAggregate $project,
                private string $stubTemplateRoot,
            ) {
                parent::__construct($project);
            }

            public function createFromTemplate(): void
            {
                $target = $this->composeFilePath();
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0777, true);
                }
                copy($this->stubTemplateRoot . '/docker-compose.yml', $target);
            }
        };
    }

    private function dindModel(): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind']);

        return $model;
    }

    private function recordingSystem(): System
    {
        $executed = &$this->executed;

        return new class ($this->tmpRoot, $this->homeRoot, $this->templateRoot, $executed) extends System {
            /** @param list<string|list<string>> $executed */
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private string $templateRoot,
                private array &$executed,
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

            public function projectFilesTemplateDirPath(?string $template = null): string
            {
                return $this->templateRoot;
            }

            public function isUidExists(string $username): bool
            {
                return false;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                if (is_array($cmd) && ($cmd[0] ?? '') === 'id') {
                    return "1000\n";
                }

                return '';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->executed[] = $cmd;

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): \Symfony\Component\Process\Process
            {
                $this->executed[] = $cmd;

                return DindOuterLifecycleTest::hostAnswer($cmd);
            }

            public function filesystem(): Filesystem
            {
                return new Filesystem($this);
            }

            public function php(): System\Services\Php
            {
                return new class ($this) extends System\Services\Php {
                    public function listAvailablePhpVersions(): array
                    {
                        return ['8.3'];
                    }
                };
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
