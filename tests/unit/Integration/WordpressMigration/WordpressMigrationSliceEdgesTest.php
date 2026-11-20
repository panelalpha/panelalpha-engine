<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\Tunnel;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration as WordPressMigrationModule;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationCancellation;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationSlice;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\PreviewTunnel;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\RoutedSourceHttpClient;

/**
 * A run on a record that has ended does nothing, a step the sequence does not know is a programming error, a failure
 * while a cancel waits is left to the cancel, and a cancel that fails is logged and rethrown.
 */
class WordpressMigrationSliceEdgesTest extends ImportTargetTestCase
{
    public function test_a_run_on_an_ended_record_runs_no_step(): void
    {
        $executed = [];
        $migration = $this->started();
        $migration->markFailed();

        $slice = $this->module($this->sequence($executed))->advanceOnce($migration);

        $this->assertSame(MigrationSlice::Done, $slice);
        $this->assertSame([], $executed);
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->fresh()?->status);
    }

    public function test_a_step_missing_from_the_sequence_is_refused(): void
    {
        $migration = $this->started();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown WordPress migration step: '.WordpressMigration::STEP_RESOLVE_SOURCE);

        $this->module([])->advanceOnce($migration);
    }

    public function test_a_step_that_fails_while_a_cancel_waits_leaves_the_record_to_the_cancel(): void
    {
        $migration = $this->started();
        $failing = new class implements MigrationStep {
            public function name(): string
            {
                return WordpressMigration::STEP_RESOLVE_SOURCE;
            }

            public function run(WordpressMigration $migration): void
            {
                $migration->requestCancel();

                throw new RuntimeException('source went away');
            }
        };

        $slice = $this->module([$failing])->advanceOnce($migration);

        $stored = $migration->fresh();
        $this->assertSame(MigrationSlice::More, $slice);
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $stored?->status);
        $this->assertSame(WordpressMigration::STEP_STATUS_FAILED, $stored?->stepStatus(WordpressMigration::STEP_RESOLVE_SOURCE));
        $this->assertSame(
            ['step' => WordpressMigration::STEP_RESOLVE_SOURCE, 'status' => WordpressMigration::STEP_STATUS_FAILED, 'message' => 'source went away'],
            $stored?->step_log[0] ?? null,
        );
    }

    public function test_a_cancel_that_fails_is_logged_and_rethrown(): void
    {
        $domain = $this->project('wpcom', 'wp.com');
        Tunnel::create([
            'domain_id' => $domain->id,
            'user_id' => $domain->user_id,
            'provider' => Tunnel::PROVIDER_PANELALPHA,
            'hostname' => 'wp-com.panelalpha.online',
            'details' => ['purpose' => Tunnel::PURPOSE_PREVIEW],
        ]);
        $migration = $this->started();
        $migration->storePreviewHostname('wp-com.panelalpha.online');
        $migration->requestCancel();
        $preview = new PreviewTunnel(remove: static function (User $user, Tunnel $tunnel): void {
            throw new RuntimeException('Connect refused the removal');
        });
        $source = new SourceConnection(fn () => new RoutedSourceHttpClient);
        $module = new WordPressMigrationModule([], new NoFailureCleanup, new MigrationCancellation($source, $preview, new RecordingSiteRemover), $source);

        try {
            $module->advanceOnce($migration);
            $this->fail('a failed cancel must be rethrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Connect refused the removal', $e->getMessage());
        }

        $stored = $migration->fresh();
        $log = $stored?->step_log ?? [];
        $this->assertSame(WordpressMigration::STATUS_CANCELLING, $stored?->status);
        $this->assertSame(
            ['step' => MigrationCancellation::LOG_STEP, 'status' => WordpressMigration::STEP_STATUS_FAILED, 'message' => 'Connect refused the removal'],
            end($log),
        );
        $this->assertNull($stored?->run_id);
    }

    private function started(): WordpressMigration
    {
        return WordpressMigration::start('https://wp.com', 'admin', 'secret', MigrationTarget::newProject(null, null))->fresh()
            ?? throw new RuntimeException('the import was not stored');
    }

    /**
     * @param  list<MigrationStep>  $sequence
     */
    private function module(array $sequence): WordPressMigrationModule
    {
        $source = new SourceConnection(fn () => new RoutedSourceHttpClient);

        return new WordPressMigrationModule($sequence, new NoFailureCleanup, null, $source);
    }

    /**
     * @param  list<string>  $executed
     * @return list<MigrationStep>
     */
    private function sequence(array &$executed): array
    {
        $steps = [];
        foreach (WordPressMigrationModule::stepNames() as $name) {
            $steps[] = new RecordingStep($name, $executed, null, false);
        }

        return $steps;
    }
}
