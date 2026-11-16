<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Plugins;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named plugin operations: status-equivalent outcomes (resource
 * body vs thrown WordPressException), and the WP-CLI argv the runner received
 * including the resolved --path.
 */
class PluginsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_plugins_and_passes_default_path(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"name":"hello-dolly","status":"inactive","version":"1.7"}]',
                'stderr' => '',
            ],
        );

        $data = $plugins->list();

        $this->assertSame(
            ['plugin', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(
            [['name' => 'hello-dolly', 'status' => 'inactive', 'version' => '1.7']],
            $data
        );
    }

    public function test_list_with_explicit_path(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner($captured, path: '/home/alice/other.test/public_html');

        $plugins->list();

        $this->assertSame(
            ['plugin', 'list', '--format=json', '--path=/home/alice/other.test/public_html'],
            $captured
        );
    }

    public function test_install_passes_slug_and_optional_force(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => "Success: Installed 1 of 1 plugins.\n", 'stderr' => ''],
        );

        $body = $plugins->install('hello-dolly', force: true);

        $this->assertSame(
            ['plugin', 'install', 'hello-dolly', '--force', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame('hello-dolly', $body['plugin']);
        $this->assertSame('Success: Installed 1 of 1 plugins.', $body['message']);
    }

    public function test_install_without_force_omits_force_flag(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner($captured);

        $plugins->install('akismet');

        $this->assertSame(
            ['plugin', 'install', 'akismet', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_activate_deactivate_update_pass_slug(): void
    {
        foreach (
            [
                'activate' => ['plugin', 'activate', 'hello-dolly'],
                'deactivate' => ['plugin', 'deactivate', 'hello-dolly'],
                'update' => ['plugin', 'update', 'hello-dolly'],
            ] as $method => $prefix
        ) {
            $captured = [];
            $plugins = $this->pluginsWithFakeRunner(
                $captured,
                result: ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''],
            );

            $body = $plugins->{$method}('hello-dolly');

            $this->assertSame(
                [...$prefix, '--path=/home/alice/example.test/public_html'],
                $captured,
                $method
            );
            $this->assertSame(['plugin' => 'hello-dolly', 'message' => 'ok'], $body, $method);
        }
    }

    /**
     * `plugin delete` removes the files and nothing else: an active page-cache plugin
     * keeps its dropin and its `WP_CACHE`, and its uninstall never runs. WordPress's
     * own Delete deactivates the plugin and runs its uninstall before the files go,
     * which is what `plugin uninstall --deactivate` does in one call.
     */
    public function test_delete_deactivates_and_uninstalls_before_the_files_go(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => "Plugin 'wp-super-cache' deactivated.\nUninstalled and deleted 'wp-super-cache' plugin.\nSuccess: Uninstalled 1 of 1 plugins.\n",
                'stderr' => '',
            ],
        );

        $body = $plugins->delete('wp-super-cache');

        $this->assertSame(
            ['plugin', 'uninstall', 'wp-super-cache', '--deactivate', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame('wp-super-cache', $body['plugin']);
        $this->assertSame(
            "Plugin 'wp-super-cache' deactivated.\nUninstalled and deleted 'wp-super-cache' plugin.\nSuccess: Uninstalled 1 of 1 plugins.",
            $body['message']
        );
    }

    /**
     * `plugin uninstall` answers exit 1 for a slug it cannot find, where `plugin delete`
     * answered "already deleted" and exit 0; a DELETE stays idempotent.
     */
    public function test_delete_of_a_slug_that_is_not_installed_answers_that_nothing_was_deleted(): void
    {
        $calls = [];
        $plugins = $this->pluginsWithRunnerQueue($calls, [
            ['exit_code' => 1, 'stdout' => "Warning: The 'gone' plugin could not be found.\n", 'stderr' => "Error: No plugins uninstalled.\n"],
            ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
        ]);

        $body = $plugins->delete('gone');

        $this->assertSame(
            ['plugin', 'is-installed', 'gone', '--skip-plugins', '--skip-themes', '--path=/home/alice/example.test/public_html'],
            $calls[1]
        );
        $this->assertSame(
            ['plugin' => 'gone', 'message' => "Plugin 'gone' is not installed, so nothing was deleted."],
            $body
        );
    }

    /**
     * A plugin that fatals when WordPress loads it fails its own uninstall, and the API must still be able to remove
     * the plugin that breaks the site: its files go without loading any plugin, and the answer says the uninstall
     * did not run.
     */
    public function test_delete_of_a_plugin_whose_uninstall_fails_deletes_its_files_without_loading_plugins(): void
    {
        $calls = [];
        $plugins = $this->pluginsWithRunnerQueue($calls, [
            ['exit_code' => 255, 'stdout' => '', 'stderr' => "PHP Fatal error:  Uncaught Error: Call to undefined function broken()\n"],
            ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
            ['exit_code' => 0, 'stdout' => "Deleted 'broken' plugin.\nSuccess: Deleted 1 of 1 plugins.\n", 'stderr' => ''],
        ]);

        $body = $plugins->delete('broken');

        $this->assertSame(
            ['plugin', 'delete', 'broken', '--skip-plugins', '--skip-themes', '--path=/home/alice/example.test/public_html'],
            $calls[2]
        );
        $this->assertSame('broken', $body['plugin']);
        $this->assertSame(
            "Plugin 'broken' was deleted, but its uninstall did not run: PHP Fatal error:  Uncaught Error: Call to undefined function broken()",
            $body['message']
        );
    }

    public function test_delete_keeps_the_wp_cli_reason_when_the_files_cannot_be_deleted_either(): void
    {
        $calls = [];
        $plugins = $this->pluginsWithRunnerQueue($calls, [
            ['exit_code' => 1, 'stdout' => "Plugin 'kept' deactivated.\n", 'stderr' => "Error: There has been a critical error on this website.\n"],
            ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
            ['exit_code' => 1, 'stdout' => '', 'stderr' => "Warning: The 'kept' plugin could not be deleted.\nError: No plugins deleted.\n"],
        ]);

        try {
            $plugins->delete('kept');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(
                "Warning: The 'kept' plugin could not be deleted.\nError: No plugins deleted.",
                $e->getMessage()
            );
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_list_passes_fields_and_status(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner($captured);

        $plugins->list('name,status,title', 'active,active-network,inactive');

        $this->assertSame(
            [
                'plugin', 'list', '--format=json', '--fields=name,status,title',
                '--status=active,active-network,inactive', '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
    }

    public function test_search_passes_fields_and_per_page(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner($captured);

        $plugins->search('seo', 'name,slug,icons', 50);

        $this->assertSame(
            ['plugin', 'search', 'seo', '--format=json', '--fields=name,slug,icons', '--per-page=50', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_search_queries_wordpress_org_via_wp_cli(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"name":"Contact Form 7","slug":"contact-form-7"}]',
                'stderr' => '',
            ],
        );

        $data = $plugins->search('contact');

        $this->assertSame(
            ['plugin', 'search', 'contact', '--format=json', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(
            [['name' => 'Contact Form 7', 'slug' => 'contact-form-7']],
            $data
        );
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $captured = [];
        $plugins = $this->pluginsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 1,
                'stdout' => 'Error: Plugin not found.',
                'stderr' => '',
            ],
        );

        try {
            $plugins->activate('missing-plugin');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Plugin not found.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_run_or_fail_prefers_stderr_then_stdout(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')->once()->andReturn([
            'exit_code' => 1,
            'stdout' => 'out-line',
            'stderr' => 'err-line',
        ]);
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        try {
            WpCli::for($user)->runOrFail(['plugin', 'list']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("err-line\nout-line", $e->getMessage());
        }
    }

    /**
     * @param  list<list<string>>  $calls
     * @param  list<array{exit_code: int, stdout: string, stderr: string}>  $results
     */
    private function pluginsWithRunnerQueue(array &$calls, array $results): Plugins
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$calls, &$results): array {
                $calls[] = $args;

                return array_shift($results);
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Plugins(WpCli::for($user));
    }

    /**
     * @param  list<string>  $captured
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function pluginsWithFakeRunner(
        array &$captured,
        array $result = ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
        ?string $path = null,
    ): Plugins {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')
            ->once()
            ->andReturnUsing(function (array $args) use (&$captured, $result): array {
                $captured = $args;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Plugins(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
