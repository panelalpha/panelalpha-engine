<?php

namespace Tests\Unit\System\Project;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Credentials\AppCredentials;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Deployment\RecipeFallback;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
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
        app(RecipeChoiceContext::class)->clear();
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
            'deploy_admin_sso',
            'deploy_app_config_fits',
            'git_commit',
        ] as $key) {
            $this->assertArrayHasKey($key, $details, "missing freeze key {$key}");
        }

        $this->assertSame('archive', $details['deploy_source']);
        $this->assertSame('static', $details['deploy_strategy']);
        $this->assertNotSame('', (string) $details['deploy_label']);
        $this->assertNull($details['git_commit']);
        $this->assertFalse($details['deploy_admin_sso']);
        $this->assertTrue($details['deploy_app_config_fits']);
        $this->assertFileExists($this->projectDir . '/' . EngineArtifacts::RUN_COMPOSE);
    }

    /** The last deploy fell back to Express after PHP failed: this one starts there, and says so. */
    public function test_a_remembered_fallback_recipe_is_tried_first(): void
    {
        $this->writeExpressBesideComposer();
        $model = $this->dindModel([RecipeFallback::DETAIL_RECIPE => 'express']);

        $logger = DeployLogger::start('alice');
        try {
            $this->dind($model)->prepareFromSources();
            $messages = array_column($logger->tail(), 'msg');
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }

        $this->assertSame('express', $model->getDetails()['deploy_platform']);
        $this->assertSame('express', app(RecipeChoiceContext::class)->fallback());
        $this->assertContains(
            'Starting with recipe express, which the last deploy fell back to after the detected one failed',
            $messages
        );
    }

    /** A remembered recipe the checkout no longer matches is dropped for detection. */
    public function test_a_remembered_recipe_that_no_longer_claims_the_project_is_ignored(): void
    {
        $this->writeExpressBesideComposer();
        unlink($this->projectDir . '/composer.json');
        $model = $this->dindModel([RecipeFallback::DETAIL_RECIPE => 'php']);

        $this->dind($model)->prepareFromSources();

        $this->assertSame('express', $model->getDetails()['deploy_platform']);
        $this->assertNull(app(RecipeChoiceContext::class)->get());
    }

    /** The last deploy installed with npm after Bun failed: this one does too, and the decision freezes it. */
    public function test_a_remembered_package_manager_is_installed_with_first(): void
    {
        $this->writeExpressBesideComposer();
        unlink($this->projectDir . '/composer.json');
        file_put_contents($this->projectDir . '/package.json', '{"packageManager":"bun@1.1.0","scripts":{"start":"node server.js"},"dependencies":{"express":"^4"}}');
        file_put_contents($this->projectDir . '/bun.lockb', 'binary');
        file_put_contents($this->projectDir . '/package-lock.json', '{"lockfileVersion":3}');
        $model = $this->dindModel([RecipeFallback::DETAIL_PACKAGE_MANAGER => 'npm']);

        $this->dind($model)->prepareFromSources();

        $this->assertSame('npm', app(RecipeChoiceContext::class)->packageManager());
        $this->assertSame('npm', $model->getDetails()[RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER]);
    }

    /** Without the remembered one the same project installs with Bun, which it asks for. */
    public function test_the_package_manager_detection_picks_is_frozen(): void
    {
        $this->writeExpressBesideComposer();
        unlink($this->projectDir . '/composer.json');
        file_put_contents($this->projectDir . '/package.json', '{"packageManager":"bun@1.1.0","scripts":{"start":"node server.js"},"dependencies":{"express":"^4"}}');
        file_put_contents($this->projectDir . '/bun.lockb', 'binary');
        file_put_contents($this->projectDir . '/package-lock.json', '{"lockfileVersion":3}');
        $model = $this->dindModel();

        $this->dind($model)->prepareFromSources();

        $this->assertSame('bun', $model->getDetails()[RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER]);
    }

    /** A recipe the request named outranks the remembered one. */
    public function test_a_requested_recipe_outranks_the_remembered_one(): void
    {
        $this->writeExpressBesideComposer();
        app(RecipeChoiceContext::class)->set('node');
        $model = $this->dindModel([RecipeFallback::DETAIL_RECIPE => 'express']);

        $this->dind($model)->prepareFromSources();

        $this->assertSame('node', $model->getDetails()['deploy_platform']);
    }

    private function writeExpressBesideComposer(): void
    {
        file_put_contents($this->projectDir . '/composer.json', '{"require":{"php":">=8.1","monolog/monolog":"^3"}}');
        file_put_contents($this->projectDir . '/index.php', '<?php echo "hi";');
        file_put_contents($this->projectDir . '/package.json', '{"scripts":{"start":"node server.js"},"dependencies":{"express":"^4"}}');
        file_put_contents($this->projectDir . '/server.js', 'require("express")().listen(3000)');
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

    /**
     * The same `.panelalpha/` directory is not the repository's own unless the
     * log says so: the engine ships one per repository under the same name.
     */
    public function test_the_deploy_log_names_where_the_app_config_came_from(): void
    {
        mkdir($this->projectDir . '/.panelalpha', 0o777, true);
        file_put_contents(
            $this->projectDir . '/.panelalpha/panelalpha.yaml',
            "id: demo\nlabel: Demo\nstrategy: static\n"
        );
        file_put_contents($this->projectDir . '/index.html', '<!DOCTYPE html><title>Hi</title>');

        $logger = DeployLogger::start('alice');
        try {
            $this->dind($this->dindModel())->prepareFromSources();
            $messages = array_column($logger->tail(), 'msg');
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            DeployLogger::deleteUserLogs('alice');
        }

        $this->assertContains('Recipe named by .panelalpha (repository)', $messages);
    }

    /**
     * An app config that brings its own compose stack has an app script naming that stack's services. Pinned to
     * another recipe, the deploy runs a stack the script cannot reach, and that is frozen with it.
     */
    public function test_a_recipe_pinned_to_another_stack_freezes_that_the_app_config_does_not_fit(): void
    {
        $this->appConfig(withItsOwnCompose: true);
        app(RecipeChoiceContext::class)->set('static');

        $model = $this->dindModel();
        $this->dind($model)->prepareFromSources();

        $details = $model->getDetails();
        $this->assertSame('static', $details['deploy_strategy']);
        $this->assertFalse($details['deploy_app_config_fits']);
        $this->assertFalse($details['deploy_admin_sso']);
        $this->assertNull($details[AppHealth::DETAIL_START_PERIOD]);
    }

    /** The first pass delivered the app config's credentials; a pin to another stack does not forget them. */
    public function test_a_recipe_pinned_to_another_stack_keeps_the_credentials_the_app_config_declares(): void
    {
        $this->appConfig(withItsOwnCompose: true, config: "credentials:\n  fields:\n    PA_ADMIN_PASSWORD: {kind: password}\n");
        app(RecipeChoiceContext::class)->set('static');

        $model = $this->dindModel();
        $this->dind($model)->prepareFromSources();

        $this->assertFalse($model->getDetails()['deploy_app_config_fits']);
        $this->assertNotNull($model->getAppCredentials());
        $this->assertStringContainsString("PA_ADMIN_PASSWORD='", (string) @file_get_contents($this->homeRoot . '/alice/' . AppCredentials::ENV_FILE));
    }

    public function test_the_stack_an_app_config_is_written_for_freezes_that_it_fits_and_its_admin_sso(): void
    {
        $this->appConfig(withItsOwnCompose: false);

        $model = $this->dindModel();
        $this->dind($model)->prepareFromSources();

        $details = $model->getDetails();
        $this->assertSame('static', $details['deploy_strategy']);
        $this->assertTrue($details['deploy_app_config_fits']);
        $this->assertTrue($details['deploy_admin_sso']);
        $this->assertSame(120, $details[AppHealth::DETAIL_START_PERIOD]);
    }

    private function appConfig(bool $withItsOwnCompose, string $config = "health:\n  start_period: 120\n"): void
    {
        mkdir($this->projectDir . '/.panelalpha/overrides', 0o777, true);
        file_put_contents($this->projectDir . '/.panelalpha/panelalpha.yaml', $config);
        file_put_contents(
            $this->projectDir . '/.panelalpha/overrides/app.sh',
            "#!/bin/bash\n[ \"\$1\" = info ] && echo '[\"users:list\",\"users:sso\"]' && exit 0\nexec docker compose exec -T web php app.php \"\$@\"\n"
        );
        if ($withItsOwnCompose) {
            file_put_contents($this->projectDir . '/.panelalpha/overrides/docker-compose.yml', "services:\n  web:\n    image: nginx\n");
        }
        file_put_contents($this->projectDir . '/index.html', '<!DOCTYPE html><title>Hi</title>');
    }

    /** The engine's own old static bootstrap is passed over, and the log says so rather than leaving a silent hunt. */
    public function test_the_deploy_log_says_when_the_engines_own_compose_file_is_passed_over(): void
    {
        file_put_contents(
            $this->projectDir.'/docker-compose.yml',
            "services:\n  app:\n    image: nginx:alpine\n    ports:\n      - '8080:80'\n    volumes:\n      - './:/usr/share/nginx/html/:ro'\n"
        );
        file_put_contents($this->projectDir.'/index.html', '<!DOCTYPE html><title>Hi</title>');

        $logger = DeployLogger::start('alice');
        try {
            $this->dind($this->dindModel())->prepareFromSources();
            $messages = array_column($logger->tail(), 'msg');
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
            DeployLogger::deleteUserLogs('alice');
        }

        $this->assertContains(
            "Compose file docker-compose.yml reads as one the engine generated, not the project's own; using strategy static instead",
            $messages
        );
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
