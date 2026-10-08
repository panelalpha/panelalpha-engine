<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Deploy\Platform\StageResolver;
use PHPUnit\Framework\TestCase;

/**
 * Firefly III is a Laravel app that refuses to serve a page until its own
 * console commands have run.
 *
 * The shipped `laravel` recipe gets the document root, the database and the
 * frontend build right, and stops there. Firefly answers `/` with a 302 and
 * then `/login` with a 500, because its `Installer` middleware calls
 * `OAuthKeys::verifyKeysRoutine()` -> `Artisan::call('firefly-iii:laravel-passport-keys')`,
 * and that command is not registered on the HTTP kernel's console application.
 * Its own Docker image works because its entrypoint runs the same commands
 * from the CLI on every boot; this directory says the same thing in the
 * engine's vocabulary.
 *
 * What is asserted here is that the recipe resolves and that its commands
 * reach the right stages -- not that Firefly installs, which only a deploy
 * can say.
 */
class FireflySourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/firefly-iii/firefly-iii';

    private function directory(): string
    {
        return SourceRecipes::defaultDirectory() . '/github.com/firefly-iii/firefly-iii';
    }

    private function appConfig(): AppConfig
    {
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $this->directory(), true);
        $this->assertNotNull($config);

        return $config;
    }

    /** @return list<string> */
    private function ids(string $stage): array
    {
        $recipe = SourceRecipes::for(self::URL);
        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);

        return array_map(
            static fn ($c) => $c->id,
            StageResolver::commandsFor($stage, $recipe, $this->appConfig())
        );
    }

    public function test_the_repository_url_resolves_to_the_firefly_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('laravel', $recipe->id);
        $this->assertSame('laravel', $recipe->strategy);
        $this->assertSame('php', $recipe->runtime);
        // Unchanged from the shipped recipe: the application is at the root
        // and answers through public/.
        $this->assertSame('public', $recipe->docroot);
        $this->assertSame(8000, $recipe->port);
    }

    /**
     * The three commands the 500 depends on, in the stages that actually run
     * them. `install` is a fresh account, `upgrade` is a redeploy over data
     * that is already there -- both have to leave the app serving, so all
     * three are in both.
     */
    public function test_the_firefly_install_commands_run_on_install_and_upgrade(): void
    {
        foreach ([PlatformStage::INSTALL, PlatformStage::UPGRADE] as $stage) {
            $ids = $this->ids($stage);

            $this->assertContains('firefly-database', $ids, "{$stage} misses the database setup");
            $this->assertContains('firefly-passport-keys', $ids, "{$stage} misses the passport keys");
            $this->assertContains('firefly-latest-version', $ids, "{$stage} misses the version row");
        }
    }

    /**
     * The commands layer on top of the shipped recipe's own, they do not
     * replace them -- Firefly still needs `key:generate`, `migrate` and
     * `storage:link`, and dropping them would trade the 500 for a different
     * one. Pinned as a set so a future `commands:` that accidentally replaces
     * the stage is visible here.
     */
    public function test_the_shipped_laravel_commands_are_not_replaced(): void
    {
        $install = $this->ids(PlatformStage::INSTALL);

        foreach (['key-generate', 'migrate'] as $kept) {
            $this->assertContains($kept, $install, "the laravel recipe's {$kept} was lost");
        }
        $this->assertContains('storage-link', $this->ids(PlatformStage::START), "the laravel recipe's storage-link was lost");
    }

    /**
     * Every command has to be able to run in the container before anything
     * else does. `/login` is one redirect away from `/`, so the probe reports
     * the 302 and not the page behind it -- a recipe that never ran these
     * would read as a green deploy.
     */
    public function test_the_firefly_commands_are_not_optional_where_they_matter(): void
    {
        $commands = [];
        foreach ($this->appConfig()->commands() as $command) {
            $commands[$command->id] = $command;
        }

        $this->assertArrayHasKey('firefly-database', $commands);
        $this->assertFalse(
            $commands['firefly-database']->optional,
            'a database that was not created is a 500 on the login page'
        );
    }

    /**
     * The commands run through `php artisan` inside the container, which is
     * where the console kernel has the application's own command list. Running
     * them on the host build instead would resolve a different application --
     * one with no database and no generated key.
     */
    public function test_the_firefly_commands_run_artisan_in_the_application(): void
    {
        foreach ($this->appConfig()->commands() as $command) {
            $this->assertStringContainsString('php artisan firefly-iii:', $command->run);
        }
    }

    /**
     * Firefly's Vite frontend is the resources/assets/v3 workspace; the root
     * package.json has no build script, so the default pass compiles nothing.
     */
    public function test_the_frontend_is_built_from_its_workspace(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe);
        $this->assertSame('npm run build --workspace resources/assets/v3', $recipe->frontendBuild);
    }

    public function test_the_extended_recipe_is_the_shipped_laravel_one(): void
    {
        $shipped = PlatformRegistry::find('laravel');
        $this->assertNotNull($shipped);
        $this->assertContains('key-generate', array_map(static fn ($c) => $c->id, $shipped->commands));
    }
}
