<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\BatchBudget;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTransfer;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\SourceWordpressClient;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Finalize decides on the migrator plugin's status, with the values the v1.1.4
 * plugin really returns: completed, not_found, the unfinished ones, backup_running.
 * One test per row of the decision table.
 */
class WordpressMigrationFinalizeStatusTest extends WordpressMigrationTestCase
{
    private const STATUS_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/status/mig-s';

    private const BATCH_URL = 'https://example.com/wp-json/wp-migrator/v1/remote-sync/process-batch';

    private const HTACCESS_URL = 'https://example.com/wp-json/wp-migrator/v1/transfer-htaccess';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unfinishedStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'in_progress' => ['in_progress'],
            'interrupted' => ['interrupted'],
            'idle' => ['idle'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function runningPhases(): array
    {
        return [
            'database' => ['backup_database'],
            'files' => ['backup_files'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unknownStatuses(): array
    {
        return [
            'running with a phase' => [
                ['status' => 'running', 'phase' => 'files'],
                'Migration was interrupted: the source reports a status this engine does not handle (status "running", phase "files", backup_running unknown, last activity unknown)',
            ],
            'no fields' => [
                [],
                'Migration was interrupted: the source reports a status this engine does not handle (status missing, phase missing, backup_running unknown, last activity unknown)',
            ],
        ];
    }

    public function test_a_completed_transfer_finishes_without_a_batch(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'completed',
                'phase' => 'completed',
                'backup_running' => false,
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        $this->assertSame(1, $http->count('POST', self::HTACCESS_URL));
        Sleep::assertNeverSlept();
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_an_unfinished_transfer_nobody_sends_goes_on_with_batches(string $state): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => $state,
                'phase' => 'ready',
                'backup_running' => false,
                'last_activity' => now()->toIso8601String(),
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 1, 3),
                $this->batch(false, 2, 3),
                $this->batch(true, 3, 3),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(3, $http->count('POST', self::BATCH_URL));
        $this->assertSame(1, $http->count('POST', self::HTACCESS_URL));
        Sleep::assertNeverSlept();
    }

    public function test_an_unfinished_transfer_stops_when_the_budget_is_spent(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'ready',
                'backup_running' => false,
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 1, 100),
                $this->batch(false, 2, 100),
            ]));

        $this->assertFalse($this->finalize($http, new BatchBudget(2)));

        $this->assertSame(2, $http->count('POST', self::BATCH_URL));
        $this->assertSame(0, $http->count('POST', self::HTACCESS_URL));
    }

    #[DataProvider('runningPhases')]
    public function test_a_backup_another_client_runs_is_waited_for(string $phase): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->inOrder([
                $this->statusReply(['status' => 'in_progress', 'phase' => $phase, 'backup_running' => true, 'last_activity' => now()->toIso8601String()]),
                $this->statusReply(['status' => 'in_progress', 'phase' => $phase, 'backup_running' => true, 'last_activity' => now()->toIso8601String()]),
                $this->statusReply(['status' => 'completed', 'phase' => 'completed', 'backup_running' => false]),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        Sleep::assertSleptTimes(2);
    }

    public function test_waiting_stops_when_the_budget_is_spent(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'backup_files',
                'backup_running' => true,
                'last_activity' => now()->toIso8601String(),
            ]));

        $this->assertFalse($this->finalize($http, new BatchBudget(2)));

        $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        Sleep::assertSleptTimes(2);
    }

    public function test_a_backup_with_no_recent_activity_fails(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 12:00:00'));
        $lastActivity = now()->subMinutes(20)->toIso8601String();
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'backup_files',
                'backup_running' => true,
                'last_activity' => $lastActivity,
            ]));

        $this->expectExceptionObject(new RuntimeException(
            'Migration was interrupted: the source has made no progress for over 15 minutes '
            .'(status "in_progress", phase "backup_files", backup_running true, last activity '.$lastActivity.')',
        ));

        try {
            $this->finalize($http);
        } finally {
            $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        }
    }

    public function test_a_migration_the_source_does_not_know_fails(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'not_found',
                'phase' => 'ready',
                'backup_running' => false,
                'last_activity' => null,
                'database' => null,
                'files' => null,
            ]));

        $this->expectExceptionObject(new RuntimeException(
            'Migration was interrupted: the source has no record of this migration '
            .'(status "not_found", phase "ready", backup_running false, last activity unknown)',
        ));

        try {
            $this->finalize($http);
        } finally {
            $this->assertSame(0, $http->count('POST', self::BATCH_URL));
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('unknownStatuses')]
    public function test_an_unknown_status_fails_with_what_the_source_said(array $fields, string $message): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply($fields));

        $this->expectExceptionObject(new RuntimeException($message));

        $this->finalize($http);
    }

    public function test_a_source_that_sends_no_new_chunks_fails(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'ready',
                'backup_running' => false,
            ]))
            ->on('POST', self::BATCH_URL, $this->batch(false, 40, 100));

        try {
            $this->finalize($http);
            $this->fail('Expected the stalled source to fail the transfer');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Migration was interrupted: the source sent no new chunks in 3 batches (phase "files", 40/100 chunks)',
                $e->getMessage(),
            );
        }

        $this->assertSame(3, $http->count('POST', self::BATCH_URL));
    }

    public function test_the_stall_count_restarts_when_sent_chunks_move(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'ready',
                'backup_running' => false,
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 40, 100),
                $this->batch(false, 40, 100),
                $this->batch(false, 41, 100),
                $this->batch(false, 41, 100),
                $this->batch(true, 42, 100),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(5, $http->count('POST', self::BATCH_URL));
    }

    public function test_a_changed_total_does_not_count_as_a_stall(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'ready',
                'backup_running' => false,
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 40, 100),
                $this->batch(false, 40, 99),
                $this->batch(false, 40, 98),
                $this->batch(true, 40, 97),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(4, $http->count('POST', self::BATCH_URL));
    }

    public function test_the_stall_guard_is_off_when_there_are_no_chunks(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->statusReply([
                'status' => 'in_progress',
                'phase' => 'ready',
                'backup_running' => false,
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 0, 0),
                $this->batch(false, 0, 0),
                $this->batch(false, 0, 0),
                $this->batch(true, 0, 0),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(4, $http->count('POST', self::BATCH_URL));
    }

    public function test_a_backup_that_ends_while_waited_for_continues_with_batches(): void
    {
        $http = (new RoutedSourceHttpClient)
            ->on('GET', self::STATUS_URL, $this->inOrder([
                $this->statusReply(['status' => 'in_progress', 'phase' => 'backup_files', 'backup_running' => true, 'last_activity' => now()->toIso8601String()]),
                $this->statusReply(['status' => 'in_progress', 'phase' => 'ready', 'backup_running' => false]),
            ]))
            ->on('POST', self::BATCH_URL, $this->inOrder([
                $this->batch(false, 1, 3),
                $this->batch(true, 3, 3),
            ]))
            ->on('POST', self::HTACCESS_URL, new SourceHttpResponse('{"success":true,"transferred":false}', 200));

        $this->assertTrue($this->finalize($http));

        $this->assertSame(2, $http->count('POST', self::BATCH_URL));
        Sleep::assertSleptTimes(1);
    }

    private function finalize(RoutedSourceHttpClient $http, ?BatchBudget $budget = null): bool
    {
        $client = new SourceWordpressClient($http);
        $client->setBatchRetrySleepSeconds(0);
        $client->useSite(new SourceSite('https://example.com'));
        $client->useSourceToken(self::SOURCE_TOKEN);

        $migration = WordpressMigration::start('https://example.com', 'admin', 'secret');

        return (new MigrationTransfer($client, static fn (): int => 200))
            ->finalize($migration, 'mig-s', 'https://example.com', $budget);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function statusReply(array $fields): SourceHttpResponse
    {
        return new SourceHttpResponse(
            json_encode(['success' => true, 'status' => $fields], JSON_THROW_ON_ERROR),
            200,
        );
    }

    /**
     * A process-batch answer. The chunk counts sit under progress, as the plugin sends them.
     */
    private function batch(bool $finalized, int $sent, int $total): SourceHttpResponse
    {
        return new SourceHttpResponse(json_encode([
            'finalized' => $finalized,
            'phase' => 'files',
            'progress' => ['percentage' => 50.0, 'sent_chunks' => $sent, 'total_chunks' => $total],
        ], JSON_THROW_ON_ERROR), 200);
    }

    /**
     * Answers in order; the last answer repeats once the list is used up.
     *
     * @param  list<SourceHttpResponse>  $answers
     * @return Closure(): SourceHttpResponse
     */
    private function inOrder(array $answers): Closure
    {
        return static function () use (&$answers): SourceHttpResponse {
            return count($answers) > 1 ? array_shift($answers) : $answers[0];
        };
    }
}
