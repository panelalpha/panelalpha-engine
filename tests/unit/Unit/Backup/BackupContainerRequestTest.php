<?php

namespace Tests\Unit\Backup;

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
