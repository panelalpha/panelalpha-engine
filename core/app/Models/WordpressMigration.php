<?php

namespace App\Models;

use App\Lib\Domains\DomainPlan;
use App\System\Project\PhpHosting\WordPress\Migration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationProgress;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use App\System\Project\PhpHosting\WordPress\Migration\TransferProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A resumable WordPress site migration: ordered steps plus encrypted
 * wp-admin password and migrator token. Secrets never enter step_log or
 * status payloads.
 *
 * @property int $id
 * @property string $source_url
 * @property ?string $username
 * @property string $method
 * @property ?string $login_url
 * @property ?string $rest_mode
 * @property ?string $source_ip
 * @property ?string $source_token
 * @property ?string $target_label
 * @property string $status
 * @property list<array{name: string, status: string}> $steps
 * @property list<array{step: string, status: string, message?: string}>|null $step_log
 * @property array{step: string, phase_percent: ?float, item: ?string, message: ?string, updated_at: string}|null $progress
 * @property list<array{code: string, message: string}>|null $warnings
 * @property ?string $password
 * @property ?string $migrator_token
 * @property ?string $plugin_migration_id
 * @property ?string $target_project
 * @property bool $created_project
 * @property bool $domain_given
 * @property ?string $preview_hostname
 * @property ?Carbon $dns_switched_at
 * @property ?string $run_id
 * @property ?Carbon $heartbeat_at
 * @property ?string $created_database
 * @property ?string $created_database_user
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WordpressMigration extends Model
{
    public const METHOD_WP_ADMIN = 'wp_admin';

    public const METHOD_MANUAL_PLUGIN = 'manual_plugin';

    public const METHODS = [self::METHOD_WP_ADMIN, self::METHOD_MANUAL_PLUGIN];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    /** A record in one of these may still create or fill its target. */
    public const UNFINISHED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_FAILED,
        self::STATUS_CANCELLING,
    ];

    /** A run is advancing in one of these. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /** Only a record in one of these may be deleted. */
    public const ENDED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** A run whose heartbeat is older than this is taken for dead. */
    public const STALE_MINUTES = 15;

    /** An unfinished import without activity this long loses its password and tokens. */
    public const SECRETS_KEPT_DAYS = 7;

    public const LOG_SECRETS_EXPIRED = 'secrets_expired';

    /** The steps that run on the destination plugin's token, from its install to the end of the transfer. */
    private const TOKEN_STEPS = [
        self::STEP_INSTALL_DESTINATION_PLUGIN,
        self::STEP_INSTALL_SOURCE_PLUGIN,
        self::STEP_VERIFY_SOURCE_PLUGIN,
        self::STEP_REMOTE_SYNC,
        self::STEP_MIGRATE_DATABASE,
        self::STEP_MIGRATE_FILES,
        self::STEP_FINALIZE,
    ];

    public const STEP_STATUS_PENDING = 'pending';

    public const STEP_STATUS_COMPLETED = 'completed';

    public const STEP_STATUS_FAILED = 'failed';

    public const STEP_RESOLVE_SOURCE = 'resolve_source';

    public const STEP_CREATE_PROJECT = 'create_project';

    public const STEP_INSTALL_WORDPRESS = 'install_wordpress';

    public const STEP_INSTALL_DESTINATION_PLUGIN = 'install_destination_plugin';

    public const STEP_INSTALL_SOURCE_PLUGIN = 'install_source_plugin';

    public const STEP_VERIFY_SOURCE_PLUGIN = 'verify_source_plugin';

    public const STEP_REMOTE_SYNC = 'remote_sync';

    public const STEP_MIGRATE_DATABASE = 'migrate_database';

    public const STEP_MIGRATE_FILES = 'migrate_files';

    public const STEP_FINALIZE = 'finalize';

    public const STEP_REMOVE_PLUGINS = 'remove_plugins';

    public const STEP_CLEAR_SECRETS = 'clear_secrets';

    protected $fillable = [
        'source_url',
        'username',
        'target_label',
        'status',
        'steps',
        'step_log',
        'progress',
        'password',
        'migrator_token',
        'plugin_migration_id',
        'target_project',
        'created_project',
        'domain_given',
        'preview_hostname',
        'dns_switched_at',
        'method',
        'login_url',
        'rest_mode',
        'source_ip',
        'source_token',
        'run_id',
        'heartbeat_at',
        'created_database',
        'created_database_user',
        'warnings',
    ];

    protected $hidden = [
        'password',
        'migrator_token',
        'source_token',
    ];

    protected $casts = [
        'steps' => 'array',
        'step_log' => 'array',
        'progress' => 'array',
        'warnings' => 'array',
        'password' => 'encrypted',
        'migrator_token' => 'encrypted',
        'created_project' => 'boolean',
        'domain_given' => 'boolean',
        'dns_switched_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'source_token' => 'encrypted',
    ];

    /**
     * Persist the source access (wp-admin password or plugin token, encrypted),
     * the target and the pending step skeleton of the method.
     * Queueing the job is the caller's; the payload is the record id. Without
     * a target the import creates a project on an allocated panelalpha.online name.
     */
    public static function start(
        string $sourceUrl,
        ?string $username = null,
        ?string $password = null,
        ?MigrationTarget $target = null,
        string $method = self::METHOD_WP_ADMIN,
        ?string $loginUrl = null,
        ?string $sourceIp = null,
        ?string $sourceToken = null,
    ): self {
        $target ??= MigrationTarget::newProject(null, null);
        $migration = new self;
        $migration->source_url = $sourceUrl;
        $migration->username = $username;
        $migration->target_label = $target->domain;
        $migration->target_project = $target->project;
        $migration->created_project = $target->createsProject;
        $migration->domain_given = $target->domainGiven;
        $migration->method = $method;
        $migration->login_url = $loginUrl;
        $migration->source_ip = $sourceIp;
        $migration->status = self::STATUS_PENDING;
        $migration->steps = Migration::initialSteps($method);
        $migration->step_log = [];
        $migration->password = $password;
        $migration->source_token = $sourceToken;
        $migration->migrator_token = null;
        $migration->save();

        return $migration;
    }

    public function importMethod(): string
    {
        return $this->method ?? self::METHOD_WP_ADMIN;
    }

    public function usesManualPlugin(): bool
    {
        return $this->importMethod() === self::METHOD_MANUAL_PLUGIN;
    }

    /**
     * The source as it was found: its home becomes source_url, so the install
     * subdirectory comes from the site itself and never from a login path.
     */
    public function storeResolvedSource(string $url, ?string $loginUrl, string $restMode): void
    {
        $this->source_url = $url;
        $this->login_url = $loginUrl;
        $this->rest_mode = $restMode;
        $this->save();
    }

    /**
     * Re-open a failed record, or one whose run died (running or pending with
     * a stale heartbeat), so a caller can queue another run. Skips steps
     * already `completed`. Does not queue the job. $sourceSecret replaces the
     * wp-admin password (wp_admin) or plugin token (manual_plugin), and is
     * required once {@see expireIdleSecrets} has cleared it.
     */
    public function resume(?string $sourceSecret = null): self
    {
        $missing = $this->missingSourceSecret();
        if ($sourceSecret === null && $missing !== null) {
            throw new InvalidArgumentException(
                "The {$missing} was cleared after ".self::SECRETS_KEPT_DAYS.' days without activity: resume with it again',
            );
        }

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $query) => $query
                ->where('status', self::STATUS_FAILED)
                ->orWhere(fn (Builder $stale) => self::whereStale($stale->whereIn('status', self::ACTIVE_STATUSES))))
            ->update([
                'status' => self::STATUS_PENDING,
                'run_id' => null,
                'updated_at' => $this->freshTimestamp(),
            ]);

        if ($updated !== 1) {
            throw new InvalidArgumentException(
                'Only a failed WordPress migration, or one that has not reported for '
                    .self::STALE_MINUTES.' minutes, can be resumed',
            );
        }

        $this->refresh();
        if ($sourceSecret !== null) {
            $this->storeSourceSecret($sourceSecret);
        }
        if ($missing !== null) {
            $this->restartUnfinishedTransfer();
        }

        return $this;
    }

    /**
     * The field a resume must send again because it was cleared:
     * `password` (wp_admin) or `source_token` (manual_plugin); null when
     * stored, or when the record is not one a resume takes.
     */
    public function missingSourceSecret(): ?string
    {
        if (! in_array($this->status, [self::STATUS_FAILED, ...self::ACTIVE_STATUSES], true)) {
            return null;
        }
        $secrets = $this->secretsForWorker();
        if ($this->usesManualPlugin()) {
            return $secrets['source_token'] === null ? 'source_token' : null;
        }

        return $secrets['password'] === null ? 'password' : null;
    }

    private function storeSourceSecret(string $secret): void
    {
        if ($this->usesManualPlugin()) {
            $this->source_token = $secret;
        } else {
            $this->password = $secret;
        }
        $this->save();
    }

    /**
     * The tokens were cleared with the source's secret: a transfer left
     * unfinished starts again from the destination plugin's install, which
     * writes a new token.
     */
    private function restartUnfinishedTransfer(): void
    {
        if (
            $this->stepStatus(self::STEP_INSTALL_DESTINATION_PLUGIN) !== self::STEP_STATUS_COMPLETED
            || $this->stepStatus(self::STEP_FINALIZE) === self::STEP_STATUS_COMPLETED
        ) {
            return;
        }

        $this->restartSteps(array_values(array_filter(
            self::TOKEN_STEPS,
            fn (string $step): bool => $this->stepStatus($step) !== null,
        )));
    }

    /**
     * Ask the run to stop and undo what the import made. A run checks this
     * between steps and batches; a record nobody is running is cancelled by
     * the job the caller queues.
     */
    public function requestCancel(): self
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', self::UNFINISHED_STATUSES)
            ->update(['status' => self::STATUS_CANCELLING, 'updated_at' => $this->freshTimestamp()]);

        if ($updated !== 1) {
            throw new InvalidArgumentException('A completed or cancelled WordPress migration cannot be cancelled');
        }

        return $this->refresh();
    }

    public function cancelRequested(): bool
    {
        return static::query()->whereKey($this->getKey())->value('status') === self::STATUS_CANCELLING;
    }

    /**
     * Remove a record whose run has ended. A failed import's project and
     * plugins stay: cancel it first to remove them.
     */
    public function deleteEnded(): void
    {
        $deleted = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', self::ENDED_STATUSES)
            ->delete();

        if ($deleted !== 1) {
            throw new InvalidArgumentException('Only a completed, failed or cancelled WordPress migration can be deleted');
        }
    }

    public function markRunning(): void
    {
        $this->moveStatus(self::ACTIVE_STATUSES, self::STATUS_RUNNING);
    }

    public function markCompleted(): void
    {
        $this->moveStatus([self::STATUS_RUNNING], self::STATUS_COMPLETED);
    }

    /** A failure during a requested cancel leaves the record cancelling, so the cancel goes on. */
    public function markFailed(): void
    {
        $this->moveStatus(self::ACTIVE_STATUSES, self::STATUS_FAILED);
    }

    public function markCancelled(): void
    {
        $this->moveStatus([self::STATUS_CANCELLING], self::STATUS_CANCELLED);
    }

    /**
     * Claim the record for one run. Refused while another run holds it and
     * its heartbeat is fresh; a stale holder is taken over.
     */
    public function claimRun(string $runId): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $query) => self::whereStale($query->whereNotNull('run_id'))->orWhereNull('run_id'))
            ->update(['run_id' => $runId, 'heartbeat_at' => $this->freshTimestamp()]);

        return $claimed === 1;
    }

    public function releaseRun(string $runId): void
    {
        static::query()->whereKey($this->getKey())->where('run_id', $runId)->update(['run_id' => null]);
    }

    /**
     * Refresh the heartbeat of a run that still holds the record, so a run
     * waiting on the source is not taken for dead. False once another run
     * has taken the record over.
     */
    public function refreshHeartbeat(string $runId): bool
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('run_id', $runId)
            ->update(['heartbeat_at' => $this->freshTimestamp()]);

        return $this->holdsRun($runId);
    }

    public function holdsRun(string $runId): bool
    {
        return static::query()->whereKey($this->getKey())->where('run_id', $runId)->exists();
    }

    /** Running or pending without a heartbeat for STALE_MINUTES: its run has died. */
    public function isStale(): bool
    {
        $last = $this->heartbeat_at ?? $this->updated_at;

        return in_array($this->status, self::ACTIVE_STATUSES, true)
            && ($last === null || $last->lt(self::staleCutoff()));
    }

    /**
     * @param  list<string>  $from
     */
    private function moveStatus(array $from, string $to): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', $from)
            ->update(['status' => $to, 'updated_at' => $this->freshTimestamp()]);
        $this->refresh();
    }

    private static function whereStale(Builder $query): Builder
    {
        $before = self::staleCutoff();

        return $query->where(fn (Builder $stale) => $stale
            ->where('heartbeat_at', '<', $before)
            ->orWhere(fn (Builder $never) => $never->whereNull('heartbeat_at')->where('updated_at', '<', $before)));
    }

    private static function staleCutoff(): Carbon
    {
        return now()->subMinutes(self::STALE_MINUTES);
    }

    /**
     * First step whose status is not `completed` (pending or failed).
     *
     * @return array{name: string, status: string}|null
     */
    public function nextIncompleteStep(): ?array
    {
        foreach ($this->steps ?? [] as $step) {
            if (($step['status'] ?? null) !== self::STEP_STATUS_COMPLETED) {
                return $step;
            }
        }

        return null;
    }

    public function stepStatus(string $name): ?string
    {
        foreach ($this->steps ?? [] as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step['status'] ?? null;
            }
        }

        return null;
    }

    public function markStepCompleted(string $name): void
    {
        $this->setStepStatus($name, self::STEP_STATUS_COMPLETED);
    }

    public function markStepFailed(string $name): void
    {
        $this->setStepStatus($name, self::STEP_STATUS_FAILED);
    }

    public function setStepStatus(string $name, string $status): void
    {
        $steps = $this->steps ?? [];
        $found = false;
        foreach ($steps as $i => $step) {
            if (($step['name'] ?? null) === $name) {
                $steps[$i]['status'] = $status;
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw new InvalidArgumentException("Unknown WordPress migration step: {$name}");
        }

        $this->steps = $steps;
        $this->save();
    }

    public function appendStepLog(string $name, string $status, ?string $message = null): void
    {
        $log = $this->step_log ?? [];
        $entry = [
            'step' => $name,
            'status' => $status,
        ];
        if ($message !== null && $message !== '') {
            $entry['message'] = $message;
        }
        $log[] = $entry;
        $this->step_log = $log;
        $this->save();
    }

    /**
     * Where the running step is; overwritten on each call. Its updated_at is
     * the run's heartbeat.
     */
    public function storeProgress(string $step, ?float $phasePercent = null, ?string $item = null, ?string $message = null): void
    {
        $this->progress = [
            'step' => $step,
            'phase_percent' => $phasePercent,
            'item' => $item,
            'message' => $message ?? MigrationProgress::stepMessage($step),
            'updated_at' => $this->freshTimestamp()->toIso8601String(),
        ];
        $this->heartbeat_at = $this->freshTimestamp();
        $this->save();
    }

    public function storeTransferProgress(string $step, TransferProgress $progress): void
    {
        $this->storeProgress($step, $progress->phasePercent(), $progress->item, $progress->message());
    }

    /**
     * Called after step 5 (remote-sync) once the source plugin returns a migration id.
     */
    public function storePluginMigrationId(string $migrationId): void
    {
        $this->plugin_migration_id = $migrationId;
        $this->save();
    }

    /**
     * Called by create_project before it creates the project, so a rerun
     * adopts the project instead of creating a second one.
     */
    public function storeTargetProject(string $project): void
    {
        $this->target_project = $project;
        $this->save();
    }

    /**
     * Called by install_wordpress before it creates them, so a rerun reuses
     * them and a cancel drops them.
     */
    public function storeCreatedDatabase(string $database, string $databaseUser): void
    {
        $this->created_database = $database;
        $this->created_database_user = $databaseUser;
        $this->save();
    }

    /**
     * The source plugin was removed after a failure: resume installs it again
     * and restarts the transfer, which the removed plugin can no longer continue.
     * plugin_migration_id stays until remote-sync replaces it: the source may
     * still hold that transfer's lock, which this import may take over.
     *
     * @param  list<string>  $steps
     */
    public function restartSteps(array $steps): void
    {
        foreach ($steps as $step) {
            $this->setStepStatus($step, self::STEP_STATUS_PENDING);
        }
    }

    /**
     * Whether an import on this engine started this transfer on its source
     * site; any other transfer holding the source's lock is never taken over.
     */
    public static function startedPluginMigration(string $pluginMigrationId): bool
    {
        return static::query()->where('plugin_migration_id', $pluginMigrationId)->exists();
    }

    /**
     * Something the import noticed but goes on with, shown in its status;
     * one entry per code.
     */
    public function addWarning(string $code, string $message): void
    {
        $this->warnings = [...$this->warningsExcept($code), ['code' => $code, 'message' => $message]];
        $this->save();
    }

    public function removeWarning(string $code): void
    {
        $this->warnings = $this->warningsExcept($code);
        $this->save();
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function warningsExcept(string $code): array
    {
        return array_values(array_filter(
            $this->warnings ?? [],
            static fn (array $warning): bool => ($warning['code'] ?? null) !== $code,
        ));
    }

    /**
     * Called by create_project once the target project and its domain exist.
     */
    public function storeTarget(string $project, string $domain): void
    {
        $this->target_project = $project;
        $this->target_label = $domain;
        $this->save();
    }

    /**
     * Called by resolve_source when the site's real home gives another domain
     * than the URL the import was started with; null for a panelalpha.online name.
     */
    public function storeTargetDomain(?string $domain): void
    {
        $this->target_label = $domain;
        $this->save();
    }

    public function storePreviewHostname(?string $hostname): void
    {
        $this->preview_hostname = $hostname;
        $this->save();
    }

    /**
     * The real domain answers on this engine: the preview is gone and the
     * certificate requested, so the DNS check stops visiting this record.
     */
    public function markDnsSwitched(): void
    {
        $this->preview_hostname = null;
        $this->dns_switched_at = $this->freshTimestamp();
        $this->save();
    }

    /**
     * Completed imports onto a domain of the customer's own whose DNS has not
     * yet been seen pointing at this engine.
     *
     * @return Collection<int, self>
     */
    public static function awaitingDnsSwitch(): Collection
    {
        return self::query()
            ->where('status', self::STATUS_COMPLETED)
            ->whereNull('dns_switched_at')
            ->whereNotNull('target_label')
            ->get()
            ->filter(static fn (self $migration): bool => $migration->keepsItsOwnDomain())
            ->values();
    }

    /** False for a panelalpha.online name, which never waits for DNS. */
    public function keepsItsOwnDomain(): bool
    {
        return is_string($this->target_label)
            && $this->target_label !== ''
            && DomainPlan::onlineLabel($this->target_label) === null;
    }

    /**
     * Called after step 3 (install destination plugin) once a migrator token exists.
     */
    public function storeMigratorToken(string $token): void
    {
        $this->migrator_token = $token;
        $this->save();
    }

    /**
     * Called by install_source_plugin before it uploads the plugin carrying
     * this token, so a rerun reuses it and every source call can send it.
     */
    public function storeSourceToken(string $token): void
    {
        $this->source_token = $token;
        $this->save();
    }

    /**
     * Decrypt password and migrator token for the worker. Never put these on
     * the job payload — call this from handle() instead.
     *
     * @return array{password: ?string, migrator_token: ?string, source_token: ?string}
     */
    public function secretsForWorker(): array
    {
        return [
            'password' => $this->password,
            'migrator_token' => $this->migrator_token,
            'source_token' => $this->source_token,
        ];
    }

    /**
     * Success of the last step and a cancel clear every secret. A `failed`
     * status keeps them for resume, until {@see expireIdleSecrets}.
     */
    public function clearSecrets(): void
    {
        $this->password = null;
        $this->migrator_token = null;
        $this->source_token = null;
        $this->save();
    }

    /**
     * Clear the secrets of every import that has had no activity for
     * SECRETS_KEPT_DAYS: failed, cancelling, or a run that died. Returns how many.
     */
    public static function expireIdleSecrets(): int
    {
        $cleared = 0;
        foreach (self::whereIdleWithSecrets(self::query())->get() as $migration) {
            $cleared += $migration->expireSecrets() ? 1 : 0;
        }

        return $cleared;
    }

    /** Only while still idle, so a resume that just stored a secret keeps it. */
    private function expireSecrets(): bool
    {
        $cleared = self::whereIdleWithSecrets(static::query()->whereKey($this->getKey()))
            ->update(['password' => null, 'migrator_token' => null, 'source_token' => null]);
        if ($cleared !== 1) {
            return false;
        }

        $this->refresh();
        $this->appendStepLog(
            self::LOG_SECRETS_EXPIRED,
            self::STEP_STATUS_COMPLETED,
            'Cleared the stored '.($this->usesManualPlugin() ? 'plugin token' : 'wp-admin password').' and tokens after '
                .self::SECRETS_KEPT_DAYS.' days without activity: resume with it again, or cancel.',
        );

        return true;
    }

    private static function whereIdleWithSecrets(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::UNFINISHED_STATUSES)
            ->where('updated_at', '<', now()->subDays(self::SECRETS_KEPT_DAYS))
            ->where(fn (Builder $secrets) => $secrets
                ->whereNotNull('password')
                ->orWhereNotNull('migrator_token')
                ->orWhereNotNull('source_token'));
    }

    /**
     * What the user still has to do by hand once the run ends.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        if (! $this->usesManualPlugin()) {
            return [];
        }

        return [
            'Remove the wordpress-migrator plugin from the source site as soon as the migration has finished, '
            .'failed or been cancelled: it was installed by hand, and the Engine has no wp-admin access to remove it.',
        ];
    }

    /**
     * API-shaped status: steps and log without password or tokens.
     *
     * @return array{
     *     id: int,
     *     source_url: string,
     *     username: ?string,
     *     method: string,
     *     login_url: ?string,
     *     rest_mode: ?string,
     *     source_ip: ?string,
     *     notices: list<string>,
     *     warnings: list<array{code: string, message: string}>,
     *     target_label: ?string,
     *     status: string,
     *     stale: bool,
     *     steps: list<array{name: string, status: string}>,
     *     step_log: list<array{step: string, status: string, message?: string}>,
     *     progress: array{step: ?string, step_index: int, step_count: int, percent: float, phase_percent: float, item: ?string, message: ?string, updated_at: ?string},
     *     target_project: ?string,
     *     target_domain: ?string,
     *     created_project: bool,
     *     preview_url: ?string,
     *     dns: array{type: string, value: string}|null
     * }
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'source_url' => $this->source_url,
            'username' => $this->username,
            'method' => $this->importMethod(),
            'login_url' => $this->login_url,
            'rest_mode' => $this->rest_mode,
            'source_ip' => $this->source_ip,
            'notices' => $this->notices(),
            'warnings' => $this->warnings ?? [],
            'target_label' => $this->target_label,
            'status' => $this->status,
            'stale' => $this->isStale(),
            'steps' => $this->steps ?? [],
            'step_log' => $this->step_log ?? [],
            'progress' => MigrationProgress::of($this),
            'target_project' => $this->target_project,
            'target_domain' => $this->target_label,
            'created_project' => $this->created_project ?? true,
            'preview_url' => $this->previewUrl(),
            'dns' => $this->dnsRecord(),
        ];
    }

    /** The preview name with the source's subdirectory, while the preview exists. */
    public function previewUrl(): ?string
    {
        if (! is_string($this->preview_hostname) || $this->preview_hostname === '') {
            return null;
        }

        return SourceUrl::destinationSiteUrl($this->preview_hostname, $this->source_url);
    }

    /**
     * The A record the customer sets so the kept domain reaches this engine.
     *
     * @return array{type: string, value: string}|null
     */
    public function dnsRecord(): ?array
    {
        if (! $this->keepsItsOwnDomain()) {
            return null;
        }

        $ipv4 = trim((string) Setting::get('default_ipv4'));
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return ['type' => 'A', 'value' => $ipv4];
    }
}
