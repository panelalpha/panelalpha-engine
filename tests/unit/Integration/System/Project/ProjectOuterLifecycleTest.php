<?php

namespace Tests\Integration\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use App\System\Project;
use App\System\Services\Webserver;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\Support\HostCommand;
use Tests\Support\LocalRoot;
use Tests\TestCase;

class ProjectOuterLifecycleTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $dindTemplateRoot;
    private string $phpTemplateRoot;

    /** @var list<string|list<string>> */
    private array $executed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-outer-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '-home';
        $this->dindTemplateRoot = $this->tmpRoot . '/templates/user/dind/project';
        $this->phpTemplateRoot = $this->tmpRoot . '/templates/user/default/project';
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
        mkdir($this->homeRoot . '/alice', 0777, true);
        mkdir($this->dindTemplateRoot, 0777, true);
        file_put_contents($this->dindTemplateRoot . '/docker-compose.yml', "services:\n  dind:\n    image: test\n");
        $this->seedPhpTemplate($this->phpTemplateRoot);
        $this->setCurrentWebserver('nginx');
        putenv('PA_ROOT_LOCAL_ROOTS='.json_encode(['homes' => $this->homeRoot, 'engine' => $this->tmpRoot]));
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        $this->setCurrentWebserver(null);
        $this->removeTree($this->tmpRoot);
        $this->removeTree($this->homeRoot);
        parent::tearDown();
    }

    public function test_dind_provision_through_aggregate_writes_compose_and_brings_stack_up(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $model = $this->dindModel('alice', $system);
        $composePath = $system->projectDirPath('alice') . '/docker-compose.yml';
        $project = new Project($system, $model);

        $project->provision();

        $this->assertFileExists($composePath);
        $this->assertContains('compose-up', $system->journal);
        $this->assertContains('useradd', $system->journal);
    }

    public function test_php_hosting_provision_through_aggregate_writes_compose_and_brings_stack_up(): void
    {
        $system = $this->recordingSystem(template: 'default');
        $model = $this->phpHostingModel('bob');
        mkdir($system->projectDirPath('bob'), 0777, true);
        $project = new Project($system, $model);

        $project->provision();

        $this->assertTrue($project->exists());
        $this->assertContains('compose-up', $system->journal);
        $this->assertContains('useradd', $system->journal);
    }

    public function test_second_provision_with_compose_present_fails_for_dind(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $project = $system->project($this->dindModel('alice', $system));
        file_put_contents($project->composeFilePath(), "services: {}\n");

        $this->expectException(LogicException::class);
        $project->provision();
    }

    public function test_failed_dind_provision_invokes_runtime_remove_and_linux_teardown(): void
    {
        $system = $this->recordingSystem(template: 'dind', failComposeUp: true);
        $model = $this->dindModel('alice', $system);
        $composePath = $system->projectDirPath('alice') . '/docker-compose.yml';
        $project = new Project($system, $model);

        try {
            $project->provision();
            $this->fail('Expected provision to throw');
        } catch (\Exception $e) {
            $this->assertSame('compose up failed', $e->getMessage());
        }

        $this->assertContains(Privileged::compose($composePath)->downWithVolumes(), $this->executed);
        $this->assertContains(Privileged::docker()->forceRemoveContainer('alice'), $this->executed);
        $this->assertFalse(is_dir($this->homeRoot . '/alice'));
    }

    public function test_delete_is_idempotent_when_compose_is_absent(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $project = $system->project($this->dindModel('alice', $system));
        $outerCompose = $project->composeFilePath();

        $project->delete();

        $this->assertContains(Privileged::docker()->forceRemoveContainer('alice'), $this->executed);
        $outerDown = array_filter($this->executed, static function ($cmd) use ($outerCompose): bool {
            if (!is_array($cmd) || !LocalRoot::isRequest($cmd)) {
                return false;
            }
            $argv = LocalRoot::plain($cmd);

            // Outer stack teardown only — not `compose -f <outer> exec … down`.
            return ($argv[0] ?? null) === 'docker'
                && ($argv[1] ?? null) === 'compose'
                && ($argv[2] ?? null) === '-f'
                && ($argv[3] ?? null) === $outerCompose
                && ($argv[4] ?? null) === 'down';
        });
        $this->assertSame([], array_values($outerDown));
        $this->assertFalse(is_dir($this->homeRoot . '/alice'));
    }

    public function test_dind_delete_tears_down_compose_force_removes_container_and_linux_home(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $project = $system->project($this->dindModel('alice', $system));
        $composePath = $project->composeFilePath();
        file_put_contents($composePath, "services: {}\n");

        $project->delete();

        $this->assertContains(Privileged::compose($composePath)->downWithVolumes(), $this->executed);
        $this->assertContains(Privileged::docker()->forceRemoveContainer('alice'), $this->executed);
        $this->assertFalse(is_dir($this->homeRoot . '/alice'));
    }

    public function test_aggregate_exposes_outer_lifecycle_methods(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $project = $system->project($this->dindModel('alice', $system));
        file_put_contents($project->composeFilePath(), "services:\n  dind:\n    image: test\n");

        $project->up();
        $project->down();
        $project->build();
        $project->buildIfMissing();
        $this->assertFalse($project->isRunning());
        $project->waitForAllRunning();

        $this->assertNotInstanceOf(\App\System\Project\Dind::class, $project);
    }

    public function test_recreate_outer_compose_rewrites_a_missing_compose_file(): void
    {
        $system = $this->recordingSystem(template: 'dind');
        $project = $system->project($this->dindModel('alice', $system));
        $this->assertFileDoesNotExist($project->composeFilePath());

        $this->assertFalse($project->isRunning());
        $project->recreateOuterCompose();

        $this->assertFileExists($project->composeFilePath());
        $this->assertContains('compose-up', $system->journal);
    }

    public function test_rebuild_of_a_running_php_project_rerenders_compose_and_brings_it_up_without_down(): void
    {
        $system = $this->recordingSystem(template: 'default', running: true);
        $model = $this->phpHostingModel('bob');
        $composePath = $system->projectDirPath('bob') . '/docker-compose.yml';
        mkdir($system->projectDirPath('bob'), 0777, true);
        file_put_contents($composePath, "services:\n  php:\n    mem_limit: 512M # stale\n");
        $project = new Project($system, $model);

        $project->rebuildFromSource();

        $this->assertStringNotContainsString('# stale', (string) file_get_contents($composePath));
        $this->assertStringContainsString('image: test', (string) file_get_contents($composePath));
        $this->assertContains(Privileged::compose($composePath)->up(), $this->executed);
        $this->assertNotContains(Privileged::compose($composePath)->down(), $this->executed);
        $this->assertNotContains(Privileged::compose($composePath)->up(true), $this->executed);
    }

    public function test_rebuild_of_a_stopped_php_project_still_goes_down_then_up(): void
    {
        $system = $this->recordingSystem(template: 'default', running: false);
        $model = $this->phpHostingModel('bob');
        $composePath = $system->projectDirPath('bob') . '/docker-compose.yml';
        mkdir($system->projectDirPath('bob'), 0777, true);
        file_put_contents($composePath, "services:\n  php:\n    mem_limit: 512M # stale\n");
        $project = new Project($system, $model);

        $project->rebuildFromSource();

        $down = array_search(Privileged::compose($composePath)->down(), $this->executed, true);
        $up = array_search(Privileged::compose($composePath)->up(), $this->executed, true);
        $this->assertIsInt($down);
        $this->assertIsInt($up);
        $this->assertLessThan($up, $down);
        $this->assertStringNotContainsString('# stale', (string) file_get_contents($composePath));
    }

    public function test_recreate_outer_compose_leaves_a_running_dind_account_alone(): void
    {
        $system = $this->recordingSystem(template: 'dind', running: true);
        $project = $system->project($this->dindModel('alice', $system));
        $composePath = $project->composeFilePath();
        $known = "services:\n  dind:\n    image: known\n";
        file_put_contents($composePath, $known);

        $project->recreateOuterCompose();

        $this->assertSame($known, file_get_contents($composePath));
        $this->assertNotContains(Privileged::compose($composePath)->up(), $this->executed);
        $this->assertNotContains(Privileged::compose($composePath)->down(), $this->executed);
        $this->assertNotContains('compose-up', $system->journal);
    }

    private function dindModel(string $username, System $system): ModelsUser
    {
        $model = new class ($system) extends ModelsUser {
            public function __construct(private System $boundSystem)
            {
            }

            public function getDomains(): array
            {
                return [];
            }

            public function save(array $options = []): bool
            {
                return true;
            }

            public function project(?System $system = null): Project
            {
                return new Project($this->boundSystem, $this);
            }
        };
        $model->username = $username;
        $model->setDetails([
            'template' => 'dind',
            'UID' => 1001,
            'GID' => 1001,
        ]);

        return $model;
    }

    private function phpHostingModel(string $username): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function getDomains(): array
            {
                return [];
            }

            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = $username;
        $model->setDetails([
            'template' => 'default',
            'UID' => 1001,
            'GID' => 1001,
            'cpu_limit' => 1.0,
            'memory_limit' => 512,
        ]);

        return $model;
    }

    private function recordingSystem(string $template, bool $failComposeUp = false, bool $running = false): System
    {
        $executed = &$this->executed;
        $phpTemplateRoot = $this->phpTemplateRoot;
        $dindTemplateRoot = $this->dindTemplateRoot;

        return new class ($this->tmpRoot, $this->homeRoot, $dindTemplateRoot, $phpTemplateRoot, $template, $executed, $failComposeUp, $running) extends System {
            /** @var list<string> */
            public array $journal = [];

            /** @param list<string|list<string>> $executed */
            public function __construct(
                private string $engineRoot,
                private string $homesRoot,
                private string $dindTemplateRoot,
                private string $phpTemplateRoot,
                private string $defaultTemplate,
                private array &$executed,
                private bool $failComposeUp,
                private bool $running,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                // AccountTeardown's sibling-compose scan only accepts POSIX roots.
                return '/tmp/' . basename($this->engineRoot) . '/home';
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->homesRoot . '/' . $username;
            }

            public function projectFilesTemplateDirPath(?string $template = null): string
            {
                $template ??= $this->defaultTemplate;

                return $template === 'dind' ? $this->dindTemplateRoot : $this->phpTemplateRoot;
            }

            public function isUidExists(string $username): bool
            {
                return false;
            }

            public function execOnHost(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = HostCommand::argv($cmd);
                $line = is_array($cmd) ? implode(' ', $cmd) : $cmd;
                if (str_contains($line, 'useradd')) {
                    $this->journal[] = 'useradd';
                }
                if (str_contains($line, 'id -u') || ($cmd[0] ?? '') === 'id') {
                    return "1001\n";
                }

                return '';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->executed[] = $cmd;
                $root = is_array($cmd) ? $this->asRoot($cmd) : [];
                $line = is_array($cmd) ? implode(' ', $this->plain($cmd)) : $cmd;

                if (($root[0] ?? '') === 'cat') {
                    $file = $root[2] ?? '';

                    return is_file($file) ? (string) file_get_contents($file) : '';
                }

                if (($root[0] ?? '') === 'touch') {
                    $file = $root[2] ?? '';
                    if ($file !== '') {
                        if (!is_dir(dirname($file))) {
                            mkdir(dirname($file), 0777, true);
                        }
                        touch($file);
                    }

                    return '';
                }

                if (str_contains($line, 'docker compose') && preg_match('/\bup\b/', $line)) {
                    if ($this->failComposeUp) {
                        throw new \Exception('compose up failed');
                    }
                    $this->journal[] = 'compose-up';
                }
                if (is_array($cmd) && LocalRoot::action($cmd) === 'compose.runningServices') {
                    return $this->running ? "php\n" : '';
                }
                if (str_contains($line, 'ps --services')) {
                    // As Compose does when the file it is pointed at is gone.
                    if (preg_match('/-f (\S+)/', $line, $m) === 1 && !is_file($m[1])) {
                        throw new \Exception("compose file \"{$m[1]}\" is invalid: open {$m[1]}: no such file or directory");
                    }
                    return '';
                }
                if (str_contains($line, 'mkdir')) {
                    $this->simulateMkdir($line);
                }
                if (($root[0] ?? '') === 'cp') {
                    $this->simulateCp($root);
                }
                if (str_contains($line, 'rm -rf')) {
                    $this->simulateRmRf($line);
                }
                if (preg_match('/^mv -T -- (\S+) (\S+)$/', implode(' ', $root), $m) === 1 && file_exists($m[1])) {
                    rename($m[1], $m[2]);
                }

                return '';
            }

            /**
             * @param  list<string>  $cmd
             * @return list<string>
             */
            private function asRoot(array $cmd): array
            {
                return LocalRoot::isRequest($cmd) ? $this->plain($cmd) : [];
            }

            /**
             * {@see LocalRoot::plain()}, except home entries, in-home renames and reads and real paths, which pa-root-local cannot name here.
             *
             * @param  list<string>  $cmd
             * @return list<string>
             */
            private function plain(array $cmd): array
            {
                $values = LocalRoot::isRequest($cmd) ? LocalRoot::values($cmd) : [];

                return match (LocalRoot::action($cmd)) {
                    'scripts.makeHomeEntry' => ['mkdir', '-m', $values[1], '--', $values[0]],
                    'account.renameInHome' => ['mv', '-T', '--', $values[1], $values[2]],
                    'account.readInHome' => ['cat', '--', $values[1]],
                    'files.existingRealPaths' => ['realpath', '-e', '--', ...$values],
                    default => LocalRoot::plain($cmd),
                };
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->executed[] = $cmd;
                $root = is_array($cmd) ? $this->asRoot($cmd) : [];
                $line = is_array($cmd) ? implode(' ', $this->plain($cmd)) : $cmd;

                if (str_contains($line, 'mkdir')) {
                    $this->simulateMkdir($line);
                }
                if (($root[0] ?? '') === 'cp') {
                    $source = $root[2] ?? null;
                    $target = $root[3] ?? null;
                    if (is_string($source) && is_string($target) && is_file($source)) {
                        if (!is_dir(dirname($target))) {
                            mkdir(dirname($target), 0777, true);
                        }
                        copy($source, $target);
                    }
                }
                if (str_contains($line, 'rm -rf')) {
                    $this->simulateRmRf($line);
                }
                if (preg_match('/^mv -T -- (\S+) (\S+)$/', implode(' ', $root), $m) === 1 && file_exists($m[1])) {
                    rename($m[1], $m[2]);
                }
                // The account's own container is on the host, under its Compose label.
                $output = LocalRoot::action((array) $cmd) === 'docker.containerNamedWithLabel' ? "alice\n" : '';
                if (array_slice($root, 0, 4) === ['stat', '-c', '%d', '--']) {
                    $output = str_repeat("1\n", count($root) - 4);
                }
                if (array_slice($root, 0, 3) === ['realpath', '-e', '--']) {
                    $output = implode("\n", array_slice($root, 3))."\n";
                }

                return new class ($output) extends Process {
                    public function __construct(private string $output)
                    {
                        parent::__construct(['true']);
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }

                    public function getErrorOutput(): string
                    {
                        return '';
                    }

                    public function getOutput(): string
                    {
                        return $this->output;
                    }
                };
            }

            public function runProcessOnHost(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $cmd = HostCommand::argv($cmd);

                return $this->runProcess($cmd, $env);
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function getHomeFilesystemMountPoint(): string
                    {
                        return '/home';
                    }

                    public function getHomeFilesystemParentBlockDevice(): string
                    {
                        return '';
                    }

                    public function directoryExists(string $path): bool
                    {
                        return is_dir($path);
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
                    }

                    public function fileExists(string $path): bool
                    {
                        return is_file($path);
                    }

                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        if (!is_dir(dirname($path))) {
                            mkdir(dirname($path), 0777, true);
                        }
                        file_put_contents($path, $contents);
                    }

                    public function makeFileFromTemplate(
                        string $filePath,
                        string $templatePath,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        if (!is_dir(dirname($filePath))) {
                            mkdir(dirname($filePath), 0777, true);
                        }
                        file_put_contents($filePath, "# stub cloudflared\n");
                    }

                    public function makeDirFromTemplate(
                        string $dir,
                        string $templateDir,
                        array $templateVars,
                        ?string $chown = null,
                        ?string $chmod = null,
                        array $exclude = [],
                    ): void {
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS)
                        );
                        foreach ($iterator as $file) {
                            if (!$file->isFile()) {
                                continue;
                            }
                            $rel = substr($file->getPathname(), strlen(rtrim($templateDir, '/\\')) + 1);
                            $rel = str_replace('\\', '/', $rel);
                            if (in_array($rel, $exclude, true)) {
                                continue;
                            }
                            if (str_ends_with($rel, '.blade.php')) {
                                continue;
                            }
                            $target = rtrim($dir, '/\\') . '/' . $rel;
                            if (!is_dir(dirname($target))) {
                                mkdir(dirname($target), 0777, true);
                            }
                            copy($file->getPathname(), $target);
                        }
                    }
                };
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

            /** @param list<string> $argv */
            private function simulateCp(array $argv): void
            {
                $parts = array_values(array_filter($argv, static fn (string $word): bool => $word !== '--'));
                $source = $parts[1] ?? null;
                $target = $parts[2] ?? null;
                if (is_string($source) && is_string($target)) {
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0777, true);
                    }
                    if (is_file($source)) {
                        copy($source, $target);
                    } elseif (is_dir($source)) {
                        if (!is_dir($target)) {
                            mkdir($target, 0777, true);
                        }
                    }
                }
            }

            private function simulateMkdir(string $line): void
            {
                if (!preg_match('/mkdir(?: -p)?(?: -m \d+)?(?: --)? (.+)$/', $line, $m)) {
                    return;
                }
                $path = trim(explode(' &&', $m[1])[0]);
                if ($path !== '' && !is_dir($path)) {
                    mkdir($path, 0777, true);
                }
            }

            private function simulateRmRf(string $line): void
            {
                if (!preg_match('/rm -rf(?: --)? (.+)$/', $line, $m)) {
                    return;
                }
                $path = trim($m[1]);
                if (is_dir($path)) {
                    $this->removeTree($path);
                }
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
        };
    }

    private function seedPhpTemplate(string $projectTemplate): void
    {
        mkdir($projectTemplate . '/entrypoint-init.d', 0777, true);
        mkdir($projectTemplate . '/crontabs', 0777, true);
        touch($projectTemplate . '/crontabs/www-data');
        mkdir($projectTemplate . '/php', 0777, true);
        file_put_contents($projectTemplate . '/php/versions-available', "8.3\n");
        mkdir($projectTemplate . '/php/8.3/fpm/pool.d', 0777, true);
        file_put_contents($projectTemplate . '/php/8.3/fpm/pool.d/www.conf', "; pool\n");
        file_put_contents($projectTemplate . '/Dockerfile-fpm', 'FROM scratch');
        file_put_contents($projectTemplate . '/docker-compose.yml-fpm', "services:\n  php:\n    image: test\n");
    }

    private function setCurrentWebserver(?string $webserver): void
    {
        $property = (new \ReflectionClass(Webserver::class))->getProperty('currentWebserver');
        $property->setAccessible(true);
        $property->setValue(null, $webserver);
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
