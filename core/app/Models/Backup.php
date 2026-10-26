<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $username
 * @property int $container_id
 * @property string $kind
 * @property ?string $note
 * @property ?array $contents
 * @property ?array $async_status
 * @property ?array $restore_details
 * @property ?string $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?User $user
 * @property ?BackupContainer $container
 * @property Collection<int, BackupItem> $items
 *
 * @method static ?Backup find(int $id)
 */
class Backup extends Model
{
    protected $fillable = [
        'user_id',
        'username',
        'container_id',
        'kind',
        'note',
        'contents',
        'async_status',
        'restore_details',
        'error',
    ];

    protected $casts = [
        'contents' => 'array',
        'async_status' => 'array',
        'restore_details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(BackupContainer::class, 'container_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BackupItem::class);
    }

    /**
     * @param  array{files?: bool, databases?: bool}  $contents
     */
    public static function prepare(
        User $user,
        BackupContainer $container,
        string $source = 'api',
        array $contents = [],
        ?string $note = null,
        string $kind = 'manual',
    ): self {
        $backup = new self;
        $backup->user_id = $user->id;
        $backup->username = $user->username;
        $backup->container_id = $container->id;
        $backup->kind = $kind;
        $backup->note = $note;
        $backup->contents = self::normalizeContents($contents);
        $backup->async_status = [
            'backup' => 'pending',
            'source' => $source,
        ];
        $backup->error = null;
        $backup->save();

        return $backup;
    }

    public function includeFiles(): bool
    {
        return ($this->contents['files'] ?? true) === true;
    }

    public function includeDatabases(): bool
    {
        return ($this->contents['databases'] ?? true) === true;
    }

    public function isScheduled(): bool
    {
        return $this->kind === 'scheduled';
    }

    /**
     * @param  array{files?: bool, databases?: bool}  $contents
     * @return array{files: bool, databases: bool}
     */
    private static function normalizeContents(array $contents): array
    {
        return [
            'files' => ($contents['files'] ?? true) === true,
            'databases' => ($contents['databases'] ?? true) === true,
        ];
    }

    public function backupStatus(): ?string
    {
        return $this->asyncStatusValue('backup');
    }

    public function setBackupStatus(?string $status): void
    {
        $this->setAsyncStatusValue('backup', $status);
    }

    public function restoreStatus(): ?string
    {
        return $this->asyncStatusValue('restore');
    }

    public function setRestoreStatus(?string $status): void
    {
        $this->setAsyncStatusValue('restore', $status);
    }

    public function deleteStatus(): ?string
    {
        return $this->asyncStatusValue('delete');
    }

    public function setDeleteStatus(?string $status): void
    {
        $this->setAsyncStatusValue('delete', $status);
    }

    public function backupProgress(): ?int
    {
        return $this->asyncProgressValue('backup_progress');
    }

    public function setBackupProgress(?int $progress): void
    {
        $this->setAsyncProgressValue('backup_progress', $progress);
    }

    public function restoreProgress(): ?int
    {
        return $this->asyncProgressValue('restore_progress');
    }

    public function setRestoreProgress(?int $progress): void
    {
        $this->setAsyncProgressValue('restore_progress', $progress);
    }

    public function deleteProgress(): ?int
    {
        return $this->asyncProgressValue('delete_progress');
    }

    public function setDeleteProgress(?int $progress): void
    {
        $this->setAsyncProgressValue('delete_progress', $progress);
    }

    private function asyncStatusValue(string $key): ?string
    {
        $status = $this->async_status ?? [];
        $value = $status[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function setAsyncStatusValue(string $key, ?string $value): void
    {
        $status = $this->async_status ?? [];
        if ($value === null) {
            unset($status[$key]);
        } else {
            $status[$key] = $value;
        }
        $this->async_status = $status;
    }

    private function asyncProgressValue(string $key): ?int
    {
        $status = $this->async_status ?? [];
        $value = $status[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function setAsyncProgressValue(string $key, ?int $value): void
    {
        $status = $this->async_status ?? [];
        if ($value === null) {
            unset($status[$key]);
        } else {
            $status[$key] = $value;
        }
        $this->async_status = $status;
    }
}
