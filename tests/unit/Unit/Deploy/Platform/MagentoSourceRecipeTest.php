<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\StageResolver;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Magento is the case the per-repository directory exists for.
 *
 * The shipped `magento` recipe gets the document root and the database right
 * and stops there, because a manifest has no way to ask for a search engine
 * and no way to run an installer that wants credentials. Everything that
 * closes those two gaps lives in this directory, so what is asserted here is
 * that the pieces are present and joined up -- not that Magento installs,
 * which only a deploy can say.
 */
class MagentoSourceRecipeTest extends TestCase
{
    private const URL = 'https://github.com/magento/magento2';

    private function directory(): string
    {
        return SourceRecipes::defaultDirectory() . '/github.com/magento/magento2';
    }

    private function appConfig(): AppConfig
    {
        $config = AppConfigDirectory::read(new LocalAppConfigSource(), $this->directory(), true);
        $this->assertNotNull($config);

        return $config;
    }

    public function test_the_repository_url_resolves_to_the_magento_recipe(): void
    {
        $recipe = SourceRecipes::for(self::URL);

        $this->assertNotNull($recipe, 'no recipe found for ' . self::URL);
        $this->assertSame('magento', $recipe->id);
        $this->assertSame('php', $recipe->runtime);
        $this->assertSame('mysql', $recipe->database);
        // Served from the repository root, Magento's own .htaccess bounces
        // into pub/ and the request loops until curl gives up at fifty hops.
        $this->assertSame('pub', $recipe->docroot);
    }

    /**
     * An app config applies its own `commands` on top of whatever manifest is
     * in play -- they are deliberately not copied into the manifest, or every
     * one would run twice. So the effective stage is the merge, and what this
     * pins is the order: the recipe's writable-dirs before this directory's
     * installer. setup:install stops on the first directory it cannot create.
     */
    public function test_the_install_ritual_runs_after_the_recipe_makes_its_directories(): void
    {
        $recipe = SourceRecipes::for(self::URL);
        $config = $this->appConfig();
        $this->assertNotNull($recipe);

        $ids = array_map(
            static fn ($c) => $c->id,
            StageResolver::commandsFor(PlatformStage::INSTALL, $recipe, $config)
        );

        $this->assertSame(['writable-dirs', 'magento-install'], $ids);
        $this->assertSame(
            ['magento-upgrade'],
            array_map(
                static fn ($c) => $c->id,
                array_values(array_filter(
                    StageResolver::commandsFor(PlatformStage::UPGRADE, $recipe, $config),
                    static fn ($c) => $c->id !== 'writable-dirs'
                ))
            )
        );
    }

    /**
     * The recipe this extends must keep declaring the directories, since this
     * directory no longer repeats them.
     */
    public function test_the_extended_recipe_still_makes_the_writable_directories(): void
    {
        $shipped = PlatformRegistry::find('magento');
        $this->assertNotNull($shipped);

        $ids = array_map(static fn ($c) => $c->id, $shipped->commands);
        $this->assertContains('writable-dirs', $ids);
    }

    /** The search engine a manifest cannot ask for, supplied as a layer. */
    public function test_it_layers_opensearch_over_the_generated_compose(): void
    {
        $config = $this->appConfig();

        $compose = $config->compose();
        $this->assertNotNull($compose, 'no compose contribution');
        $this->assertSame(
            AppConfig::COMPOSE_OVERRIDE,
            $config->composeMode(),
            'must layer over the generated file, never replace it'
        );

        $parsed = Yaml::parse($compose);
        $this->assertArrayHasKey('opensearch', $parsed['services']);
        // setup:install talks to the search engine partway through; a refused
        // connection leaves a half-written database the next attempt will not
        // install over.
        $this->assertSame(
            'service_healthy',
            $parsed['services']['app']['depends_on']['opensearch']['condition']
        );
        $this->assertArrayHasKey('healthcheck', $parsed['services']['opensearch']);
    }

    /**
     * The heap has to fit inside whatever ceiling the hardener applies, with
     * room for the JVM's own off-heap use. Pinned because raising one without
     * the other is how you get a cgroup kill on the first indexing run.
     */
    public function test_the_opensearch_heap_is_bounded(): void
    {
        $env = Yaml::parse((string) $this->appConfig()->compose())['services']['opensearch']['environment'];

        $this->assertSame('-Xms512m -Xmx512m', $env['OPENSEARCH_JAVA_OPTS']);

        // ServiceHardener only fills in a limit the author did not write, and
        // the catalogue's elasticsearch entry (opensearch is its alias) is
        // 384m -- below this heap. Leaving mem_limit off would hand the
        // service a ceiling smaller than the heap it was told to reserve.
        $service = Yaml::parse((string) $this->appConfig()->compose())['services']['opensearch'];
        $this->assertSame('1536m', $service['mem_limit']);

        // An account is a Sysbox container; its nested containers cannot raise
        // RLIMIT_MEMLOCK above the parent's, and asking is fatal -- "error
        // setting rlimit type 8: operation not permitted", before the container
        // starts.
        $this->assertArrayNotHasKey('memlock', $service['ulimits'] ?? []);
    }

    public function test_the_scripts_the_install_and_the_panel_call_are_shipped(): void
    {
        $dir = $this->directory();

        // The manifest's install command runs this by path inside the
        // container; a rename that missed one would be a deploy-time failure.
        $this->assertFileExists($dir . '/files/panelalpha/install.sh');
        $this->assertFileExists($dir . '/files/panelalpha/app.php');
        $this->assertFileExists($dir . '/overrides/app.sh');
        $this->assertFileExists($dir . '/hooks/prepare.sh');

        $run = implode(' ', array_map(static fn ($c) => $c->run, $this->appConfig()->commands()));
        $this->assertStringContainsString('/app/panelalpha/install.sh', $run);
    }

    /**
     * bin/magento is CLI and gets PHP's 128M default, while Magento's own
     * pub/.user.ini raises only the web limit to 756M. The first install
     * attempt died at module 245 of 904 on exactly that.
     */
    public function test_every_magento_command_is_run_without_the_cli_memory_cap(): void
    {
        $dir = $this->directory();
        $install = file_get_contents($dir . '/files/panelalpha/install.sh');
        $app = file_get_contents($dir . '/overrides/app.sh');

        $this->assertMatchesRegularExpression('/php -d memory_limit=-1 bin\/magento/', (string) $install);
        foreach (['/app/panelalpha/app.php', 'bin/magento'] as $invoked) {
            $this->assertStringNotContainsString(
                'exec -T app php ' . $invoked,
                (string) $app,
                'a Magento command is being run with the default CLI memory limit'
            );
        }
    }

    /**
     * `info` is answered without a running container, and it must not offer
     * what app.php cannot do -- the engine will not call an unlisted command,
     * but it will call a listed one.
     */
    public function test_info_advertises_only_what_the_helper_implements(): void
    {
        $dir = $this->directory();
        $app = (string) file_get_contents($dir . '/overrides/app.sh');
        $helper = (string) file_get_contents($dir . '/files/panelalpha/app.php');

        $this->assertMatchesRegularExpression('/echo \'\[(.+)\]\'/', $app, 'info answers no JSON array');
        preg_match('/echo \'(\[.+\])\'/', $app, $m);
        $advertised = json_decode($m[1], true);
        $this->assertIsArray($advertised);

        foreach ($advertised as $command) {
            if ($command === 'install') {
                // Handled by app.sh itself, which drives install.sh.
                $this->assertStringContainsString("= 'install' ]", $app);

                continue;
            }
            $this->assertStringContainsString("case '{$command}':", $helper, "{$command} is advertised but not implemented");
        }

        // Magento's admin session has no token endpoint to mint one from, so
        // none of the three SSO patterns fits without forging a session.
        $this->assertNotContains('users:sso', $advertised);
    }
}
