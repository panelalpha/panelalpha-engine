<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Config;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named wp-config constant operations: list/get/set/delete
 * resource bodies, failure as Exception, and WP-CLI argv including --path.
 */
class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_constants_only_with_default_path(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 0,
                'stdout' => json_encode([
                    ['key' => 'DB_NAME', 'value' => 'wp', 'type' => 'constant'],
                    ['key' => 'table_prefix', 'value' => 'wp_', 'type' => 'variable'],
                    ['key' => 'WP_DEBUG', 'value' => '1', 'type' => 'constant'],
                ]),
                'stderr' => '',
            ]],
        );

        $body = $config->list();

        $this->assertSame(
            ['config', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            [
                ['key' => 'DB_NAME', 'value' => 'wp', 'type' => 'constant'],
                ['key' => 'WP_DEBUG', 'value' => '1', 'type' => 'constant'],
            ],
            $body
        );
    }

    public function test_list_all_keeps_variables(): void
    {
        $rows = [
            ['key' => 'DB_NAME', 'value' => 'wp', 'type' => 'constant'],
            ['key' => 'table_prefix', 'value' => 'wp_', 'type' => 'variable'],
        ];
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence, results: [['exit_code' => 0, 'stdout' => json_encode($rows), 'stderr' => '']]);

        $this->assertSame($rows, $config->list('all'));
    }

    public function test_get_a_variable(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence, results: [['exit_code' => 0, 'stdout' => "wp_\n", 'stderr' => '']]);

        $body = $config->get('table_prefix', 'variable');

        $this->assertSame(
            ['config', 'get', 'table_prefix', '--type=variable', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['constant' => 'table_prefix', 'value' => 'wp_'], $body);
    }

    public function test_get_as_json_decodes_the_value(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => '{"host":"127.0.0.1","port":6379}', 'stderr' => '']],
        );

        $body = $config->get('WP_REDIS_CONFIG', 'all', json: true);

        $this->assertSame(
            ['config', 'get', 'WP_REDIS_CONFIG', '--type=all', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['host' => '127.0.0.1', 'port' => 6379], $body['value']);
    }

    public function test_set_an_empty_variable(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        $config->set('custom_var', '', type: 'variable');

        $this->assertSame(
            ['config', 'set', 'custom_var', '', '--type=variable', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_create_passes_the_given_credentials_and_flags(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence, results: [
            ['exit_code' => 0, 'stdout' => "Success: Generated 'wp-config.php' file.\n", 'stderr' => ''],
        ]);

        $body = $config->create([
            'dbname' => 'alice_wp',
            'dbuser' => 'alice_u',
            'dbpass' => ' p@ss ',
            'dbhost' => 'localhost:3306',
            'dbprefix' => 'wp_',
            'dbcharset' => 'utf8mb4',
            'locale' => 'pl_PL',
            'force' => true,
            'skip_check' => false,
        ]);

        $this->assertSame([
            'config', 'create',
            '--dbname=alice_wp', '--dbuser=alice_u', '--dbpass= p@ss ', '--dbhost=localhost:3306',
            '--dbprefix=wp_', '--dbcharset=utf8mb4', '--locale=pl_PL', '--force',
            '--path=/home/alice/example.test/public_html',
        ], $sequence[0]);
        $this->assertSame(['message' => "Success: Generated 'wp-config.php' file."], $body);
    }

    public function test_create_omits_what_is_not_given(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        $config->create(['dbname' => 'db', 'dbuser' => 'u', 'dbpass' => '', 'dbhost' => 'db.test', 'skip_check' => true]);

        $this->assertSame([
            'config', 'create', '--dbname=db', '--dbuser=u', '--dbpass=', '--dbhost=db.test', '--skip-check',
            '--path=/home/alice/example.test/public_html',
        ], $sequence[0]);
    }

    public function test_delete_with_type_all(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        $config->delete('DISABLE_WP_CRON', 'all');

        $this->assertSame(
            ['config', 'delete', 'DISABLE_WP_CRON', '--type=all', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_an_unknown_type_is_refused_before_running_wp_cli(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        try {
            $config->get('X', 'everything');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Invalid wp-config type: everything.', $e->getMessage());
            $this->assertSame([], $sequence);
        }
    }

    public function test_get_returns_constant_value_with_explicit_path(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "true\n", 'stderr' => '']],
            path: '/home/alice/other.test/public_html',
        );

        $body = $config->get('WP_DEBUG');

        $this->assertSame(
            ['config', 'get', 'WP_DEBUG', '--type=constant', '--path=/home/alice/other.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['constant' => 'WP_DEBUG', 'value' => 'true'], $body);
    }

    public function test_set_passes_raw_flag_and_returns_body(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Updated the constant 'WP_DEBUG' in the 'wp-config.php' file with the raw value 'true'.\n", 'stderr' => '']],
        );

        $body = $config->set('WP_DEBUG', 'true', raw: true);

        $this->assertSame(
            ['config', 'set', 'WP_DEBUG', 'true', '--type=constant', '--raw', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame('WP_DEBUG', $body['constant']);
        $this->assertSame('true', $body['value']);
        $this->assertStringContainsString('Success:', $body['message']);
    }

    public function test_set_without_raw_omits_raw_flag(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        $config->set('CUSTOM_KEY', 'hello');

        $this->assertSame(
            ['config', 'set', 'CUSTOM_KEY', 'hello', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_a_value_starting_with_a_dash_goes_as_the_raw_php_that_writes_the_same_line(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner($sequence);

        $quoted = $config->set('WP_MEMORY_LIMIT', '--exec=x');
        $raw = $config->set('WP_POST_REVISIONS', '-1', raw: true);

        $this->assertSame(
            ['config', 'set', 'WP_MEMORY_LIMIT', "'--exec=x'", '--type=constant', '--raw', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['config', 'set', 'WP_POST_REVISIONS', '(-1)', '--type=constant', '--raw', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame('--exec=x', $quoted['value']);
        $this->assertSame('-1', $raw['value']);
    }

    public function test_delete_passes_argv_and_returns_body(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [['exit_code' => 0, 'stdout' => "Success: Deleted the constant 'WP_DEBUG' from the 'wp-config.php' file.\n", 'stderr' => '']],
        );

        $body = $config->delete('WP_DEBUG');

        $this->assertSame(
            ['config', 'delete', 'WP_DEBUG', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(['constant' => 'WP_DEBUG', 'message' => "Success: Deleted the constant 'WP_DEBUG' from the 'wp-config.php' file."], $body);
    }

    public function test_nonzero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $config = $this->configWithFakeRunner(
            $sequence,
            results: [[
                'exit_code' => 1,
                'stdout' => "Error: The constant or variable 'MISSING' is not defined in the 'wp-config.php' file.",
                'stderr' => '',
            ]],
        );

        try {
            $config->get('MISSING');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertStringContainsString('MISSING', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param  list<list<string>>  $sequence
     * @param  list<array{exit_code: int, stdout: string, stderr: string}>  $results
     */
    private function configWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): Config {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new Config(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
