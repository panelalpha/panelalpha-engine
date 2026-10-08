<?php

namespace Tests\Unit\Backup;

use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupListTest extends TestCase
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

        Schema::create('backup_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->string('remote_path', 1024);
            $table->unsignedBigInteger('size_bytes');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['backup_id', 'remote_path']);
        });
    }

    public function test_backups_lists_only_this_project_newest_first(): void
    {
        $alice = $this->makeUser('alice');
        $bob = $this->makeUser('bob');
        $container = $this->makeContainer();

        $older = $this->seedBackup($alice, $container, '2026-01-01 10:00:00');
        $newer = $this->seedBackup($alice, $container, '2026-01-02 10:00:00');
        $this->seedBackup($bob, $container, '2026-01-03 10:00:00');

        $listed = BackupRecord::query()
            ->where('user_id', $alice->id)
            ->with(['container', 'items'])
            ->orderByDesc('created_at')
            ->get();

        $this->assertSame(2, $listed->count());
        $this->assertSame([$newer->id, $older->id], $listed->pluck('id')->all());
        $this->assertTrue($listed->every(fn (BackupRecord $b): bool => $b->user_id === $alice->id));
        $this->assertTrue($listed->every(fn (BackupRecord $b): bool => $b->relationLoaded('container')));
        $this->assertTrue($listed->every(fn (BackupRecord $b): bool => $b->relationLoaded('items')));
    }

    private function seedBackup(User $user, BackupContainer $container, string $createdAt): BackupRecord
    {
        $backup = new BackupRecord();
        $backup->user_id = $user->id;
        $backup->username = $user->username;
        $backup->container_id = $container->id;
        $backup->async_status = ['backup' => 'completed'];
        $backup->created_at = $createdAt;
        $backup->updated_at = $createdAt;
        $backup->save();

        return $backup;
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
