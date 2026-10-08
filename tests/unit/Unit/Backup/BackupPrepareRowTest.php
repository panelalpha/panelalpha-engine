<?php

namespace Tests\Unit\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupPrepareRowTest extends TestCase
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_containers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver');
            $table->string('location', 1024);
            $table->longText('credentials')->nullable();
            $table->timestamps();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username')->index();
            $table->foreignId('container_id')->constrained('backup_containers')->restrictOnDelete();
            $table->json('async_status')->nullable();
            $table->json('restore_details')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function test_prepare_row_inserts_pending_backup_with_source(): void
    {
        $user = $this->makeUser('alice');
        $container = $this->makeContainer();

        $record = BackupRecord::prepare($user, $container, 'api');

        $this->assertInstanceOf(BackupRecord::class, $record);
        $this->assertSame('pending', $record->backupStatus());
        $this->assertSame('api', $record->async_status['source'] ?? null);
        $this->assertNull($record->error);
        $this->assertSame(1, BackupRecord::query()->count());
    }

    private function makeUser(string $username): User
    {
        $user = new User();
        $user->username = $username;
        $user->domain = $username . '.example.test';
        $user->email = $username . '@example.test';
        $user->name = $username;
        $user->status = 'active';
        $user->details = ['template' => 'dind'];
        $user->save();

        return $user;
    }

    private function makeContainer(): BackupContainer
    {
        $container = new BackupContainer();
        $container->name = 'test-' . uniqid('', true);
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = null;
        $container->save();

        return $container;
    }
}
