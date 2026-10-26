<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Core;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WordPressNotFound;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named core operations: status body (installed, version and
 * the state WP-CLI's is-installed output names), install/update resource
 * bodies, and the WP-CLI argv the runner received including the resolved
 * --path. Status treats is-installed exit 1 as installed=false, not as a
 * failure; any other non-zero exit is a failure. Download never runs over
 * an existing core and never on a container project.
 */
class CoreTest extends TestCase
{
    private const DB_REACHABLE = ['exit_code' => 0, 'stdout' => "1\n1\n", 'stderr' => ''];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_status_returns_installed_and_version_with_default_path(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => "6.7.1\n", 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(
            ['core', 'is-installed', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['core', 'version', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame(['installed' => true, 'version' => '6.7.1', 'state' => Core::STATE_INSTALLED], $body);
    }

    public function test_status_with_explicit_path(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '6.6.2', 'stderr' => ''],
            ],
            path: '/home/alice/other.test/public_html',
        );

        $body = $core->status();

        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][2]);
        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[1][2]);
        $this->assertTrue($body['installed']);
        $this->assertSame('6.6.2', $body['version']);
        $this->assertSame(Core::STATE_INSTALLED, $body['state']);
    }

    public function test_status_not_installed_with_no_output_and_a_reachable_database(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
                self::DB_REACHABLE,
                ['exit_code' => 0, 'stdout' => '6.7.1', 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => '6.7.1', 'state' => Core::STATE_NOT_INSTALLED], $body);
        $this->assertSame(
            ['db', 'query', 'SELECT 1', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
    }

    public function test_status_ignores_php_warnings_wordpress_prints_under_cli(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Warning: Undefined array key \"HTTP_HOST\" in /home/alice/example.test/public_html/wp-includes/functions.php on line 6473\n",
                ],
                self::DB_REACHABLE,
                ['exit_code' => 0, 'stdout' => "7.1.3\n", 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => '7.1.3', 'state' => Core::STATE_NOT_INSTALLED], $body);
    }

    public function test_status_ignores_php_prefixed_notices_and_deprecations(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => "Deprecated: Creation of dynamic property in /x.php on line 3\n",
                    'stderr' => "PHP Notice:  Undefined index: HTTP_HOST in /x.php on line 9\nPHP Strict Standards:  y in /x.php\n",
                ],
                self::DB_REACHABLE,
                ['exit_code' => 0, 'stdout' => '7.1.3', 'stderr' => ''],
            ],
        );

        $this->assertSame(Core::STATE_NOT_INSTALLED, $core->status()['state']);
    }

    public function test_status_with_a_reachable_database_and_other_output_is_unknown(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Warning: Undefined array key \"HTTP_HOST\" in /x.php on line 6473\n"
                        . "Error: It seems, the WordPress core files do not have the proper file permissions.\n",
                ],
                self::DB_REACHABLE,
                ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: could not read version'],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => null, 'state' => Core::STATE_UNKNOWN], $body);
    }

    public function test_status_without_core_files_is_no_core_with_null_version(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => "Error: This does not seem to be a WordPress installation.\n"
                    . "The used path is: /home/alice/example.test/public_html/\n"
                    . "Pass --path=`path/to/wordpress` or run `wp core download`.\n",
            ]],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => null, 'state' => Core::STATE_NO_CORE], $body);
        $this->assertCount(1, $sequence, 'neither the database nor core version is asked without core files');
    }

    public function test_status_without_wp_config_is_no_config_and_keeps_the_version(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Error: 'wp-config.php' not found.\nEither create one manually or use `wp config create`.\n",
                ],
                ['exit_code' => 0, 'stdout' => "7.1.3\n", 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => '7.1.3', 'state' => Core::STATE_NO_CONFIG], $body);
        $this->assertSame(['core', 'version'], array_slice($sequence[1], 0, 2), 'the database is not probed without wp-config.php');
    }

    public function test_status_with_a_translated_database_error_is_db_unreachable(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Error: Błąd łączenia się z bazą danych. Oznacza to, że nazwa użytkownika lub hasło "
                        . "w pliku `wp-config.php` nie jest poprawne, albo nie można nawiązać połączenia z serwerem "
                        . "bazy danych `db-does-not-exist.invalid`.\n",
                ],
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Error: Failed to get current SQL modes. Reason: ERROR 2005 (HY000): "
                        . "Unknown server host 'db-does-not-exist.invalid' (-2)\n",
                ],
                ['exit_code' => 0, 'stdout' => "7.1.3\n", 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => '7.1.3', 'state' => Core::STATE_DB_UNREACHABLE], $body);
    }

    public function test_status_reads_a_mysqlcheck_style_client_error_as_db_unreachable(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: Error establishing a database connection.\n"],
                [
                    'exit_code' => 2,
                    'stdout' => "mysqlcheck: Got error: 2005: Unknown server host 'db-does-not-exist.invalid' (-2) when trying to connect\n",
                    'stderr' => '',
                ],
                ['exit_code' => 0, 'stdout' => '7.1.3', 'stderr' => ''],
            ],
        );

        $this->assertSame(Core::STATE_DB_UNREACHABLE, $core->status()['state']);
    }

    public function test_status_with_access_denied_is_db_unreachable(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: Error establishing a database connection.\n"],
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Error: Failed to get current SQL modes. Reason: ERROR 1045 (28000): "
                        . "Access denied for user 'wp'@'172.18.0.3' (using password: YES)\n",
                ],
                ['exit_code' => 0, 'stdout' => '7.1.3', 'stderr' => ''],
            ],
        );

        $this->assertSame(Core::STATE_DB_UNREACHABLE, $core->status()['state']);
    }

    public function test_status_without_a_mysql_client_is_unknown_not_db_unreachable(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 127, 'stdout' => '', 'stderr' => "/usr/bin/env: 'mysql': No such file or directory\n"],
                ['exit_code' => 0, 'stdout' => '6.8.3', 'stderr' => ''],
            ],
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => '6.8.3', 'state' => Core::STATE_UNKNOWN], $body);
    }

    public function test_status_on_a_container_project_with_no_wordpress_is_no_core(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            notFound: 'No WordPress install was found in this project\'s containers.',
        );

        $body = $core->status();

        $this->assertSame(['installed' => false, 'version' => null, 'state' => Core::STATE_NO_CORE], $body);
        $this->assertCount(1, $sequence, 'core version is not asked without core files');
    }

    public function test_status_of_a_site_that_fails_to_load_is_an_error_not_uninstalled(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: Uncaught Error in broken-plugin.php']],
        );

        try {
            $core->status();
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('PHP Fatal error: Uncaught Error in broken-plugin.php', $e->getMessage());
            $this->assertCount(1, $sequence, 'core version is not asked');
        }
    }

    public function test_install_can_skip_the_admin_email(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner($sequence);

        $core->install([
            'url' => 'https://example.test',
            'title' => 'Example Site',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'SecurePass123',
            'skip_email' => true,
        ]);

        $this->assertSame('--skip-email', $sequence[0][7]);
    }

    public function test_status_installed_but_version_fails_throws(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
                ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: could not read version'],
            ],
        );

        try {
            $core->status();
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: could not read version', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_install_passes_required_fields_and_default_path(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "Success: WordPress installed successfully.\n", 'stderr' => ''],
            ],
        );

        $body = $core->install([
            'url' => 'https://example.test',
            'title' => 'Example Site',
            'admin_user' => 'admin',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'SecurePass123',
        ]);

        $this->assertSame(
            [
                'core',
                'install',
                '--url=https://example.test',
                '--title=Example Site',
                '--admin_user=admin',
                '--admin_email=admin@example.com',
                '--admin_password=SecurePass123',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame(['message' => 'Success: WordPress installed successfully.'], $body);
    }

    public function test_install_with_explicit_path(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner($sequence, path: '/home/alice/other.test/public_html');

        $core->install([
            'url' => 'https://other.test',
            'title' => 'Other',
            'admin_user' => 'bob',
            'admin_email' => 'bob@example.com',
            'admin_password' => 'AnotherPass1',
        ]);

        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][7]);
    }

    public function test_update_passes_target_version_and_path(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: Updated WordPress to 6.7.1.', 'stderr' => ''],
            ],
        );

        $body = $core->update('6.7.1');

        $this->assertSame(
            ['core', 'update', '--version=6.7.1', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['version' => '6.7.1', 'message' => 'Success: Updated WordPress to 6.7.1.'],
            $body
        );
    }

    public function test_check_update_lists_newer_versions(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => "Deprecated: Creation of dynamic property in /x.php on line 3\n"
                    . '[{"version":"6.7.1","update_type":"minor","package_url":"https://downloads.wordpress.org/release/wordpress-6.7.1.zip"}]',
                'stderr' => '',
            ]],
        );

        $updates = $core->checkUpdate();

        $this->assertSame(
            ['core', 'check-update', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame([[
            'version' => '6.7.1',
            'update_type' => 'minor',
            'package_url' => 'https://downloads.wordpress.org/release/wordpress-6.7.1.zip',
        ]], $updates);
    }

    public function test_check_update_when_up_to_date_is_an_empty_list(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner($sequence, results: [
            ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
        ]);

        $this->assertSame([], $core->checkUpdate());
    }

    public function test_install_non_zero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => 'Error: WordPress is already installed.',
                    'stderr' => '',
                ],
            ],
        );

        try {
            $core->install([
                'url' => 'https://example.test',
                'title' => 'Example',
                'admin_user' => 'admin',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'SecurePass123',
            ]);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: WordPress is already installed.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_update_non_zero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => "Error: Can't find version 99.0.0",
                    'stderr' => '',
                ],
            ],
        );

        try {
            $core->update('99.0.0');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("Error: Can't find version 99.0.0", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function test_download_without_options_reports_what_wp_cli_downloaded(): void
    {
        $sequence = [];
        $checked = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => "Downloading WordPress 6.7.1 (en_US)...\nmd5 hash verified: 0123abcd\nSuccess: WordPress downloaded.\n",
                'stderr' => '',
            ]],
            checkedFiles: $checked,
        );

        $body = $core->download();

        $this->assertSame(['core', 'download', '--path=/home/alice/example.test/public_html'], $sequence[0]);
        $this->assertSame(['/home/alice/example.test/public_html/wp-includes/version.php'], $checked);
        $this->assertSame(
            ['version' => '6.7.1', 'locale' => 'en_US', 'path' => '/home/alice/example.test/public_html'],
            $body,
        );
    }

    public function test_download_passes_locale_version_and_explicit_path(): void
    {
        $sequence = [];
        $checked = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => "Downloading WordPress 6.6.2 (de_DE_formal)...\nSuccess: WordPress downloaded.\n",
                'stderr' => '',
            ]],
            path: '/home/alice/other.test/public_html/blog',
            checkedFiles: $checked,
        );

        $body = $core->download('de_DE_formal', '6.6.2');

        $this->assertSame(
            ['core', 'download', '--locale=de_DE_formal', '--version=6.6.2', '--path=/home/alice/other.test/public_html/blog'],
            $sequence[0],
        );
        $this->assertSame(['/home/alice/other.test/public_html/blog/wp-includes/version.php'], $checked);
        $this->assertSame(
            ['version' => '6.6.2', 'locale' => 'de_DE_formal', 'path' => '/home/alice/other.test/public_html/blog'],
            $body,
        );
    }

    public function test_download_falls_back_to_the_request_when_wp_cli_does_not_name_the_version(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: WordPress downloaded.\n", 'stderr' => '']],
        );

        $body = $core->download('pl_PL', 'latest');

        $this->assertSame('latest', $body['version']);
        $this->assertSame('pl_PL', $body['locale']);
    }

    public function test_download_refuses_a_directory_that_already_holds_core(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner($sequence, coreFilesPresent: true);

        try {
            $core->download('pl_PL');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertStringContainsString('/home/alice/example.test/public_html', $e->getMessage());
            $this->assertSame([], $sequence, 'WP-CLI is never run');
        }
    }

    public function test_download_refuses_a_project_running_in_its_own_containers(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'dind']);
        $user->shouldNotReceive('project');
        $user->shouldNotReceive('getMainDomain');

        try {
            (new Core(WpCli::for($user)))->download();
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('PHP hosting', $e->getMessage());
        }
    }

    public function test_download_non_zero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $core = $this->coreWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: Release not found. Double-check locale or version.\n"]],
        );

        try {
            $core->download('xx_XX', '1.0');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Release not found. Double-check locale or version.', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param list<list<string>> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     * @param list<string> $checkedFiles
     */
    private function coreWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
        bool $coreFilesPresent = false,
        array &$checkedFiles = [],
        ?string $notFound = null,
    ): Core {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $files = Mockery::mock(FileManager::class);
        $files->shouldReceive('isFile')->andReturnUsing(function (string $file) use (&$checkedFiles, $coreFilesPresent): bool {
            $checkedFiles[] = $file;

            return $coreFilesPresent;
        });
        $project->shouldReceive('fileManager')->andReturn($files);
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results, $notFound): array {
                $sequence[] = $args;
                if ($notFound !== null) {
                    throw new WordPressNotFound($notFound);
                }
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Core(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
