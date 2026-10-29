<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Source\EngineArtifactMigration;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * Migrating an account the previous engine deployed copies its compose file
 * to the run file as root. A compose file the tenant replaced with a link to
 * another account's file must be copied as that link, never as its contents,
 * and a link already at the run file's name must not be written through.
 */
class EngineArtifactMigrationCopyTest extends TestCase
{
    private string $root;

    /** @var list<list<string>> */
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-migrate-copy-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/alice/project', 0777, true);
        mkdir($this->root.'/home/bob/project', 0777, true);
        file_put_contents($this->root.'/home/bob/project/docker-compose.yml', "services:\n  bob: {}\n");
        symlink($this->root.'/home/bob/project/docker-compose.yml', $this->root.'/home/alice/project/docker-compose.yml');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_the_compose_file_is_copied_without_following_a_link(): void
    {
        EngineArtifactMigration::forProject($this->dind())->migrate();

        $this->assertCount(1, $this->copies);
        $this->assertContains('--no-dereference', $this->copies[0]);
        $this->assertContains('--remove-destination', $this->copies[0]);
        $this->assertSame($this->root.'/home/alice/project/'.EngineArtifacts::RUN_COMPOSE, end($this->copies[0]));
    }

    private function dind(): DindProject
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'deploy_strategy' => 'compose']);
        $copies = &$this->copies;
        $system = new class(new LocalHostSystem($this->root, $this->root.'/home'), $this->root, $copies) extends System
        {
            /** @param list<list<string>> $copies */
            public function __construct(private LocalHostSystem $local, private string $root, private array &$copies) {}

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                if (array_slice((array) $cmd, 0, 2) === ['sudo', 'cp']) {
                    $this->copies[] = (array) $cmd;

                    return '';
                }

                return $this->local->exec($cmd, $env, $timeout);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                return $this->local->runProcess($cmd, $env, $timeout);
            }
        };
        $runtime = (new ProjectAggregate($system, $model))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }
}
