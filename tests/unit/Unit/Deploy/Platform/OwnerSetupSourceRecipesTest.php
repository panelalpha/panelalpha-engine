<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Ghost and listmonk both ship an unauthenticated first-run page that makes
 * whoever submits it first the site's administrator. Their recipes
 * complete it with generated credentials kept in ~/.panelalpha, before the
 * application listens on its published port.
 *
 * What is asserted here is the wiring that makes "before" true. Whether the
 * setup itself succeeds is only something a deploy can say.
 */
class OwnerSetupSourceRecipesTest extends TestCase
{
    private function config(string $repo): AppConfig
    {
        $config = AppConfigDirectory::read(
            new LocalAppConfigSource(),
            SourceRecipes::defaultDirectory() . '/github.com/' . $repo,
            true
        );
        $this->assertNotNull($config);

        return $config;
    }

    /** @return array<string, mixed> */
    private function compose(AppConfig $config): array
    {
        $compose = Yaml::parse((string) $config->compose());
        $this->assertIsArray($compose);

        return $compose;
    }

    private function file(AppConfig $config, string $path): string
    {
        foreach ($config->files() as $file) {
            if ($file['path'] === $path) {
                return $file['contents'];
            }
        }
        $this->fail("the recipe ships no files/{$path}");
    }

    public function test_ghost_does_not_start_until_its_owner_is_set_up(): void
    {
        $config = $this->config('tryghost/ghost');
        $this->assertSame(AppConfig::COMPOSE_REPLACE, $config->composeMode());
        $services = $this->compose($config)['services'];

        $this->assertSame(
            ['condition' => 'service_completed_successfully'],
            $services['ghost']['depends_on']['init'] ?? null
        );
        $init = $services['init'];
        $this->assertArrayNotHasKey('ports', $init, 'the one-shot that runs the setup must publish nothing');
        $this->assertSame(['/bin/sh', '/pa/init.sh'], $init['entrypoint']);
        $this->assertContains('./panelalpha/ghost:/pa:ro', $init['volumes']);
        $this->assertSame(['../.panelalpha/app-credentials.env'], $init['env_file']);
        $this->assertArrayNotHasKey('env_file', $services['ghost'], 'only init reads the owner credentials');

        // The same Ghost, against the same database and content, at the same url.
        $this->assertSame($services['ghost']['environment'], $init['environment']);
        $this->assertContains('ghost_content:/var/lib/ghost/content', $init['volumes']);
    }

    public function test_ghost_setup_goes_through_ghosts_own_endpoint_on_loopback(): void
    {
        $config = $this->config('tryghost/ghost');
        $init = $this->file($config, 'panelalpha/ghost/init.sh');
        $claim = $this->file($config, 'panelalpha/ghost/claim.js');

        $this->assertStringContainsString('server__host=127.0.0.1', $init);
        $this->assertStringContainsString("'/ghost/api/admin/authentication/setup/'", $claim);
        $this->assertStringContainsString("host: '127.0.0.1'", $claim);
        // A failed setup must fail the deploy, not start an unclaimed site.
        $this->assertStringContainsString('exit "$rc"', $init);
    }

    /**
     * Staff device verification mails a code to every sign-in from a new
     * browser, and the recipe configures no mail: left on, the generated owner
     * could never sign in.
     */
    public function test_ghost_device_verification_is_off_unless_the_account_turns_it_on(): void
    {
        $env = $this->compose($this->config('tryghost/ghost'))['services']['ghost']['environment'];

        $this->assertSame('${GHOST_STAFF_DEVICE_VERIFICATION:-false}', $env['security__staffDeviceVerification']);
    }

    public function test_ghost_keeps_generated_secrets_outside_the_checkout(): void
    {
        $prepare = (string) $this->config('tryghost/ghost')->setupCommands();

        $this->assertStringContainsString('DATA_HOME="${HOME}/.panelalpha/ghost"', $prepare);
        $this->assertStringContainsString('DB_ENV="${DATA_HOME}/database.env"', $prepare);
        // The owner login is the engine's now, adopted from the file the hook used to write.
        $this->assertStringNotContainsString('credentials.txt', $prepare);
        $this->assertSame('.panelalpha/ghost/owner.env', $this->config('tryghost/ghost')->credentials()?->adoptFrom);
        // ~/project/.env is rebuilt from the persisted file, never generated into.
        $this->assertStringNotContainsString('if [ ! -f .env ]', $prepare);
    }

    public function test_listmonk_serves_only_after_its_super_admin_exists(): void
    {
        $config = $this->config('knadh/listmonk');
        $this->assertSame(AppConfig::COMPOSE_OVERRIDE, $config->composeMode());
        $app = $this->compose($config)['services']['app'];

        $this->assertSame(['sh', '/pa/start.sh'], $app['command']);
        $this->assertSame(['../.panelalpha/app-credentials.env'], $app['env_file']);
        $this->assertContains('./panelalpha/listmonk:/pa:ro', $app['volumes']);

        $start = $this->file($config, 'panelalpha/listmonk/start.sh');
        $install = strpos($start, 'LISTMONK_ADMIN_USER="${PA_ADMIN_USER}"');
        $loopback = strpos($start, 'PORT=127.0.0.1:');
        $serve = strrpos($start, "exec ./listmonk --config ''");
        $this->assertNotFalse($install);
        $this->assertNotFalse($loopback);
        $this->assertNotFalse($serve);
        $this->assertTrue($install < $loopback && $loopback < $serve, 'install, claim on loopback, then serve');
    }

    public function test_listmonk_takes_its_super_admin_from_the_engine(): void
    {
        $config = $this->config('knadh/listmonk');
        $prepare = (string) $config->setupCommands();

        // The engine generates and keeps the login; the hook no longer writes one.
        $this->assertSame(['PA_ADMIN_USER', 'PA_ADMIN_PASSWORD'], array_keys($config->credentials()?->fields ?? []));
        $this->assertSame('.panelalpha/listmonk/admin.env', $config->credentials()?->adoptFrom);
        $this->assertStringNotContainsString('admin.env', $prepare);
        $this->assertStringNotContainsString('credentials.txt', $prepare);
    }

    /**
     * The panel's install sets its own administrator. The setup form is gone
     * by the time it runs, so it cannot post to it any more.
     */
    public function test_listmonk_install_sets_the_panels_admin_directly(): void
    {
        $app = (string) $this->config('knadh/listmonk')->appScript();

        $this->assertStringContainsString('ON CONFLICT (username) DO UPDATE', $app);
        $this->assertStringNotContainsString('password2=', $app);
    }
}
