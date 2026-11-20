<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Inspect\RecipeFileOverlay;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\AppConfigStack;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Dind;
use App\System\Project\Dind\AppManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The WordPress/WordPress recipe's app script execs its compose stack's `wordpress` service, which a deploy pinned
 * to the `wordpress` recipe does not run, so app management is refused before anything runs.
 */
class WordPressSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/WordPress/WordPress';

    private string $checkout = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = sys_get_temp_dir().'/pa-wordpress-source-'.bin2hex(random_bytes(6));
        foreach (['wp-settings.php', 'wp-login.php', 'wp-includes/version.php', 'wp-admin/index.php'] as $file) {
            @mkdir(dirname($this->checkout.'/'.$file), 0777, true);
            file_put_contents($this->checkout.'/'.$file, '<?php');
        }
        RecipeFileOverlay::apply($this->checkout, self::URL);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->remove($this->checkout);
        parent::tearDown();
    }

    public function test_deployed_from_its_own_compose_stack_the_app_config_fits(): void
    {
        $decision = DetectProjectStrategy::detect($this->checkout, self::URL);

        $this->assertSame(Strategies::COMPOSE, $decision['strategy']);
        $this->assertTrue(AppConfigStack::fits($this->appConfig(), $decision));
    }

    public function test_pinned_to_the_wordpress_recipe_app_management_is_refused_before_anything_runs(): void
    {
        $decision = DetectProjectStrategy::detect($this->checkout, self::URL, 'wordpress');
        $model = new User;
        $model->username = 'wp';
        $model->setDetails([
            'template' => 'dind',
            'git_repo' => self::URL,
            'deploy_strategy' => $decision['strategy'],
            AppManager::DETAIL_APP_CONFIG_FITS => AppConfigStack::fits($this->appConfig(), $decision),
            'UID' => 1000,
            'GID' => 1000,
        ]);
        $runtime = (new Project($this->systemThatRunsNothing(), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        $this->assertSame(Strategies::PHP, $decision['strategy']);
        $this->expectExceptionMessage('App management is not supported for this application');
        $runtime->apps()->listUsers();
    }

    private function appConfig(): AppConfig
    {
        $appConfig = AppConfigLocator::findShipped(new LocalAppConfigSource, self::URL);
        $this->assertNotNull($appConfig);

        return $appConfig;
    }

    private function systemThatRunsNothing(): System
    {
        return new class extends System
        {
            public function __construct() {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('App management ran a command: '.(is_array($cmd) ? implode(' ', $cmd) : $cmd));
            }
        };
    }
}
