<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Plugins;
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

    public function test_activate_deactivate_update_delete_pass_slug(): void
    {
        foreach (
            [
                'activate' => ['plugin', 'activate', 'hello-dolly'],
                'deactivate' => ['plugin', 'deactivate', 'hello-dolly'],
                'update' => ['plugin', 'update', 'hello-dolly'],
                'delete' => ['plugin', 'delete', 'hello-dolly'],
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
     * @param list<string> $captured
     * @param array{exit_code: int, stdout: string, stderr: string} $result
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
