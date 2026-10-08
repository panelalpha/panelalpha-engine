<?php

namespace Tests\Unit\Backup;

use App\Jobs\CreateBackup;
use App\Models\Backup as BackupRecord;
use App\Models\BackupContainer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreateBackupJobTest extends TestCase
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

        $path = base_path('database/migrations/2026_09_04_000000_create_tasks_tables.php');
        require_once $path;
        (new \CreateTasksTables())->up();
    }

    public function test_job_has_single_try_and_two_hour_timeout(): void
    {
        $job = new CreateBackup(42);

        $this->assertSame(1, $job->tries);
        $this->assertSame(7200, $job->timeout);
        $this->assertSame(42, $job->backupId);
    }

    public function test_dispatch_chain_attaches_task(): void
    {
        Queue::fake();

        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.example.test';
        $user->email = 'alice@example.test';
        $user->name = 'alice';
        $user->status = 'active';
        $user->details = ['template' => 'dind'];
        $user->save();

        $container = new BackupContainer();
        $container->name = 'local-main';
        $container->driver = 'local';
        $container->location = '/tmp/backups';
        $container->credentials = null;
        $container->save();

        $backup = BackupRecord::prepare($user, $container, 'api');
        $task = Task::start(
            jobType: CreateBackup::class,
            queue: 'default',
            username: $backup->username,
            details: ['backup_id' => $backup->id, 'action' => 'backup'],
        );
        CreateBackup::dispatch($backup->id)->attachTask($task);

        $this->assertSame(Task::STATUS_QUEUED, $task->refresh()->status);
        $this->assertSame(CreateBackup::class, $task->job_type);
        $this->assertSame('alice', $task->username);

        Queue::assertPushed(CreateBackup::class, function (CreateBackup $pushed) use ($backup, $task) {
            return $pushed->backupId === $backup->id && $pushed->taskId === $task->id;
        });
    }
}
