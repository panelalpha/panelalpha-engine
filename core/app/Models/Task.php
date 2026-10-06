<?php

namespace App\Models;

use App\Lib\Testing\CoverageRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class Task extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    /** `details` key of the worker process running the task; never shown to a client. */
    public const WORKER = 'worker';

    /**
     * Jobs that deploy a project and write its deploy log.
     *
     * @var list<class-string>
     */
    public const DEPLOY_JOB_TYPES = [
        \App\Jobs\DeployProject::class,
        \App\Jobs\RebuildProject::class,
    ];

    protected $fillable = [
        'job_id',
        'username',
        'queue',
        'job_type',
        'status',
        'details',
        'pid',
        'pid_start_time',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'details' => 'array',
        'pid' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public static function start(
        string $jobType,
        string $queue,
        ?string $username = null,
        ?array $details = null
    ): self {
        if ($jobType === '' || $queue === '') {
            throw new InvalidArgumentException('job_type and queue are required');
        }

        $details = self::stampCoverageTokenId($details);

        return self::create([
            'job_type' => $jobType,
            'queue' => $queue,
            'username' => $username,
            'details' => $details,
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
    }

    /**
     * When a coverage recording session is active for the current API token,
     * persist its id on the task so queue workers can dump coverage later.
     *
     * @param array<string, mixed>|null $details
     * @return array<string, mixed>|null
     */
    private static function stampCoverageTokenId(?array $details): ?array
    {
        $tokenId = self::currentCoverageTokenId();
        if ($tokenId === null) {
            return $details;
        }

        $details ??= [];
        $details['api_token_id'] = $tokenId;

        return $details;
    }

    private static function currentCoverageTokenId(): ?int
    {
        if (!App::hasDebugModeEnabled()) {
            return null;
        }

        $user = Auth::user();
        if (!$user instanceof Admin) {
            return null;
        }

        $token = $user->currentAccessToken();
        if (!$token instanceof PersonalAccessToken) {
            return null;
        }

        $tokenId = (int) $token->id;
        if (!CoverageRecorder::isRecording($tokenId)) {
            return null;
        }

        return $tokenId;
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /**
     * @return Collection<int, TaskLog>
     */
    public function logsAfter(int $afterId, int $limit = 2000): Collection
    {
        return $this->logsPage(afterId: $afterId, limit: $limit);
    }

    /**
     * Page of log rows after an id cursor and/or a timestamp.
     *
     * $since is unix seconds or an ISO datetime string. Both filters AND
     * together so a poller can advance by created_at and still skip rows that
     * share the same second via after_id.
     *
     * @return Collection<int, TaskLog>
     */
    public function logsPage(int $afterId = 0, int|string|null $since = null, int $limit = 2000): Collection
    {
        $query = $this->logs()->orderBy('id')->limit($limit);

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $sinceAt = self::parseSince($since);
        if ($sinceAt !== null) {
            $query->where('created_at', '>', $sinceAt);
        }

        return $query->get();
    }

    /**
     * @return Carbon|null null when the filter is absent or zero
     */
    public static function parseSince(int|string|null $since): ?Carbon
    {
        if ($since === null || $since === '' || $since === 0 || $since === '0') {
            return null;
        }

        if (is_int($since) || (is_string($since) && ctype_digit($since))) {
            $seconds = (int) $since;
            if ($seconds <= 0) {
                return null;
            }

            return Carbon::createFromTimestamp($seconds);
        }

        return Carbon::parse((string) $since);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Whether this task was a project deploy.
     *
     * Deploys are the only job types that write a per-account deploy log, and
     * the only ones whose liveness can be read from the pid that log records —
     * {@see \App\Lib\Task\TaskReconciler} relies on both facts.
     */
    public function isDeploy(): bool
    {
        return in_array($this->job_type, self::DEPLOY_JOB_TYPES, true);
    }

    public function markRunning(?string $jobId = null): bool
    {
        if ($this->status !== self::STATUS_QUEUED) {
            return false;
        }

        $this->status = self::STATUS_RUNNING;
        $this->started_at = now();
        if (is_string($jobId) && $jobId !== '') {
            $this->job_id = $jobId;
        }
        $this->save();

        return true;
    }

    public function markCompleted(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        $this->save();

        return true;
    }

    public function markFailed(string $error): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        $details = $this->details ?? [];
        $details['error'] = $error;
        $this->details = $details;
        $this->status = self::STATUS_FAILED;
        $this->failed_at = now();
        $this->save();

        return true;
    }

    public function markCancelled(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->save();

        return true;
    }

    public function setPid(?int $pid, ?string $startTime): void
    {
        $this->pid = $pid;
        $this->pid_start_time = $pid === null ? null : $startTime;
        $this->save();
    }
}
