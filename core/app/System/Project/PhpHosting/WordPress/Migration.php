<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchedStep;
use App\System\Project\PhpHosting\WordPress\Migration\FailureCleanup;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationCancellation;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\RunHeartbeat;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourcePluginOnFailure;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\ClearSecretsStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\CreateProjectStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\FinalizeStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallDestinationPluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallSourcePluginStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\InstallWordpressStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateDatabaseStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\MigrateFilesStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemovePluginsStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\ResolveSourceStep;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\VerifySourcePluginStep;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Advances a WordPress migration record through its remaining sequence, one
 * bounded run at a time: a run claims the record, does one step (or a share
 * of a batched one), and says whether another run must follow. A requested
 * cancel is carried out by the next run instead of a step. Source calls keep
 * the claim alive while they wait; a run that lost its claim to another one
 * stops without touching the record.
 *
 * Status transitions stay on the record. The controller queues the job.
 * The queue job is an adapter that constructs this module with no arguments.
 * Tests pass a substitute sequence; callers other than tests use the production sequence.
 */
final class Migration
{
    /** @var list<MigrationStep> */
    private array $sequence;

    private FailureCleanup $failureCleanup;

    private MigrationCancellation $cancellation;

    private SourceConnection $source;

    /**
     * @param  list<MigrationStep>|null  $sequence  Production sequence when omitted.
     */
    public function __construct(
        ?array $sequence = null,
        ?FailureCleanup $failureCleanup = null,
        ?MigrationCancellation $cancellation = null,
        ?SourceConnection $source = null,
    ) {
        $source ??= new SourceConnection;
        $this->source = $source;
        $this->sequence = $sequence ?? self::productionSequence($source);
        $this->failureCleanup = $failureCleanup ?? new SourcePluginOnFailure($source);
        $this->cancellation = $cancellation ?? new MigrationCancellation($source);
    }

    /**
     * Ordered step names stored on a new record of the method. This is the only
     * author of that order. manual_plugin verifies the plugin the user
     * installed where wp_admin installs it.
     *
     * @return list<string>
     */
    public static function stepNames(string $method = WordpressMigration::METHOD_WP_ADMIN): array
    {
        return [
            WordpressMigration::STEP_RESOLVE_SOURCE,
            WordpressMigration::STEP_CREATE_PROJECT,
            WordpressMigration::STEP_INSTALL_WORDPRESS,
            WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN,
            $method === WordpressMigration::METHOD_MANUAL_PLUGIN
                ? WordpressMigration::STEP_VERIFY_SOURCE_PLUGIN
                : WordpressMigration::STEP_INSTALL_SOURCE_PLUGIN,
            WordpressMigration::STEP_REMOTE_SYNC,
            WordpressMigration::STEP_MIGRATE_DATABASE,
            WordpressMigration::STEP_MIGRATE_FILES,
            WordpressMigration::STEP_FINALIZE,
            WordpressMigration::STEP_REMOVE_PLUGINS,
            WordpressMigration::STEP_CLEAR_SECRETS,
        ];
    }

    /**
     * Skeleton persisted by WordpressMigration::start.
     *
     * @return list<array{name: string, status: string}>
     */
    public static function initialSteps(string $method = WordpressMigration::METHOD_WP_ADMIN): array
    {
        $steps = [];
        foreach (self::stepNames($method) as $name) {
            $steps[] = [
                'name' => $name,
                'status' => WordpressMigration::STEP_STATUS_PENDING,
            ];
        }

        return $steps;
    }

    /**
     * Run until the record completes, fails or is cancelled, in the same
     * bounded runs the queue job makes.
     */
    public function advance(WordpressMigration $migration): void
    {
        while ($this->advanceOnce($migration) === MigrationSlice::More) {
            $migration->refresh();
        }
    }

    /**
     * One bounded run under the record's claim. The queue payload stays the
     * record id; each step reads secrets itself.
     */
    public function advanceOnce(WordpressMigration $migration): MigrationSlice
    {
        $runId = bin2hex(random_bytes(16));
        if (! $migration->claimRun($runId)) {
            return MigrationSlice::Busy;
        }

        $this->source->keepAlive(new RunHeartbeat($migration, $runId));
        try {
            return $this->slice($migration->refresh(), $runId);
        } finally {
            $this->source->keepAlive(null);
            $migration->releaseRun($runId);
        }
    }

    private function slice(WordpressMigration $migration, string $runId): MigrationSlice
    {
        if ($migration->status === WordpressMigration::STATUS_CANCELLING) {
            $this->cancel($migration, $runId);

            return MigrationSlice::Done;
        }
        if (! in_array($migration->status, WordpressMigration::ACTIVE_STATUSES, true)) {
            return MigrationSlice::Done;
        }

        $migration->markRunning();
        $step = $migration->nextIncompleteStep();
        if ($migration->status === WordpressMigration::STATUS_RUNNING && $step !== null) {
            $this->runStep($migration, $step['name'], $runId);
        }

        return $this->afterStep($migration);
    }

    private function afterStep(WordpressMigration $migration): MigrationSlice
    {
        $migration->refresh();
        if ($migration->status === WordpressMigration::STATUS_CANCELLING) {
            return MigrationSlice::More;
        }
        if ($migration->nextIncompleteStep() !== null) {
            return MigrationSlice::More;
        }

        $migration->markCompleted();

        return MigrationSlice::Done;
    }

    private function runStep(WordpressMigration $migration, string $name, string $runId): void
    {
        $handler = $this->handlersByName()[$name]
            ?? throw new InvalidArgumentException("Unknown WordPress migration step: {$name}");

        try {
            $migration->storeProgress($name);
            $handler->run($migration);
            $this->recordOutcome($migration->refresh(), $handler);
        } catch (Throwable $e) {
            $this->fail($migration, $name, $e, $runId);
        }
    }

    private function recordOutcome(WordpressMigration $migration, MigrationStep $handler): void
    {
        $name = $handler->name();
        if ($migration->stepStatus($name) === WordpressMigration::STEP_STATUS_COMPLETED) {
            $migration->appendStepLog($name, WordpressMigration::STEP_STATUS_COMPLETED);

            return;
        }
        if (! $handler instanceof BatchedStep) {
            throw new RuntimeException("WordPress migration step {$name} returned without completing");
        }
    }

    /**
     * A failure while a cancel is pending is left to the cancel; any other
     * keeps the secrets for resume and is rethrown. A run that lost its claim
     * leaves the record to the run that took it over.
     */
    private function fail(WordpressMigration $migration, string $name, Throwable $e, string $runId): void
    {
        self::rethrowWithoutClaim($migration, $runId, $e);
        $migration->refresh();
        $migration->markStepFailed($name);
        $migration->appendStepLog($name, WordpressMigration::STEP_STATUS_FAILED, $e->getMessage());
        if ($migration->cancelRequested()) {
            return;
        }

        $migration->markFailed();
        $this->failureCleanup->afterFailure($migration, $name);

        throw $e;
    }

    private function cancel(WordpressMigration $migration, string $runId): void
    {
        try {
            $this->cancellation->run($migration);
        } catch (Throwable $e) {
            self::rethrowWithoutClaim($migration, $runId, $e);
            $migration->refresh();
            $migration->appendStepLog(MigrationCancellation::LOG_STEP, WordpressMigration::STEP_STATUS_FAILED, $e->getMessage());

            throw $e;
        }
    }

    private static function rethrowWithoutClaim(WordpressMigration $migration, string $runId, Throwable $e): void
    {
        if (! $migration->holdsRun($runId)) {
            throw $e;
        }
    }

    /**
     * @return array<string, MigrationStep>
     */
    private function handlersByName(): array
    {
        $handlers = [];
        foreach ($this->sequence as $step) {
            $handlers[$step->name()] = $step;
        }

        return $handlers;
    }

    /**
     * Every handler of both methods. The source steps share one connection, so
     * a run finds the source and logs into wp-admin at most once.
     *
     * @return list<MigrationStep>
     */
    private static function productionSequence(SourceConnection $source): array
    {
        return [
            new ResolveSourceStep($source),
            new CreateProjectStep,
            new InstallWordpressStep,
            new InstallDestinationPluginStep,
            new InstallSourcePluginStep($source),
            new VerifySourcePluginStep($source),
            new RemoteSyncStep($source),
            new MigrateDatabaseStep($source),
            new MigrateFilesStep($source),
            new FinalizeStep($source),
            new RemovePluginsStep($source),
            new ClearSecretsStep,
        ];
    }
}
