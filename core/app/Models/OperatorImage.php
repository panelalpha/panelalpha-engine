<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A base image in panelalpha-cache-registry for every project to build `FROM`. Never trimmed, pruned or
 * budgeted away; the weekly refresh renews it the way its source says.
 *
 * @property int $id
 * @property string $ref
 * @property string $source
 * @property ?string $dockerfile
 * @property ?array<string, string> $build_args
 * @property ?string $why
 * @property string $status
 * @property ?string $digest
 * @property ?int $size_bytes
 * @property ?Carbon $refreshed_at
 * @property ?string $error
 * @property ?int $task_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OperatorImage extends Model
{
    public const SOURCE_PULL = 'pull';

    public const SOURCE_BUILD = 'build';

    public const SOURCE_LOCAL = 'local';

    public const SOURCES = [self::SOURCE_PULL, self::SOURCE_BUILD, self::SOURCE_LOCAL];

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REMOVING = 'removing';

    protected $fillable = [
        'ref',
        'source',
        'dockerfile',
        'build_args',
        'why',
        'status',
        'digest',
        'size_bytes',
        'refreshed_at',
        'error',
        'task_id',
    ];

    protected $casts = [
        'build_args' => 'array',
        'size_bytes' => 'integer',
        'refreshed_at' => 'datetime',
        'task_id' => 'integer',
    ];

    public static function findByRef(string $ref): ?self
    {
        return self::query()->where('ref', $ref)->first();
    }

    /**
     * Every operator ref, whatever its state, or null when the table cannot
     * be read: a caller deleting what is not listed must then delete nothing.
     *
     * @return list<string>|null
     */
    public static function refs(): ?array
    {
        try {
            return array_values(array_map('strval', self::query()->orderBy('ref')->pluck('ref')->all()));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whether a queued operation on it has not finished yet. */
    public function isBusy(): bool
    {
        $task = $this->task_id === null ? null : Task::query()->find($this->task_id);

        return $task instanceof Task && ! $task->isTerminal();
    }

    public function isBuilt(): bool
    {
        return $this->source === self::SOURCE_BUILD;
    }

    /** @return array<string, string> */
    public function buildArgs(): array
    {
        return array_map('strval', is_array($this->build_args) ? $this->build_args : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'owner' => 'operator',
            'ref' => $this->ref,
            'source' => $this->source,
            'why' => $this->why,
            'dockerfile' => $this->dockerfile,
            'build_args' => (object) $this->buildArgs(),
            'status' => $this->status,
            'digest' => $this->digest,
            'size' => $this->size_bytes,
            'refreshed_at' => $this->refreshed_at?->toIso8601String(),
            'error' => $this->error,
            'task_id' => $this->task_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
