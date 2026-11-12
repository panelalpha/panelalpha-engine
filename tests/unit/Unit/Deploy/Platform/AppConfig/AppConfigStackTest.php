<?php

namespace Tests\Unit\Deploy\Platform\AppConfig;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigLocator;
use App\Lib\Deploy\Platform\AppConfig\AppConfigStack;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\Strategies;
use PHPUnit\Framework\TestCase;

/**
 * The stack an app config is written for, and so the deploys it speaks for.
 */
class AppConfigStackTest extends TestCase
{
    public function test_a_compose_file_of_its_own_speaks_only_for_a_stack_that_runs_it(): void
    {
        $appConfig = $this->config(['compose' => "services:\n  web:\n    image: nginx\n", 'app' => "#!/bin/bash\n"]);

        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => 'compose', 'strategy' => Strategies::COMPOSE]));
        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => null, 'strategy' => Strategies::PAEMD]));
        $this->assertFalse(AppConfigStack::fits($appConfig, ['platform' => 'wordpress', 'strategy' => Strategies::PHP]));
    }

    public function test_a_compose_file_layered_over_the_engines_speaks_for_any_stack(): void
    {
        $appConfig = $this->config(['compose' => "services:\n  app:\n    environment: {A: b}\n", 'compose_mode' => AppConfig::COMPOSE_OVERRIDE]);

        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => 'php', 'strategy' => Strategies::PHP]));
    }

    public function test_a_declared_recipe_speaks_only_for_itself(): void
    {
        $appConfig = $this->config(['config' => "extends: php\n"]);

        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => 'php', 'strategy' => Strategies::PHP]));
        $this->assertFalse(AppConfigStack::fits($appConfig, ['platform' => 'wordpress', 'strategy' => Strategies::PHP]));
    }

    /** The digiquiz recipe is `ladigitale-spa-php`, an instance of `php-plain`: written for that base too. */
    public function test_a_declared_recipe_speaks_for_the_recipe_it_extends(): void
    {
        $appConfig = AppConfigLocator::findShipped(new LocalAppConfigSource, 'https://codeberg.org/ladigitale/digiquiz');
        $this->assertNotNull($appConfig);

        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => 'ladigitale-spa-php', 'strategy' => Strategies::PHP]));
        $this->assertTrue(AppConfigStack::fits($appConfig, ['platform' => 'php-plain', 'strategy' => Strategies::PHP]));
        $this->assertFalse(AppConfigStack::fits($appConfig, ['platform' => 'laravel', 'strategy' => Strategies::LARAVEL]));
        $this->assertFalse(AppConfigStack::fits($appConfig, ['platform' => null, 'strategy' => Strategies::RAILPACK]));
    }

    public function test_an_app_config_with_no_stack_of_its_own_speaks_for_any(): void
    {
        $appConfig = $this->config(['app' => "#!/bin/bash\n"]);

        $this->assertSame($appConfig, AppConfigStack::for(['platform' => 'static', 'strategy' => Strategies::STATIC], $appConfig));
        $this->assertNull(AppConfigStack::for(['platform' => 'static', 'strategy' => Strategies::STATIC], null));
    }

    /** @param  array<string, mixed>  $parts */
    private function config(array $parts): AppConfig
    {
        $appConfig = AppConfig::fromDirectory($parts);
        $this->assertNotNull($appConfig);

        return $appConfig;
    }
}
