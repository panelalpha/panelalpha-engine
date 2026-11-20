<?php

namespace Tests\Integration\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use Tests\TestCase;

/**
 * wp-config.php names the database credentials. On PHP hosting every project is another account, so the file is
 * never readable by one of them: not after the write, and not between WP-CLI's write and the chmod that follows it.
 */
class WpCliConfigFileTest extends TestCase
{
    private const CONFIG = '/home/alice/example.test/public_html/wp-config.php';

    /** @var list<string> */
    private array $events = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_wp_cli_creates_it_under_an_owner_only_umask_and_it_is_made_600(): void
    {
        $this->cli(existing: false)->createConfigFile(['--dbname=wp', '--dbuser=u']);

        $this->assertSame([
            'wp config create --dbname=wp --dbuser=u --exec=umask(0077); --path=/home/alice/example.test/public_html',
            'chmod 600 '.self::CONFIG,
        ], $this->events);
    }

    public function test_a_file_force_writes_over_is_made_600_before_the_write_too(): void
    {
        $this->cli(existing: true)->createConfigFile(['--dbname=wp', '--force']);

        $this->assertSame([
            'isFile '.self::CONFIG,
            'chmod 600 '.self::CONFIG,
            'wp config create --dbname=wp --force --exec=umask(0077); --path=/home/alice/example.test/public_html',
            'chmod 600 '.self::CONFIG,
        ], $this->events);
    }

    public function test_force_with_nothing_there_yet_only_creates(): void
    {
        $this->cli(existing: false)->createConfigFile(['--dbname=wp', '--force']);

        $this->assertSame([
            'isFile '.self::CONFIG,
            'wp config create --dbname=wp --force --exec=umask(0077); --path=/home/alice/example.test/public_html',
            'chmod 600 '.self::CONFIG,
        ], $this->events);
    }

    public function test_a_failed_create_is_not_followed_by_a_chmod(): void
    {
        try {
            $this->cli(existing: false, exitCode: 1)->createConfigFile(['--dbname=wp']);
            $this->fail('A failed config create should throw');
        } catch (\Exception) {
        }

        $this->assertSame(
            ['wp config create --dbname=wp --exec=umask(0077); --path=/home/alice/example.test/public_html'],
            $this->events
        );
    }

    public function test_a_container_project_keeps_the_mode_its_image_gives_it(): void
    {
        $this->cli(existing: true, details: ['template' => 'dind'])->createConfigFile(['--dbname=wp', '--force']);

        $this->assertSame(['wp config create --dbname=wp --force'], $this->events);
    }

    /** @param array<string, mixed> $details */
    private function cli(bool $existing, int $exitCode = 0, array $details = []): WpCli
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails($details);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args) use ($exitCode): array {
            $this->events[] = 'wp '.implode(' ', $args);

            return ['exit_code' => $exitCode, 'stdout' => '', 'stderr' => $exitCode === 0 ? '' : 'Error: no'];
        });
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('isFile')->andReturnUsing(function (string $path) use ($existing): bool {
            $this->events[] = 'isFile '.$path;

            return $existing;
        });
        $files->shouldReceive('chmod')->andReturnUsing(function (string $path, string $mode): void {
            $this->events[] = "chmod {$mode} {$path}";
        });
        $project->shouldReceive('fileManager')->andReturn($files);
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($domain);

        return WpCli::for($user);
    }
}
