<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Migration\WpCliWordpressInstaller;
use Mockery;
use Tests\TestCase;

/**
 * The wp-config.php the import writes holds the database password, so it is
 * readable by the project's own user only, as a database export is.
 */
class WpCliWordpressInstallerWriteConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_created_config_is_readable_by_the_project_user_only(): void
    {
        $captured = [];
        $modes = [];
        $user = $this->userWithFakeProject($captured, $modes);

        (new WpCliWordpressInstaller)->writeConfig(
            $user,
            '/home/alice/example.test/public_html',
            'alice_wp',
            'alice_wp',
            'secret',
        );

        $this->assertSame(
            [
                'config', 'create',
                '--dbname=alice_wp', '--dbuser=alice_wp', '--dbpass=secret', '--dbhost=database-users.shared-hosting.palocal',
                '--force', '--exec=umask(0077);',
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
        $this->assertSame(['/home/alice/example.test/public_html/wp-config.php' => '600'], $modes);
    }

    /**
     * @param  list<string>  $captured
     * @param  array<string, string>  $modes  path => mode set on it
     */
    private function userWithFakeProject(array &$captured, array &$modes): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$captured): array {
                $captured = $args;

                return ['exit_code' => 0, 'stdout' => "Success: Generated 'wp-config.php' file.\n", 'stderr' => ''];
            });
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('chmod')->andReturnUsing(function (string $path, string $mode) use (&$modes): void {
            $modes[$path] = $mode;
        });
        $files->shouldReceive('isFile')->andReturn(false);
        $project->shouldReceive('fileManager')->andReturn($files);
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
