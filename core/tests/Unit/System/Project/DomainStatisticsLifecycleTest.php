<?php

namespace Tests\Unit\System\Project;

use App\Integrations\Statistics\Statistics;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Services\Webserver;
use App\System\Services\Webserver\WebserverInterface;
use Tests\TestCase;
use Tests\Unit\Integrations\Statistics\FakeStatistics;

class DomainStatisticsLifecycleTest extends TestCase
{
    public function test_create_ensures_provider_config(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);
        [$project, $domainModel, $tmpRoot] = $this->dindFixture();

        try {
            $project->domain($domainModel)->create();
            $this->assertSame(['app.example.test'], $fake->configured);
            $this->assertSame([], $fake->forgotten);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_delete_removes_that_domain_stats(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);
        [$project, $domainModel, $tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('deleteDomainConfig')->with('app.example.test');
        $driver->expects($this->once())->method('reload');

        try {
            $project->domain($domainModel)->delete();
            $this->assertSame(['app.example.test'], $fake->forgotten);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    public function test_rebuild_keeps_stats_data(): void
    {
        $fake = new FakeStatistics();
        $this->app->instance(Statistics::class, $fake);
        [$project, $domainModel, $tmpRoot, $driver] = $this->dindFixture();
        $driver->expects($this->once())->method('rebuildDomainConfig')->with($domainModel);

        try {
            $project->domain($domainModel)->rebuild();
            $this->assertSame([], $fake->forgotten);
            $this->assertSame([], $fake->configured);
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    /**
     * @return array{0: Project, 1: DomainModel, 2: string, 3: WebserverInterface}
     */
    private function dindFixture(): array
    {
        $user = $this->createStub(ModelsUser::class);
        $user->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'username' ? 'alice' : null
        );
        $user->method('getUid')->willReturn(1000);
        $user->method('getGid')->willReturn(1000);
        $user->method('hasGitProject')->willReturn(false);
        $user->method('getTemplate')->willReturn('dind');

        $domainModel = new DomainModel();
        $domainModel->domain = 'app.example.test';
        $domainModel->details = [
            'ssl_disabled' => true,
            'document_root' => '/public_html',
            'aliases' => ['www.app.example.test'],
        ];

        $driver = $this->createMock(WebserverInterface::class);
        $driver->method('addDomain');
        $driver->method('reload');
        $driver->method('deleteDomainLogsDir');

        $tmpRoot = sys_get_temp_dir() . '/pa-domain-stats-' . bin2hex(random_bytes(4));
        $home = $tmpRoot . '/home/alice';
        mkdir($home . '/public_html', 0777, true);

        $system = new class ($tmpRoot, $home, $driver) extends System {
            public function __construct(
                private string $engineRoot,
                private string $aliceHome,
                private WebserverInterface $driver,
            ) {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function homesDirPath(): string
            {
                return dirname($this->aliceHome);
            }

            public function projectHomeDirPath(string $username): string
            {
                return $this->aliceHome;
            }

            public function webserver(): Webserver
            {
                $driver = $this->driver;

                return new class ($driver) extends Webserver {
                    public function __construct(private WebserverInterface $driver)
                    {
                    }

                    public function driver(?string $slug = null): WebserverInterface
                    {
                        return $this->driver;
                    }

                    public function getCurrentWebserver(): string
                    {
                        return 'nginx-proxy';
                    }
                };
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                return '';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isDir(string $target): bool
                    {
                        return is_dir($target);
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
                    }
                };
            }
        };

        return [new Project($system, $user), $domainModel, $tmpRoot, $driver];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
