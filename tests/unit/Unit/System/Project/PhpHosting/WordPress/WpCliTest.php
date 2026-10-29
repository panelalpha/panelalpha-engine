<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for the WordPress WpCli runner: path default,
 * explicit path, args --path= winning, and the runner body shape. The WP-CLI
 * runner is a fake Project so these do not need a live docker host.
 */
class WpCliTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_omitting_path_uses_main_domain_document_root(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner($captured, mainDocRoot: '/example.test/public_html', home: '/home/alice');

        $result = $cli->run(['plugin', 'list']);

        $this->assertSame(['plugin', 'list', '--path=/home/alice/example.test/public_html'], $captured);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('ok', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function test_skip_plugins_adds_both_skip_flags_before_the_path(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner($captured, mainDocRoot: '/example.test/public_html', home: '/home/alice', skipPlugins: true);

        $cli->run(['plugin', 'list']);

        $this->assertSame(
            ['plugin', 'list', '--skip-plugins', '--skip-themes', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_skip_plugins_does_not_repeat_a_flag_already_in_the_args(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner($captured, mainDocRoot: '/example.test/public_html', home: '/home/alice', skipPlugins: true);

        $cli->run(['--skip-plugins', 'option', 'list']);

        $this->assertSame(
            ['--skip-plugins', 'option', 'list', '--skip-themes', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_explicit_path_is_passed_as_wp_cli_path(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner(
            $captured,
            mainDocRoot: '/example.test/public_html',
            home: '/home/alice',
            path: '/home/alice/other.test/public_html',
        );

        $cli->run(['core', 'version']);

        $this->assertSame(
            ['core', 'version', '--path=/home/alice/other.test/public_html'],
            $captured
        );
    }

    public function test_a_relative_path_is_read_inside_the_home(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner($captured, mainDocRoot: '/example.test/public_html', home: '/home/alice', path: 'blog/');

        $cli->run(['core', 'version']);

        $this->assertSame(['core', 'version', '--path=/home/alice/blog'], $captured);
    }

    public function test_an_absolute_path_outside_the_home_is_read_inside_it_as_the_file_manager_does(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner($captured, mainDocRoot: '/example.test/public_html', home: '/home/alice', path: '/tmp/wp');

        $cli->run(['core', 'version']);

        $this->assertSame(['core', 'version', '--path=/home/alice/tmp/wp'], $captured);
    }

    public function test_path_inside_args_wins_over_bound_path(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner(
            $captured,
            mainDocRoot: '/example.test/public_html',
            home: '/home/alice',
            path: '/home/alice/from-sibling',
        );

        $cli->run(
            ['option', 'get', 'blogname', '--path=/home/alice/from-args'],
        );

        $this->assertSame(
            ['option', 'get', 'blogname', '--path=/home/alice/from-args'],
            $captured
        );
    }

    public function test_php_hosting_refusal_surfaces_from_the_runner(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')
            ->once()
            ->andThrow(new \RuntimeException('WP-CLI is only supported for PHP hosting projects.'));
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WP-CLI is only supported for PHP hosting projects.');

        WpCli::for($user)->run(['--version']);
    }

    public function test_a_container_project_gets_no_host_path_and_needs_no_main_domain(): void
    {
        $captured = [];
        $user = $this->containerUser($captured);

        WpCli::for($user)->run(['plugin', 'list']);

        $this->assertSame(['plugin', 'list'], $captured, 'the runner finds the install in the containers');
    }

    public function test_a_container_projects_path_is_the_containers_own(): void
    {
        $captured = [];
        $user = $this->containerUser($captured);

        WpCli::for($user, '/var/www/html')->run(['core', 'version']);

        $this->assertSame(['core', 'version', '--path=/var/www/html'], $captured);
    }

    public function test_a_service_reaches_the_container_runner(): void
    {
        $captured = [];
        $user = $this->containerUser($captured, $service);

        WpCli::for($user, 'blog', service: 'wordpress')->run(['core', 'version']);

        $this->assertSame(['core', 'version', '--path=blog'], $captured);
        $this->assertSame('wordpress', $service);
    }

    public function test_a_service_on_php_hosting_is_refused(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldNotReceive('project');

        $this->expectException(WordPressException::class);
        $this->expectExceptionMessage("service applies only to WordPress in the project's own containers.");

        WpCli::for($user, service: 'wordpress')->run(['core', 'version']);
    }

    public function test_raw_command_body_is_exit_code_stdout_stderr(): void
    {
        $captured = [];
        $cli = $this->cliWithFakeRunner(
            $captured,
            mainDocRoot: '/example.test/public_html',
            home: '/home/alice',
            result: ['exit_code' => 1, 'stdout' => 'out', 'stderr' => 'err'],
        );

        $result = $cli->run(['cache', 'flush']);

        $this->assertSame(['exit_code', 'stdout', 'stderr'], array_keys($result));
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('out', $result['stdout']);
        $this->assertSame('err', $result['stderr']);
    }

    public function test_resolve_domain_path_uses_that_domain_document_root(): void
    {
        $user = Mockery::mock(User::class);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $user->shouldReceive('project')->andReturn($project);

        $domain = $this->domain('/addon.test/public_html');
        $path = WpCli::for($user)->resolveDomainPath($domain);

        $this->assertSame('/home/alice/addon.test/public_html', $path);
    }

    /**
     * @param  list<string>  $captured
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function cliWithFakeRunner(
        array &$captured,
        string $mainDocRoot,
        string $home,
        array $result = ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''],
        ?string $path = null,
        bool $skipPlugins = false,
    ): WpCli {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn($home);
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')
            ->once()
            ->andReturnUsing(function (array $args) use (&$captured, $result): array {
                $captured = $args;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain($mainDocRoot));

        return WpCli::for($user, $path, skipPlugins: $skipPlugins);
    }

    /** @param list<string> $captured */
    private function containerUser(array &$captured, ?string &$service = null): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'dind']);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runWpCli')
            ->once()
            ->andReturnUsing(function (array $args, ?string $svc = null) use (&$captured, &$service): array {
                $captured = $args;
                $service = $svc;

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldNotReceive('getMainDomain');

        return $user;
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
