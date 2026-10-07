<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use App\System\Project\Dind\SourceAccess;
use Tests\TestCase;

class DindPrepareFromSourceTest extends TestCase
{
    private string $tmpRoot;
    private string $homeRoot;
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');

        $this->tmpRoot = sys_get_temp_dir() . '/pa-dind-prepare-' . bin2hex(random_bytes(4));
        $this->homeRoot = $this->tmpRoot . '/home';
        $this->projectDir = $this->homeRoot . '/alice/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot . '/users/alice', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_prepare_is_on_dind_not_on_source(): void
    {
        $model = $this->dindModel(['deploy_strategy' => 'static']);
        $project = $this->dind($model);

        $this->assertTrue(method_exists($project, 'prepareFromSources'));
        $this->assertTrue(method_exists($project, 'preCheckFromSources'));
        $this->assertFalse(method_exists(SourceAccess::class, 'prepare'));
        $this->assertFalse(method_exists(SourceAccess::class, 'preCheck'));
    }

    public function test_prepare_freezes_the_same_snapshot_keys_as_lib_for_a_static_fixture(): void
    {
        file_put_contents(
            $this->projectDir . '/index.html',
            '<!DOCTYPE html><html><head><title>Hi</title></head><body>Hi</body></html>'
        );

        $model = $this->dindModel();
        $this->dind($model)->prepareFromSources();

        $details = $model->getDetails();
        foreach ([
            'deploy_source',
            'deploy_strategy',
            'deploy_label',
            'deploy_port',
            'deploy_runtime',
            'deploy_platform',
            'deploy_checks_dir',
            'deploy_image',
            'git_commit',
        ] as $key) {
            $this->assertArrayHasKey($key, $details, "missing freeze key {$key}");
        }

        $this->assertSame('archive', $details['deploy_source']);
        $this->assertSame('static', $details['deploy_strategy']);
        $this->assertNotSame('', (string) $details['deploy_label']);
        $this->assertNull($details['git_commit']);
        $this->assertFileExists($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
    }

    public function test_pre_check_is_a_no_op_when_the_repo_has_no_precheck_script(): void
    {
        $model = $this->dindModel([
            'git_repo' => 'https://github.com/example/no-recipe.git',
            'git_branch' => 'main',
        ]);

        $this->dind($model)->preCheckFromSources();

        $this->assertFileDoesNotExist($this->homeRoot . '/alice/panelalpha-before-clone-validation.sh');
    }

    private function dind(ModelsUser $model): Dind
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->homeRoot), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }

    private function dindModel(array $details = []): ModelsUser
    {
        $model = new class extends ModelsUser {
            public function save(array $options = []): bool
            {
                return true;
            }

            // getMainDomain() queries the database; this fixture has no domain.
            public function getMainDomain(): ?DomainModel
            {
                return null;
            }
        };
        $model->username = 'alice';
        $model->setDetails(array_merge([
            'template' => 'dind',
            'UID' => 1000,
            'GID' => 1000,
        ], $details));

        return $model;
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
