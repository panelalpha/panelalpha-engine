<?php

namespace Tests\Unit\System\Project\Dind;

use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * Folding the account's env_vars into the run file the engine generated, which
 * bakes them into `environment:` where they outrank `env_file:`.
 */
class ComposeWriterMergeAccountEnvTest extends TestCase
{
    private string $tmpRoot;
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->tmpRoot = sys_get_temp_dir() . '/pa-compose-env-' . bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot . '/home/alice/project';
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function test_the_account_variables_replace_the_baked_ones(): void
    {
        $path = $this->writeRunFile($this->generatedCompose([
            'APP_ENV' => 'production',
            'KEEP' => 'remove-me',
            'PA_DEPLOY_PHASE' => 'install',
            'PORT' => 3000,
        ]));

        $changed = $this->dind()->composeWriter()->mergeAccountEnv(['APP_ENV' => 'staging', 'ADDED' => 'one'], ['KEEP']);

        $this->assertTrue($changed);
        $environment = $this->environment($path);
        $this->assertSame('staging', $environment['APP_ENV']);
        $this->assertArrayNotHasKey('KEEP', $environment);
        $this->assertSame('one', $environment['ADDED']);
        $this->assertSame('install', $environment['PA_DEPLOY_PHASE']);
        $this->assertSame(3000, $environment['PORT']);
    }

    public function test_a_file_the_repository_owns_is_left_alone(): void
    {
        $path = $this->writeRunFile("services:\n  app:\n    image: nginx:alpine\n    environment:\n      APP_ENV: production\n");

        $changed = $this->dind()->composeWriter()->mergeAccountEnv(['APP_ENV' => 'staging'], []);

        $this->assertFalse($changed);
        $this->assertSame('production', $this->environment($path)['APP_ENV']);
    }

    public function test_nothing_set_and_nothing_removed_leaves_the_file_alone(): void
    {
        $path = $this->writeRunFile($this->generatedCompose(['APP_ENV' => 'production']));
        $before = (string) file_get_contents($path);

        $this->assertFalse($this->dind()->composeWriter()->mergeAccountEnv([], []));
        $this->assertSame($before, (string) file_get_contents($path));
    }

    private function dind(): DindProject
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot . '/home'), $this->model()))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }

    private function model(): ModelsUser
    {
        $model = new class extends ModelsUser {
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

    /**
     * @param array<string, mixed> $environment
     */
    private function generatedCompose(array $environment): string
    {
        return Yaml::dump([
            'services' => [
                'app' => [
                    'image' => 'nginx:alpine',
                    'labels' => ['panelalpha.generated' => 'framework'],
                    'environment' => $environment,
                ],
            ],
        ], 6, 2);
    }

    private function writeRunFile(string $yaml): string
    {
        $path = $this->dind()->userAppComposeFilePath();
        file_put_contents($path, $yaml);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(string $path): array
    {
        $parsed = Yaml::parse((string) file_get_contents($path));

        return (array) ($parsed['services']['app']['environment'] ?? []);
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
