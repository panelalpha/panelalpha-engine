<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * The run file sits in the account's own project directory, where the tenant
 * can put a symlink in its place. The engine writes it as root, so it must
 * never write through such a link or read what the link points at.
 */
class ComposeWriterRunFileSymlinkTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir().'/pa-run-file-link-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/home/alice/project', 0777, true);
        $this->outside = $this->root.'/elsewhere.yml';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_generated_run_file_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        $dind = $this->dind();
        $this->linkRunFileTo('untouched');

        $dind->composeWriter()->writeGeneratedCompose($dind->userAppDirPath(), "services:\n  app:\n    image: nginx:alpine\n", null);

        $this->assertSame('untouched', file_get_contents($this->outside));
        $this->assertFalse(is_link($dind->userAppComposeFilePath()));
        $this->assertStringContainsString('nginx:alpine', (string) file_get_contents($dind->userAppComposeFilePath()));
    }

    public function test_merging_the_account_variables_refuses_a_symlinked_run_file(): void
    {
        $dind = $this->dind();
        $this->linkRunFileTo($this->generatedCompose());

        try {
            $dind->composeWriter()->mergeAccountEnv(['APP_ENV' => 'staging'], []);
            $this->fail('A symlinked run file was rewritten.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('it is a symlink', $e->getMessage());
        }

        $this->assertSame($this->generatedCompose(), file_get_contents($this->outside));
        $this->assertTrue(is_link($dind->userAppComposeFilePath()));
    }

    public function test_merging_the_api_access_refuses_a_symlinked_run_file(): void
    {
        $dind = $this->dind();
        $this->linkRunFileTo($this->generatedCompose());

        try {
            $dind->composeWriter()->mergeApiAccessEnv(['PANELALPHA_ENGINE_TOKEN' => 'secret']);
            $this->fail('A symlinked run file was rewritten.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('it is a symlink', $e->getMessage());
        }

        $this->assertSame($this->generatedCompose(), file_get_contents($this->outside));
        $this->assertTrue(is_link($dind->userAppComposeFilePath()));
    }

    private function linkRunFileTo(string $contents): void
    {
        file_put_contents($this->outside, $contents);
        symlink($this->outside, $this->dind()->userAppComposeFilePath());
    }

    private function generatedCompose(): string
    {
        return Yaml::dump([
            'services' => [
                'app' => [
                    'image' => 'nginx:alpine',
                    'labels' => ['panelalpha.generated' => 'framework'],
                    'environment' => ['APP_ENV' => 'production'],
                ],
            ],
        ], 6, 2);
    }

    private function dind(): DindProject
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $this->model()))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }

    private function model(): ModelsUser
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = 'alice';
        $model->domain = 'alice.example.test';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        return $model;
    }
}
