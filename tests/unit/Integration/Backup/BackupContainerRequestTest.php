<?php

namespace Tests\Integration\Backup;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

use App\Http\Requests\BackupContainerStoreRequest;
use App\Http\Requests\BackupContainerUpdateRequest;
use App\Models\BackupContainer;
use Tests\TestCase;

class BackupContainerRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('backup_containers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver');
            $table->string('location', 1024);
            $table->longText('credentials')->nullable();
            $table->timestamps();
        });
    }

    /**
     * engine#809: a local store writes from inside the core container, so a
     * path outside the bind-mounted host paths is lost on the next recreate.
     */
    public function test_a_local_location_outside_a_persisted_path_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        try {
            $request->assertLocalLocationIsSafe('/var/backups');
            $this->fail('a container-only location was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'must be under a path this engine persists',
                $e->errors()['location'][0]
            );
            $this->assertStringContainsString('/opt/panelalpha', $e->errors()['location'][0]);
        }
    }

    /** The backup staging scratch is mounted, but a store there is lost: cleanup and tmpfiles. */
    public function test_the_backup_staging_scratch_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/var/tmp/panelalpha-backup', '/var/tmp/panelalpha-backup/backups'] as $scratch) {
            try {
                $request->assertLocalLocationIsSafe($scratch);
                $this->fail("{$scratch} was accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('must be under a path this engine persists', $e->errors()['location'][0]);
            }
        }
    }

    public function test_a_local_location_under_a_persisted_path_is_kept(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/home/backups', '/opt/panelalpha/backups', '/var/www/html/storage/backups'] as $safe) {
            $request->assertLocalLocationIsSafe($safe);
        }
        $this->assertTrue(true);
    }

    /** The root broker writes nothing below the engine's directory but the engine's own files, so no backup lands there. */
    public function test_a_local_location_in_the_engine_directory_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/opt/panelalpha/shared-hosting', '/opt/panelalpha/shared-hosting/backups/', '/opt/panelalpha/shared-hosting/users/alice/backups'] as $inside) {
            try {
                $request->assertLocalLocationIsSafe($inside);
                $this->fail("{$inside} was accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString("inside the engine's directory (/opt/panelalpha/shared-hosting)", $e->errors()['location'][0]);
            }
        }
    }

    /** The broker drops empty path parts, so a doubled slash or a `.` segment names the same directory. */
    public function test_a_respelt_engine_directory_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/opt/panelalpha//shared-hosting/x', '/opt/panelalpha/./shared-hosting/x'] as $respelt) {
            try {
                $request->assertLocalLocationIsSafe($respelt);
                $this->fail("{$respelt} was accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString("inside the engine's directory", $e->errors()['location'][0]);
            }
        }
    }

    public function test_a_respelt_home_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/home/', '//home', '/home/.'] as $respelt) {
            try {
                $request->assertLocalLocationIsSafe($respelt);
                $this->fail("{$respelt} was accepted");
            } catch (ValidationException $e) {
                $this->assertSame('Local backup location must not be empty, filesystem root, or /home.', $e->errors()['location'][0]);
            }
        }
    }

    public function test_a_local_location_beside_the_engine_directory_is_kept(): void
    {
        $this->expectNotToPerformAssertions();

        (new BackupContainerStoreRequest())->assertLocalLocationIsSafe('/opt/panelalpha/shared-hosting-backups');
    }

    public function test_local_driver_drops_credentials(): void
    {
        $request = new BackupContainerStoreRequest();

        $this->assertNull($request->normalizeCredentials('local', [
            'access_key_id' => 'key',
        ]));
    }

    public function test_non_local_driver_requires_credentials_array(): void
    {
        $request = new BackupContainerStoreRequest();

        try {
            $request->normalizeCredentials('s3', null);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['credentials' => ['Credentials are required for non-local backup storage.']],
                $e->errors(),
            );
        }
    }

    public function test_s3_requires_access_keys(): void
    {
        $request = new BackupContainerStoreRequest();

        try {
            $request->normalizeCredentials('s3', ['region' => 'eu-central-1']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'credentials.access_key_id' => ['The access_key_id field is required.'],
                'credentials.secret_access_key' => ['The secret_access_key field is required.'],
            ], $e->errors());
        }
    }

    public function test_s3_keeps_known_keys_and_drops_empty_values(): void
    {
        $request = new BackupContainerStoreRequest();

        $this->assertSame([
            'access_key_id' => 'id',
            'secret_access_key' => 'secret',
            'region' => 'eu-central-1',
        ], $request->normalizeCredentials('s3', [
            'access_key_id' => 'id',
            'secret_access_key' => 'secret',
            'region' => 'eu-central-1',
            'endpoint' => '',
            'prefix' => null,
            'unknown' => 'ignored',
        ]));
    }

    public function test_ftp_requires_host_and_username(): void
    {
        $request = new BackupContainerStoreRequest();

        try {
            $request->normalizeCredentials('ftp', ['password' => 'secret']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame([
                'credentials.host' => ['The host field is required.'],
                'credentials.username' => ['The username field is required.'],
            ], $e->errors());
        }
    }

    public function test_sftp_keeps_optional_key_material(): void
    {
        $request = new BackupContainerStoreRequest();

        $this->assertSame([
            'host' => 'backup.example.test',
            'username' => 'alice',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'passphrase' => 'phrase',
            'port' => '22',
        ], $request->normalizeCredentials('sftp', [
            'host' => 'backup.example.test',
            'username' => 'alice',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'passphrase' => 'phrase',
            'port' => '22',
        ]));
    }

    public function test_switching_to_non_local_without_credentials_fails_when_none_are_stored(): void
    {
        $container = $this->makeContainer();
        $request = $this->updateRequest($container->id, ['driver' => 's3']);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            ['credentials' => ['Credentials are required for non-local backup storage.']],
            $validator->errors()->toArray(),
        );
    }

    public function test_switching_to_non_local_is_allowed_when_credentials_already_exist(): void
    {
        $container = $this->makeContainer(['host' => 'backup.example.test', 'username' => 'alice']);
        $container->driver = 'ftp';
        $container->save();

        $request = $this->updateRequest($container->id, ['driver' => 'sftp']);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }

    public function test_a_persistent_root_itself_is_refused(): void
    {
        $request = new BackupContainerStoreRequest();

        foreach (['/opt/panelalpha', '/var/www/html/storage'] as $root) {
            try {
                $request->assertLocalLocationIsSafe($root);
                $this->fail("{$root} was accepted");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('not one of those paths itself', $e->errors()['location'][0]);
            }
        }
    }

    public function test_switching_to_local_without_a_location_checks_the_kept_location(): void
    {
        $container = $this->makeContainer(['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        $container->driver = 's3';
        $container->location = 'my-bucket';
        $container->save();

        $request = $this->updateRequest($container->id, ['driver' => 'local']);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('must be under a path this engine persists', (string) $validator->errors()->first('location'));
    }

    public function test_switching_to_local_keeps_a_safe_location(): void
    {
        $container = $this->makeContainer(['access_key_id' => 'key', 'secret_access_key' => 'secret']);
        $container->driver = 's3';
        $container->location = '/opt/panelalpha/backups';
        $container->save();

        $request = $this->updateRequest($container->id, ['driver' => 'local']);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateRequest(int $id, array $payload): BackupContainerUpdateRequest
    {
        $base = Request::create('/backup-containers/' . $id, 'PUT', $payload);
        $request = BackupContainerUpdateRequest::createFrom($base);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));

        $route = new Route(['PUT'], 'backup-containers/{id}', []);
        $route->bind($request);
        $route->setParameter('id', (string) $id);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function makeContainer(array $credentials = []): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'test-' . uniqid('', true);
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = $credentials !== [] ? $credentials : null;
        $container->save();

        return $container;
    }
}
