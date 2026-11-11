<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Users;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for named user operations: status-equivalent outcomes (resource
 * body vs thrown WordPressException), and the WP-CLI argv the runner received
 * including the resolved --path.
 */
class UsersTest extends TestCase
{
    private const FOUND_USER = ['exit_code' => 0, 'stdout' => "7\n", 'stderr' => ''];

    private const MISSING_USER = [
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => "Error: Invalid user ID, email or login: 'missing'\n",
    ];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_users_and_passes_default_path(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: [
                'exit_code' => 0,
                'stdout' => '[{"ID":"1","user_login":"admin","user_email":"admin@example.com","roles":"administrator"}]',
                'stderr' => '',
            ],
        );

        $data = $users->list();

        $this->assertSame(
            ['user', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            [['ID' => '1', 'user_login' => 'admin', 'user_email' => 'admin@example.com', 'roles' => 'administrator']],
            $data
        );
    }

    public function test_list_with_explicit_path(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence, path: '/home/alice/other.test/public_html');

        $users->list();

        $this->assertSame(
            ['user', 'list', '--format=json', '--path=/home/alice/other.test/public_html'],
            $sequence[0]
        );
    }

    public function test_create_passes_login_email_password_and_optional_fields(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: ['exit_code' => 0, 'stdout' => "Success: Created user 2.\n", 'stderr' => ''],
        );

        $body = $users->create([
            'login' => 'jdoe',
            'email' => 'jdoe@example.com',
            'password' => 'SecurePass123',
            'role' => 'editor',
            'display_name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $this->assertSame(
            [
                'user', 'create', 'jdoe', 'jdoe@example.com',
                '--user_pass=SecurePass123',
                '--role=editor',
                '--display_name=Jane Doe',
                '--first_name=Jane',
                '--last_name=Doe',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
        $this->assertSame('jdoe', $body['user']);
        $this->assertSame('Success: Created user 2.', $body['message']);
    }

    public function test_create_without_optional_fields_omits_them(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        $users->create([
            'login' => 'bob',
            'email' => 'bob@example.com',
            'password' => 'AnotherPass1',
        ]);

        $this->assertSame(
            [
                'user', 'create', 'bob', 'bob@example.com',
                '--user_pass=AnotherPass1',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
    }

    public function test_create_reports_the_login_wordpress_stores(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        $body = $users->create(['login' => ' bob ', 'email' => 'bob@example.com', 'password' => 'AnotherPass1']);

        $this->assertSame('bob', $sequence[0][2]);
        $this->assertSame('bob', $body['user']);
    }

    public function test_create_refuses_a_login_the_api_refuses(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        try {
            $users->create(['login' => 'a;b', 'email' => 'ab@example.com', 'password' => 'AnotherPass1']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('a;b', $e->getMessage());
        }

        $this->assertSame([], $sequence);
    }

    public function test_a_password_shorter_than_the_api_rule_is_refused_before_wp_cli_runs(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        foreach (
            [
                static fn (Users $users) => $users->create(['login' => 'bob', 'email' => 'bob@example.com', 'password' => 'short']),
                static fn (Users $users) => $users->setPassword('bob', 'short'),
                static fn (Users $users) => $users->update('bob', ['password' => 'short']),
            ] as $call
        ) {
            try {
                $call($users);
                $this->fail('Expected WordPressException');
            } catch (WordPressException $e) {
                $this->assertSame('The password must be at least 8 characters.', $e->getMessage());
                $this->assertSame(422, $e->httpStatus);
            }
        }

        $this->assertSame([], $sequence);
    }

    public function test_update_passes_selected_fields(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: [self::FOUND_USER, ['exit_code' => 0, 'stdout' => 'Success: Updated user.', 'stderr' => '']],
        );

        $body = $users->update('jdoe', [
            'email' => 'new@example.com',
            'role' => 'author',
            'display_name' => 'Jane',
        ]);

        $this->assertSame(
            [
                'user', 'update', 'jdoe',
                '--user_email=new@example.com',
                '--role=author',
                '--display_name=Jane',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[1]
        );
        $this->assertSame(['user' => 'jdoe', 'message' => 'Success: Updated user.'], $body);
    }

    public function test_list_passes_role_and_fields(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        $users->list('administrator', 'user_login,user_email,roles,id');

        $this->assertSame(
            [
                'user', 'list', '--format=json', '--role=administrator', '--fields=user_login,user_email,roles,id',
                '--path=/home/alice/example.test/public_html',
            ],
            $sequence[0]
        );
    }

    public function test_create_can_send_the_new_user_an_email(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence);

        $users->create(['login' => 'bob', 'email' => 'bob@example.com', 'password' => 'AnotherPass1', 'send_email' => true]);

        $this->assertSame(
            ['user', 'create', 'bob', 'bob@example.com', '--user_pass=AnotherPass1', '--send-email', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_update_sets_the_password_in_the_same_call(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence, results: [self::FOUND_USER]);

        $users->update('7', ['role' => 'editor', 'password' => 'NewSecret123']);

        $this->assertSame(
            ['user', 'update', '7', '--role=editor', '--user_pass=NewSecret123', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
    }

    public function test_delete_passes_yes_and_optional_reassign(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: [self::FOUND_USER, ['exit_code' => 0, 'stdout' => 'Success: Removed user jdoe.', 'stderr' => '']],
        );

        $body = $users->delete('jdoe', reassign: 'admin');

        $this->assertSame(
            ['user', 'delete', 'jdoe', '--yes', '--reassign=admin', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame('jdoe', $body['user']);
    }

    public function test_delete_without_reassign_omits_reassign_flag(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner($sequence, results: [self::FOUND_USER]);

        $users->delete('jdoe');

        $this->assertSame(
            ['user', 'delete', 'jdoe', '--yes', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
    }

    public function test_set_password_uses_caller_provided_value(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: [self::FOUND_USER, ['exit_code' => 0, 'stdout' => 'Success: Updated user.', 'stderr' => '']],
        );

        $body = $users->setPassword('jdoe', 'NewSecret99');

        $this->assertSame(
            ['user', 'update', 'jdoe', '--user_pass=NewSecret99', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame(['user' => 'jdoe', 'message' => 'Success: Updated user.'], $body);
    }

    public function test_a_user_wordpress_does_not_have_is_a_404(): void
    {
        foreach (
            [
                static fn (Users $users) => $users->update('missing', ['role' => 'editor']),
                static fn (Users $users) => $users->delete('missing'),
                static fn (Users $users) => $users->setPassword('missing', 'NewSecret99'),
            ] as $call
        ) {
            $sequence = [];
            $users = $this->usersWithFakeRunner($sequence, results: [self::MISSING_USER]);

            try {
                $call($users);
                $this->fail('Expected WordPressException');
            } catch (WordPressException $e) {
                $this->assertSame(404, $e->httpStatus);
                $this->assertStringContainsString("Invalid user ID, email or login: 'missing'", $e->getMessage());
            }

            $this->assertSame(
                ['user', 'get', 'missing', '--field=ID', '--path=/home/alice/example.test/public_html'],
                $sequence[0]
            );
        }
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $users = $this->usersWithFakeRunner(
            $sequence,
            results: [self::FOUND_USER, [
                'exit_code' => 1,
                'stdout' => 'Error: Invalid user ID, email or login: \'missing\'',
                'stderr' => '',
            ]],
        );

        try {
            $users->delete('missing');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("Error: Invalid user ID, email or login: 'missing'", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param  list<list<string>>  $sequence
     */
    private function usersWithFakeRunner(
        array &$sequence,
        array $results = ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
        ?string $path = null,
    ): Users {
        $results = array_is_list($results) ? $results : [$results];
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

        return new Users(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
