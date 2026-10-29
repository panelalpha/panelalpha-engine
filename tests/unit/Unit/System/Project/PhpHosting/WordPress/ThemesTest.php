<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Themes;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named theme operations: status-equivalent outcomes (resource body vs thrown WordPressException), and the WP-CLI argv the runner received
 * including the resolved --path.
 */
class ThemesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_themes_and_passes_default_path(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"name":"twentytwentyfour","status":"inactive","version":"1.7"}]',
                'stderr' => '',
            ],
        );

        $data = $themes->list();

        $this->assertSame(
            ['theme', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(
            [['name' => 'twentytwentyfour', 'status' => 'inactive', 'version' => '1.7']],
            $data
        );
    }

    public function test_list_with_explicit_path(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner($captured, path: '/home/alice/other.test/public_html');

        $themes->list();

        $this->assertSame(
            ['theme', 'list', '--format=json', '--path=/home/alice/other.test/public_html'],
            $captured
        );
    }

    public function test_install_passes_slug_and_optional_force(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => "Success: Installed 1 of 1 themes.\n", 'stderr' => ''],
        );

        $body = $themes->install('twentytwentyfour', force: true);

        $this->assertSame(
            ['theme', 'install', 'twentytwentyfour', '--force', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame('twentytwentyfour', $body['theme']);
        $this->assertSame('Success: Installed 1 of 1 themes.', $body['message']);
    }

    public function test_install_without_force_omits_force_flag(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner($captured);

        $themes->install('twentytwentythree');

        $this->assertSame(
            ['theme', 'install', 'twentytwentythree', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_activate_update_delete_pass_slug(): void
    {
        foreach (
            [
                'activate' => ['theme', 'activate', 'twentytwentyfour'],
                'update' => ['theme', 'update', 'twentytwentyfour'],
                'delete' => ['theme', 'delete', 'twentytwentyfour'],
            ] as $method => $prefix
        ) {
            $captured = [];
            $themes = $this->themesWithFakeRunner(
                $captured,
                result: ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''],
            );

            $body = $themes->{$method}('twentytwentyfour');

            $this->assertSame(
                [...$prefix, '--path=/home/alice/example.test/public_html'],
                $captured,
                $method
            );
            $this->assertSame(['theme' => 'twentytwentyfour', 'message' => 'ok'], $body, $method);
        }
    }

    public function test_list_passes_fields_and_status(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner($captured);

        $themes->list('name,status,title', 'active');

        $this->assertSame(
            ['theme', 'list', '--format=json', '--fields=name,status,title', '--status=active', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_search_passes_fields_and_per_page(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner($captured);

        $themes->search('blog', 'name,slug,screenshot_url', 50);

        $this->assertSame(
            ['theme', 'search', 'blog', '--format=json', '--fields=name,slug,screenshot_url', '--per-page=50', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_search_queries_wordpress_org_via_wp_cli(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"name":"Astra","slug":"astra"}]',
                'stderr' => '',
            ],
        );

        $data = $themes->search('astra');

        $this->assertSame(
            ['theme', 'search', 'astra', '--format=json', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(
            [['name' => 'Astra', 'slug' => 'astra']],
            $data
        );
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $captured = [];
        $themes = $this->themesWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 1,
                'stdout' => 'Error: Theme not found.',
                'stderr' => '',
            ],
        );

        try {
            $themes->activate('missing-theme');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Theme not found.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_run_or_fail_prefers_stderr_then_stdout(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
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
            WpCli::for($user)->runOrFail(['theme', 'list']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("err-line\nout-line", $e->getMessage());
        }
    }

    /**
     * @param  list<string>  $captured
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function themesWithFakeRunner(
        array &$captured,
        array $result = ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
        ?string $path = null,
    ): Themes {
        $user = Mockery::mock(User::class)->makePartial();
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

        return new Themes(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
