<?php

namespace Tests\Integration\Models;

use App\Models\Backup;
use App\Models\BackupContainer;
use App\Models\BackupItem;
use App\Models\BackupSchedule;
use App\Models\DeployHook;
use App\Models\FtpAccount;
use App\Models\HookDelivery;
use App\Models\IpAssigned;
use App\Models\IpSubnet;
use App\Models\MysqlSsoToken;
use App\Models\SftpAccount;
use App\Models\Snapshot;
use App\Models\Task;
use App\Models\TaskLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** Each belongs-to relation reads the related model by the column the schema gives it. */
class ModelRelationKeysTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /** @param class-string<Model> $model @param class-string<Model> $related */
    #[DataProvider('relations')]
    public function test_the_relation_points_at_its_model_by_its_column(string $model, string $relation, string $related, string $foreignKey, string $ownerKey): void
    {
        $belongsTo = (new $model())->{$relation}();

        $this->assertInstanceOf(BelongsTo::class, $belongsTo);
        $this->assertInstanceOf($related, $belongsTo->getRelated());
        $this->assertSame($foreignKey, $belongsTo->getForeignKeyName());
        $this->assertSame($ownerKey, $belongsTo->getOwnerKeyName());
    }

    /** @return iterable<string, array{0: class-string<Model>, 1: string, 2: class-string<Model>, 3: string, 4: string}> */
    public static function relations(): iterable
    {
        yield 'backup item' => [BackupItem::class, 'backup', Backup::class, 'backup_id', 'id'];
        yield 'task log' => [TaskLog::class, 'task', Task::class, 'task_id', 'id'];
        yield 'ftp account' => [FtpAccount::class, 'userModel', User::class, 'user_id', 'id'];
        yield 'assigned ip subnet' => [IpAssigned::class, 'ipSubnet', IpSubnet::class, 'ip_subnet_id', 'id'];
        yield 'sftp account' => [SftpAccount::class, 'user', User::class, 'user_id', 'id'];
        yield 'mysql sso token' => [MysqlSsoToken::class, 'user', User::class, 'user_id', 'id'];
        yield 'backup' => [Backup::class, 'user', User::class, 'user_id', 'id'];
        yield 'pending hook delivery' => [DeployHook::class, 'pendingDelivery', HookDelivery::class, 'pending_delivery_id', 'id'];
        yield 'last scheduled backup' => [BackupSchedule::class, 'lastBackup', Backup::class, 'last_backup_id', 'id'];
        yield 'snapshot' => [Snapshot::class, 'user', User::class, 'user_id', 'id'];
    }

    public function test_a_backup_item_loads_its_backup_and_a_task_log_its_task(): void
    {
        $user = $this->makeUser('relowner');
        $task = Task::start(jobType: 'App\\Jobs\\DeployProject', queue: 'default', username: 'relowner');
        $log = TaskLog::create(['task_id' => $task->id, 'log' => 'line']);
        $container = BackupContainer::create(['name' => 'disk', 'driver' => 'local', 'location' => '/backups']);
        $backup = Backup::create(['user_id' => $user->id, 'username' => 'relowner', 'container_id' => $container->id]);
        $item = BackupItem::create(['backup_id' => $backup->id, 'remote_path' => 'files.tar.gz', 'size_bytes' => 1, 'details' => []]);

        $this->assertTrue($item->backup->is($backup));
        $this->assertTrue($backup->user->is($user));
        $this->assertTrue($log->task->is($task));
    }
}
