<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dumps of a project's databases taken while it kept running, kept on the engine host as a restore point.
 *
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property string $kind
 * @property ?string $deploy_id
 * @property string $status
 * @property ?string $restore_status
 * @property ?array $databases
 * @property int $size_bytes
 * @property ?string $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?User $user
 */
class Snapshot extends Model
{
    public const string KIND_MANUAL = 'manual';

    public const string KIND_PRE_DEPLOY = 'pre_deploy';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    private const array KINDS = [self::KIND_MANUAL, self::KIND_PRE_DEPLOY];

    protected $fillable = [
        'user_id',
        'username',
        'kind',
        'deploy_id',
        'status',
        'restore_status',
        'databases',
        'size_bytes',
        'error',
    ];

    protected $casts = [
        'databases' => 'array',
        'size_bytes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function begin(User $user, string $kind, ?string $deployId = null): self
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown snapshot kind '{$kind}'");
        }

        $snapshot = new self;
        $snapshot->user_id = $user->id;
        $snapshot->username = $user->username;
        $snapshot->kind = $kind;
        $snapshot->deploy_id = $deployId;
        $snapshot->status = self::STATUS_RUNNING;
        $snapshot->databases = [];
        $snapshot->size_bytes = 0;
        $snapshot->save();

        return $snapshot;
    }

    /**
     * @param  list<array{name: string, file: string, size_bytes: int, sha256: string}>  $databases
     */
    public function complete(array $databases): void
    {
        $this->databases = $databases;
        $this->size_bytes = array_sum(array_column($databases, 'size_bytes'));
        $this->status = self::STATUS_COMPLETED;
        $this->error = null;
        $this->save();
    }

    public function fail(string $error): void
    {
        $this->databases = [];
        $this->size_bytes = 0;
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->save();
    }

    public function saveRestoreStatus(string $status, ?string $error = null): void
    {
        $this->restore_status = $status;
        $this->error = $error;
        $this->save();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * @return list<array{name: string, file: string, size_bytes: int, sha256: string}>
     */
    public function entries(): array
    {
        return array_values($this->databases ?? []);
    }
}
