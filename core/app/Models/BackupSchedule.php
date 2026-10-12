<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $container_id
 * @property string $cron
 * @property int $keep_last
 * @property bool $files
 * @property bool $databases
 * @property bool $enabled
 * @property ?Carbon $next_run_at
 * @property ?Carbon $last_run_at
 * @property ?int $last_backup_id
 * @property ?string $last_error
 * @property ?User $user
 * @property ?BackupContainer $container
 * @property ?Backup $lastBackup
 */
class BackupSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'container_id',
        'cron',
        'keep_last',
        'files',
        'databases',
        'enabled',
        'next_run_at',
        'last_run_at',
        'last_backup_id',
        'last_error',
    ];

    protected $casts = [
        'keep_last' => 'integer',
        'files' => 'boolean',
        'databases' => 'boolean',
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(BackupContainer::class, 'container_id');
    }

    public function lastBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'last_backup_id');
    }

    /**
     * @return array{files: bool, databases: bool}
     */
    public function contents(): array
    {
        return [
            'files' => (bool) $this->files,
            'databases' => (bool) $this->databases,
        ];
    }

    public function advanceNextRun(?Carbon $from = null): void
    {
        $this->next_run_at = self::nextRun($this->cron, $from);
    }

    public static function nextRun(string $cron, ?Carbon $from = null): Carbon
    {
        $expression = new CronExpression($cron);
        $date = $expression->getNextRunDate(
            CarbonImmutable::instance($from ?? Carbon::now('UTC')),
            0,
            false,
            'UTC',
        );

        return Carbon::instance($date);
    }

    /** A schedule may run at most once an hour; every gap in the next year of occurrences must be at least that long. */
    public static function isAtMostHourly(string $cron): bool
    {
        $expression = new CronExpression($cron);
        $cursor = CarbonImmutable::instance(Carbon::now('UTC'));
        $previous = null;

        for ($run = 0; $run < 200; $run++) {
            $next = CarbonImmutable::instance($expression->getNextRunDate($cursor, 0, false, 'UTC'));
            if ($previous !== null && $previous->diffInSeconds($next) < 3600) {
                return false;
            }
            $previous = $next;
            $cursor = $next;
        }

        return true;
    }
}
