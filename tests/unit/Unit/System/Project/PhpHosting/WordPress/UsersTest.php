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
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_users_and_passes_default_path(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"ID":"1","user_login":"admin","user_email":"admin@example.com","roles":"administrator"}]',
                'stderr' => '',
            ],
        );

        $data = $users->list();

        $this->assertSame(
            ['user', 'list', '--format=json', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(
            [['ID' => '1', 'user_login' => 'admin', 'user_email' => 'admin@example.com', 'roles' => 'administrator']],
            $data
        );
    }

    public function test_list_with_explicit_path(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured, path: '/home/alice/other.test/public_html');

        $users->list();

        $this->assertSame(
            ['user', 'list', '--format=json', '--path=/home/alice/other.test/public_html'],
            $captured
        );
    }

    public function test_create_passes_login_email_password_and_optional_fields(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => "Success: Created user 2.\n", 'stderr' => ''],
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
            $captured
        );
        $this->assertSame('jdoe', $body['user']);
        $this->assertSame('Success: Created user 2.', $body['message']);
    }

    public function test_create_without_optional_fields_omits_them(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured);

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
            $captured
        );
    }

    public function test_update_passes_selected_fields(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => 'Success: Updated user.', 'stderr' => ''],
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
            $captured
        );
        $this->assertSame(['user' => 'jdoe', 'message' => 'Success: Updated user.'], $body);
    }

    public function test_list_passes_role_and_fields(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured);

        $users->list('administrator', 'user_login,user_email,roles,id');

        $this->assertSame(
            [
                'user', 'list', '--format=json', '--role=administrator', '--fields=user_login,user_email,roles,id',
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
    }

    public function test_create_can_send_the_new_user_an_email(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured);

        $users->create(['login' => 'bob', 'email' => 'bob@example.com', 'password' => 'AnotherPass1', 'send_email' => true]);

        $this->assertSame(
            ['user', 'create', 'bob', 'bob@example.com', '--user_pass=AnotherPass1', '--send-email', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_update_sets_the_password_in_the_same_call(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured);

        $users->update('7', ['role' => 'editor', 'password' => 'NewSecret123']);

        $this->assertSame(
            ['user', 'update', '7', '--role=editor', '--user_pass=NewSecret123', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_delete_passes_yes_and_optional_reassign(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => 'Success: Removed user jdoe.', 'stderr' => ''],
        );

        $body = $users->delete('jdoe', reassign: 'admin');

        $this->assertSame(
            ['user', 'delete', 'jdoe', '--yes', '--reassign=admin', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame('jdoe', $body['user']);
    }

    public function test_delete_without_reassign_omits_reassign_flag(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner($captured);

        $users->delete('jdoe');

        $this->assertSame(
            ['user', 'delete', 'jdoe', '--yes', '--path=/home/alice/example.test/public_html'],
            $captured
        );
    }

    public function test_set_password_uses_caller_provided_value(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => 'Success: Updated user.', 'stderr' => ''],
        );

        $body = $users->setPassword('jdoe', 'NewSecret99');

        $this->assertSame(
            ['user', 'update', 'jdoe', '--user_pass=NewSecret99', '--path=/home/alice/example.test/public_html'],
            $captured
        );
        $this->assertSame(['user' => 'jdoe', 'message' => 'Success: Updated user.'], $body);
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $captured = [];
        $users = $this->usersWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 1,
                'stdout' => 'Error: Invalid user ID, email or login: \'missing\'',
                'stderr' => '',
            ],
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
     * @param  list<string>  $captured
     * @param  array{exit_code: int, stdout: string, stderr: string}  $result
     */
    private function usersWithFakeRunner(
        array &$captured,
        array $result = ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
        ?string $path = null,
    ): Users {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
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

        return new Users(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
