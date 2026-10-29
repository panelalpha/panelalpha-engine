<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\Config;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * `config create` without dbhost: WP-CLI's localhost is the account's PHP
 * container on PHP hosting and the WordPress container on a container
 * project, and MySQL is in neither.
 */
class ConfigDatabaseHostTest extends TestCase
{
    /** @var list<list<string>> */
    private array $runs = [];

    /** @var list<array{exit_code: int, stdout: string, stderr: string}> */
    private array $results = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_php_hosting_defaults_to_the_projects_mysql_server(): void
    {
        config(['env.USERS_DB_HOST' => 'mysql-users.test']);

        $this->config([])->create(['dbname' => 'alice_wp', 'dbuser' => 'alice_u', 'skip_check' => true]);

        $this->assertCount(1, $this->runs);
        $this->assertContains('--dbhost=mysql-users.test', $this->runs[0]);
    }

    public function test_php_hosting_without_a_configured_host_uses_the_engines_own_name_for_it(): void
    {
        config(['env.USERS_DB_HOST' => null]);

        $this->config([])->create(['dbname' => 'alice_wp', 'dbuser' => 'alice_u']);

        $this->assertContains('--dbhost=database-users.shared-hosting.palocal', $this->runs[0]);
    }

    public function test_a_given_host_is_kept_and_nothing_is_looked_up(): void
    {
        config(['env.USERS_DB_HOST' => 'mysql-users.test']);

        $this->config([])->create(['dbname' => 'alice_wp', 'dbuser' => 'alice_u', 'dbhost' => TestNetwork::LOOPBACK.':3307']);
        $this->config(['template' => 'dind'])->create(['dbname' => 'wordpress', 'dbuser' => 'wordpress', 'dbhost' => 'mariadb']);

        $this->assertCount(2, $this->runs);
        $this->assertContains('--dbhost='.TestNetwork::LOOPBACK.':3307', $this->runs[0]);
        $this->assertContains('--dbhost=mariadb', $this->runs[1]);
    }

    public function test_a_container_project_keeps_the_database_service_its_wp_config_names(): void
    {
        $this->results = [['exit_code' => 0, 'stdout' => "db\n", 'stderr' => '']];

        $this->config(['template' => 'dind'])->create(['dbname' => 'wordpress', 'dbuser' => 'wordpress', 'force' => true, 'skip_check' => true]);

        $this->assertSame(['config', 'get', 'DB_HOST'], $this->runs[0]);
        $this->assertSame(
            ['config', 'create', '--dbname=wordpress', '--dbuser=wordpress', '--dbhost=db', '--force', '--skip-check'],
            $this->runs[1],
        );
    }

    public function test_a_container_project_without_wp_config_leaves_the_host_to_wp_cli(): void
    {
        $this->results = [['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: 'wp-config.php' not found.\n"]];

        $this->config(['template' => 'dind'])->create(['dbname' => 'wordpress', 'dbuser' => 'wordpress', 'skip_check' => true]);

        $this->assertSame(['config', 'create', '--dbname=wordpress', '--dbuser=wordpress', '--skip-check'], $this->runs[1]);
    }

    /** @param array<string, mixed> $details */
    private function config(array $details): Config
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails($details);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')->andReturnUsing(function (array $args): array {
            $this->runs[] = $args;

            return array_shift($this->results) ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        });
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($domain);

        return new Config(WpCli::for($user));
    }
}
