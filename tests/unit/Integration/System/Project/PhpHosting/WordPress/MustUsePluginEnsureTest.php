<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress;

use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use Mockery;
use Tests\TestCase;

/**
 * The engine's must-use plugin copied into a PHP hosting install, as the account, before an SSO login.
 */
class MustUsePluginEnsureTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    /** @var array<string, string> */
    private array $written = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_plugin_lands_in_an_existing_mu_plugins_directory(): void
    {
        $plugin = new MustUsePlugin($this->user(muPluginsExists: true));

        $plugin->ensure('/home/alice/shop.example.test/public_html/');

        $this->assertSame([], $this->made);
        $this->assertSame(
            ['/home/alice/shop.example.test/public_html/wp-content/mu-plugins/panelalpha-app.php' => file_get_contents($plugin->sourcePath())],
            $this->written,
        );
    }

    public function test_a_missing_mu_plugins_directory_is_made_with_its_parents(): void
    {
        $plugin = new MustUsePlugin($this->user(muPluginsExists: false));

        $plugin->ensure('/home/alice/shop.example.test/public_html');

        $this->assertSame(['/home/alice/shop.example.test/public_html/wp-content/mu-plugins'], $this->made);
        $this->assertArrayHasKey('/home/alice/shop.example.test/public_html/wp-content/mu-plugins/panelalpha-app.php', $this->written);
    }

    public function test_an_engine_without_the_plugin_source_fails_before_writing(): void
    {
        $this->app->setBasePath(sys_get_temp_dir().'/pa-no-mu-plugin-source');
        $plugin = new MustUsePlugin($this->user(muPluginsExists: true));

        try {
            $plugin->ensure('/home/alice/shop.example.test/public_html');
            $this->fail('a missing plugin source must fail');
        } catch (Exception $e) {
            $this->assertSame('Engine must-use plugin source is missing.', $e->getMessage());
            $this->assertSame(500, $e->httpStatus);
        }

        $this->assertSame([], $this->written);
    }

    private function user(bool $muPluginsExists): User
    {
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('exists')->andReturn($muPluginsExists);
        $files->shouldReceive('mkdir')->andReturnUsing(function (string $path, bool $parents = false): void {
            $this->assertTrue($parents);
            $this->made[] = $path;
        });
        $files->shouldReceive('putContents')->andReturnUsing(function (string $path, string $contents): void {
            $this->written[$path] = $contents;
        });
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('fileManager')->andReturn($files);
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
