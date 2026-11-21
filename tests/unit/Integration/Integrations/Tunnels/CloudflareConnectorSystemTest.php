<?php

namespace Tests\Integration\Integrations\Tunnels;

use App\Integrations\Tunnels\Cloudflare;
use App\System;
use App\System\EnginePaths;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/**
 * Account creation renders the connector through a project lookup that names no System. It lands in the project dir
 * of the System the container hands out, so an account the dind-test harness renders on its bound TestSystem stays in
 * the test dir instead of reaching for the engine's /opt/panelalpha/shared-hosting (which, without an installed
 * engine, failed the harness's first step).
 */
class CloudflareConnectorSystemTest extends TestCase
{
    use InMemoryDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        LocalRoot::answerLocally();
        $this->root = sys_get_temp_dir().'/pa-connector-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/users/alice', 0777, true);
        $this->app->instance(System::class, $this->system());
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_a_supervisord_account_gets_the_program_in_the_bound_systems_project_dir(): void
    {
        $user = $this->makeUser('alice', ['template' => 'dind']);

        Cloudflare::renderConnector($user, false);

        $conf = $this->root.'/users/alice/supervisord.conf.d/cloudflared.conf';
        $this->assertFileExists($conf);
        $this->assertStringContainsString("autostart=false\n", (string) file_get_contents($conf));
        $this->assertDirectoryDoesNotExist(EnginePaths::ENGINE_DIR.'/users/alice');
    }

    public function test_an_s6_account_gets_the_service_in_the_bound_systems_project_dir(): void
    {
        mkdir($this->root.'/users/alice/services');
        $user = $this->makeUser('alice', ['template' => 'dind']);

        Cloudflare::renderConnector($user, true, 'tunnel-token');

        $service = $this->root.'/users/alice/services/cloudflared';
        $this->assertSame("tunnel-token\n", file_get_contents($service.'/env/TUNNEL_TOKEN'));
        $this->assertFileDoesNotExist($service.'/down');
        $this->assertDirectoryDoesNotExist($this->root.'/users/alice/supervisord.conf.d');
    }

    private function system(): System
    {
        return new class($this->root) extends System
        {
            public function __construct(private readonly string $root) {}

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function homesDirPath(): string
            {
                return $this->root.'/home';
            }
        };
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $full = "{$path}/{$item}";
            is_dir($full) && ! is_link($full) ? $this->removeTree($full) : unlink($full);
        }
        rmdir($path);
    }
}
