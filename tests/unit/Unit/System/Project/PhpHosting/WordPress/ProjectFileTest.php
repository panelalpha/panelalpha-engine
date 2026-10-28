<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\Database;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A file WP-CLI reads or writes for a caller stays inside the project: the
 * home on PHP hosting, the checkout's mount or the install directory in the
 * project's own containers. `..` is resolved before the check, and a refusal
 * is a 422 on the field, raised before WP-CLI runs.
 */
class ProjectFileTest extends TestCase
{
    /** @var list<list<string>> */
    private array $runs = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function filesInsideTheHome(): array
    {
        return [
            'absolute' => ['/home/alice/backup.sql', '/home/alice/backup.sql'],
            'relative to the working directory, the home' => ['backups/site.sql', '/home/alice/backups/site.sql'],
            'dots inside the home' => ['/home/alice/a/./b/../site.sql', '/home/alice/a/site.sql'],
            'the home mounted at /var/www' => ['/var/www/example.test/backup.sql', '/home/alice/example.test/backup.sql'],
        ];
    }

    #[DataProvider('filesInsideTheHome')]
    public function test_on_php_hosting_a_file_inside_the_home_is_given_to_wp_cli_absolute(string $file, string $given): void
    {
        $this->assertSame($given, $this->phpHostingCli()->projectFile('file', $file));
    }

    /** @return array<string, array{string}> */
    public static function filesOutsideTheHome(): array
    {
        return [
            'a host file' => ['/etc/passwd'],
            'climbing out of the document root' => ['/var/www/html/../../../etc/passwd'],
            'climbing out of the working directory' => ['../../etc/passwd'],
            'climbing out of the home' => ['/home/alice/../bob/wp-config.php'],
            'a home whose name starts the same' => ['/home/alice2/backup.sql'],
            'the home itself' => ['/home/alice'],
            'the container\'s temporary directory' => ['/tmp/dump.sql'],
        ];
    }

    #[DataProvider('filesOutsideTheHome')]
    public function test_on_php_hosting_a_file_outside_the_home_is_a_problem_on_the_field(string $file): void
    {
        $problem = $this->refusal(fn () => $this->phpHostingCli()->projectFile('file', $file));

        $this->assertSame(['file'], array_keys($problem->errors()));
        $this->assertSame('file_outside_project', $problem->problems[0]['code']);
        $this->assertSame(
            "The file must be inside the project home /home/alice, absolute or relative to it; {$file} is not.",
            $problem->problems[0]['message'],
        );
    }

    /** @return array<string, array{string, string}> */
    public static function filesInsideTheContainerProject(): array
    {
        return [
            'below the checkout\'s mount' => ['/var/www/html/backup.sql', '/var/www/html/backup.sql'],
            'dots below the mount' => ['/var/www/html/blog/../backup.sql', '/var/www/html/backup.sql'],
            'relative to the install directory' => ['backup.sql', 'backup.sql'],
            'relative, with dots that stay inside' => ['dumps/./old/../site.sql', 'dumps/site.sql'],
        ];
    }

    #[DataProvider('filesInsideTheContainerProject')]
    public function test_in_containers_a_file_below_the_mount_or_the_install_is_accepted(string $file, string $given): void
    {
        $this->assertSame($given, $this->containerCli()->projectFile('file', $file));
    }

    /** @return array<string, array{string}> */
    public static function filesOutsideTheContainerProject(): array
    {
        return [
            'a container file' => ['/etc/passwd'],
            'climbing out of the mount' => ['/var/www/html/../../../etc/passwd'],
            'climbing out of the install directory' => ['../wp-config.php'],
            'the mount itself' => ['/var/www/html'],
            'the install directory itself' => ['.'],
            'another directory of the container' => ['/app/backup.sql'],
        ];
    }

    #[DataProvider('filesOutsideTheContainerProject')]
    public function test_in_containers_any_other_file_is_a_problem_on_the_field(string $file): void
    {
        $problem = $this->refusal(fn () => $this->containerCli()->projectFile('file', $file));

        $this->assertSame(['file'], array_keys($problem->errors()));
        $this->assertSame('file_outside_project', $problem->problems[0]['code']);
        $this->assertStringContainsString("{$file} is neither", $problem->problems[0]['message']);
    }

    public function test_import_and_export_refuse_a_file_outside_the_project_before_wp_cli_runs(): void
    {
        foreach ([$this->phpHostingCli(), $this->containerCli()] as $cli) {
            $database = new Database($cli);

            $this->refusal(fn () => $database->import('/var/www/html/../../../etc/passwd'));
            $this->refusal(fn () => $database->export('/etc/cron.d/backup'));
        }

        $this->assertSame([], $this->runs);
    }

    public function test_import_hands_wp_cli_the_file_it_checked(): void
    {
        $database = new Database($this->phpHostingCli());

        $body = $database->import('backups/../site.sql');

        $this->assertSame(['db', 'import', '/home/alice/site.sql'], array_slice($this->runs[0], 0, 3));
        $this->assertSame('/home/alice/site.sql', $body['file']);
    }

    private function refusal(callable $call): ProblemException
    {
        try {
            $call();
        } catch (ProblemException $e) {
            $this->assertSame(422, $e->status);

            return $e;
        }
        $this->fail('Expected a ProblemException');
    }

    private function phpHostingCli(): WpCli
    {
        return WpCli::for($this->user([]), '/home/alice/example.test/public_html');
    }

    private function containerCli(): WpCli
    {
        return WpCli::for($this->user(['template' => 'dind']));
    }

    /** @param array<string, mixed> $details */
    private function user(array $details): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails($details);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')->andReturnUsing(fn (string $path): string => $path);
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args): array {
            $this->runs[] = $args;

            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $user->shouldReceive('project')->andReturn($project);

        return $user;
    }
}
