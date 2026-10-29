<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\ApplicationPasswords;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for application-password operations: resource body vs thrown
 * WordPressException, and the WP-CLI argv the runner received including
 * the resolved --path. Create asserts the one-time secret in the body.
 */
class ApplicationPasswordsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_returns_decoded_passwords_and_passes_default_path(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => '[{"uuid":"6633824d-c1d7-4f79-9dd5-4586f734d69e","name":"myapp","created":1638395611}]',
                'stderr' => '',
            ],
        );

        $data = $passwords->list('admin');

        $this->assertSame(
            [
                'user', 'application-password', 'list', 'admin', '--format=json',
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
        $this->assertSame(
            [['uuid' => '6633824d-c1d7-4f79-9dd5-4586f734d69e', 'name' => 'myapp', 'created' => 1638395611]],
            $data
        );
    }

    public function test_list_with_explicit_path(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner($captured, path: '/home/alice/other.test/public_html');

        $passwords->list('admin');

        $this->assertSame(
            [
                'user', 'application-password', 'list', 'admin', '--format=json',
                '--path=/home/alice/other.test/public_html',
            ],
            $captured
        );
    }

    public function test_create_returns_one_time_secret_from_porcelain_and_passes_path(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => "ZG1bxdxdzjTwhsY8vK8l1C65\n",
                'stderr' => '',
            ],
        );

        $body = $passwords->create('admin', 'myapp');

        $this->assertSame(
            [
                'user', 'application-password', 'create', 'admin', 'myapp', '--porcelain',
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
        $this->assertSame('admin', $body['user']);
        $this->assertSame('myapp', $body['name']);
        $this->assertSame('ZG1bxdxdzjTwhsY8vK8l1C65', $body['password']);
    }

    public function test_create_with_app_id_passes_flag(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: ['exit_code' => 0, 'stdout' => 'SecretOnlyOnce', 'stderr' => ''],
        );

        $body = $passwords->create('admin', 'ci', appId: '42');

        $this->assertSame(
            [
                'user', 'application-password', 'create', 'admin', 'ci', '--porcelain',
                '--app-id=42',
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
        $this->assertSame('SecretOnlyOnce', $body['password']);
    }

    public function test_create_parses_password_line_when_chrome_present(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => "Success: Created application password.\nPassword: ZG1bxdxdzjTwhsY8vK8l1C65\n",
                'stderr' => '',
            ],
        );

        // Force non-porcelain path by calling parse via create with porcelain
        // still sent; the parser still accepts a Password: line if present.
        $body = $passwords->create('admin', 'myapp');

        $this->assertSame('ZG1bxdxdzjTwhsY8vK8l1C65', $body['password']);
    }

    public function test_delete_passes_uuid_and_path(): void
    {
        $captured = [];
        $uuid = '6633824d-c1d7-4f79-9dd5-4586f734d69e';
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 0,
                'stdout' => 'Success: Deleted 1 of 1 application password.',
                'stderr' => '',
            ],
        );

        $body = $passwords->delete('admin', $uuid);

        $this->assertSame(
            [
                'user', 'application-password', 'delete', 'admin', $uuid,
                '--path=/home/alice/example.test/public_html',
            ],
            $captured
        );
        $this->assertSame(
            [
                'user' => 'admin',
                'uuid' => $uuid,
                'message' => 'Success: Deleted 1 of 1 application password.',
            ],
            $body
        );
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $captured = [];
        $passwords = $this->passwordsWithFakeRunner(
            $captured,
            result: [
                'exit_code' => 1,
                'stdout' => "Error: Invalid user ID, email or login: 'missing'",
                'stderr' => '',
            ],
        );

        try {
            $passwords->list('missing');
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
    private function passwordsWithFakeRunner(
        array &$captured,
        array $result = ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
        ?string $path = null,
    ): ApplicationPasswords {
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

        return new ApplicationPasswords(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
