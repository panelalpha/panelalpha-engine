<?php

namespace Tests\Unit\Console\Wp;

use App\Console\Commands\Domains\WpCli as DomainWpCli;
use App\Console\Commands\Wp\WpCommand;
use App\Http\Controllers\User\Wp\WpCliController;
use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Illuminate\Support\Facades\Route as RouteFacade;
use Mockery;
use Tests\TestCase;

/**
 * Artisan adapters reach the same WordPress command module with the project
 * username and the resolved path. They do not repeat the operation matrix.
 */
class WpCommandsReachModuleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_wp_command_signature_takes_username_and_optional_path(): void
    {
        $command = new WpCommand;
        $this->assertSame('wordpress:command', $command->getName());
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasArgument('username'));
        $this->assertTrue($definition->hasArgument('arg'));
        $this->assertTrue($definition->hasOption('path'));
    }

    public function test_domain_wp_cli_help_names_wp_command_as_replacement(): void
    {
        $command = new DomainWpCli;
        $this->assertSame('domain:wp-cli', $command->getName());
        $this->assertStringContainsString('wordpress:command', $command->getDescription());
    }

    public function test_wp_command_and_domain_wp_cli_resolve_username_and_path_for_the_module(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $user->shouldReceive('project')->andReturn($project);

        $main = Mockery::mock(Domain::class);
        $main->shouldReceive('getDocumentRoot')->andReturn('/alice.test/public_html');
        $user->shouldReceive('getMainDomain')->andReturn($main);

        $module = WpCli::for($user);
        $this->assertSame('/home/alice/alice.test/public_html', $module->resolvePath());
        $this->assertSame('/home/alice/custom', WpCli::for($user, '/home/alice/custom')->resolvePath());

        $addon = Mockery::mock(Domain::class);
        $addon->shouldReceive('getDocumentRoot')->andReturn('/addon.test/public_html');
        $this->assertSame('/home/alice/addon.test/public_html', $module->resolveDomainPath($addon));

        $merged = $module->mergePath(['plugin', 'list']);
        $this->assertSame(['plugin', 'list', '--path=/home/alice/alice.test/public_html'], $merged);
    }

    public function test_a_path_that_leaves_the_home_is_refused_before_wp_cli_runs(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldNotReceive('runWpCli');
        $user->shouldReceive('project')->andReturn($project);

        try {
            WpCli::for($user, '../bob/public_html')->run(['core', 'version']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame(
                'The path ../bob/public_html does not name a directory inside the project home.',
                $e->getMessage(),
            );
        }
    }

    public function test_canonical_and_deprecated_http_routes_share_the_controller_action(): void
    {
        $uris = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if ($route->getActionName() === WpCliController::class.'@run') {
                $uris[] = $route->uri();
            }
        }
        sort($uris);

        $this->assertSame(
            [
                'api/projects/{username}/wp-cli/command',
                'api/projects/{username}/wp/command',
                'api/users/{username}/wp-cli/command',
                'api/users/{username}/wp/command',
            ],
            $uris
        );
    }
}
