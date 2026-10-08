<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Filesystem;
use App\System\Project;
use App\System\Project\Cron;
use PHPUnit\Framework\TestCase;

class ProjectCronTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-project-cron-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/users/alice/crontabs', 0777, true);
        touch($this->tmpRoot . '/users/alice/crontabs/www-data');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_project_cron_returns_collaborator_at_engine_crontab_path(): void
    {
        $system = $this->systemWithDirectFilesystem($this->tmpRoot);
        $project = new Project($system, $this->userModel('alice'));

        $cron = $project->cron();

        $this->assertInstanceOf(Cron::class, $cron);
        $this->assertSame(
            $this->tmpRoot . '/users/alice/crontabs/www-data',
            $cron->crontabPath()
        );
    }

    public function test_list_create_delete_and_update_round_trip(): void
    {
        $system = $this->systemWithDirectFilesystem($this->tmpRoot);
        $cron = (new Project($system, $this->userModel('alice')))->cron();

        $this->assertSame([], $cron->list());

        $created = $cron->create([
            'command' => '/usr/bin/true',
            'minute' => '5',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
        ]);

        $this->assertSame('/usr/bin/true', $created['command']);
        $this->assertNotEmpty($created['hash']);

        $listed = $cron->list();
        $this->assertCount(1, $listed);
        $this->assertSame($created['hash'], $listed[0]['hash']);

        $this->assertTrue($cron->exists($created['hash']));

        $updated = $cron->update($created['hash'], [
            'command' => '/usr/bin/false',
            'minute' => '10',
            'hour' => '1',
            'day_of_month' => '2',
            'month' => '3',
            'day_of_week' => '4',
        ]);

        $this->assertSame('/usr/bin/false', $updated['command']);
        $this->assertSame('10', $updated['minute']);
        // The hash is derived from the job's fields, so an update hands back a new one.
        $this->assertNotSame($created['hash'], $updated['hash']);
        $this->assertFalse($cron->exists($created['hash']));
        $this->assertTrue($cron->exists($updated['hash']));

        $deleted = $cron->delete($updated['hash']);
        $this->assertSame($updated['hash'], $deleted['hash']);
        $this->assertSame([], $cron->list());
        $this->assertFalse($cron->exists($updated['hash']));
    }

    private function userModel(string $username): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = $username;
        $model->setDetails(['template' => 'dind', 'deploy_strategy' => 'php']);

        return $model;
    }

    private function systemWithDirectFilesystem(string $engineRoot): System
    {
        return new class ($engineRoot) extends System {
            public function __construct(private string $engineRoot)
            {
            }

            public function engineDirPath(): string
            {
                return $this->engineRoot;
            }

            public function filesystem(): Filesystem
            {
                return new class ($this) extends Filesystem {
                    public function fileGetContents(string $path): string
                    {
                        if (!is_file($path)) {
                            return '';
                        }

                        return (string) file_get_contents($path);
                    }

                    public function filePutContents(
                        string $path,
                        string $contents,
                        ?string $chown = null,
                        ?string $chmod = null,
                    ): void {
                        $dir = dirname($path);
                        if (!is_dir($dir)) {
                            mkdir($dir, 0777, true);
                        }
                        file_put_contents($path, $contents);
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
