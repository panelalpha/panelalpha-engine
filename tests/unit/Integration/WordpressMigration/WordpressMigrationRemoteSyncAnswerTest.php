<?php

namespace Tests\Integration\WordpressMigration;

use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationTarget;
use App\System\Project\PhpHosting\WordPress\Migration\SourceConnection;
use App\System\Project\PhpHosting\WordPress\Migration\SourceHttpResponse;
use App\System\Project\PhpHosting\WordPress\Migration\SourceSite;
use App\System\Project\PhpHosting\WordPress\Migration\Steps\RemoteSyncStep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RoutedSourceHttpClient;

/**
 * Which remote-sync answers fail the step, with which message, and what a record must hold before it is sent.
 */
class WordpressMigrationRemoteSyncAnswerTest extends WordpressMigrationTestCase
{
    private const REMOTE_SYNC = 'https://example.com/wp-json/wp-migrator/v1/remote-sync';

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function failedAnswers(): array
    {
        return [
            'not successful, with a message' => [['success' => false, 'message' => 'refused'], 'refused'],
            'not successful, without a message' => [['success' => false], 'remote-sync was not successful'],
            'not successful, a message that is no string' => [['success' => 'yes', 'message' => ['x']], 'remote-sync was not successful'],
            'successful without a migration id' => [['success' => true], 'remote-sync did not return a migration_id'],
            'successful with an empty migration id' => [['success' => true, 'migration_id' => ''], 'remote-sync did not return a migration_id'],
            'successful with a numeric migration id' => [['success' => true, 'migration_id' => 7], 'remote-sync did not return a migration_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    #[DataProvider('failedAnswers')]
    public function test_an_answer_without_success_and_a_migration_id_fails_the_step(array $answer, string $message): void
    {
        $migration = $this->ready();
        $http = (new RoutedSourceHttpClient)->on('POST', self::REMOTE_SYNC, new SourceHttpResponse(json_encode($answer, JSON_THROW_ON_ERROR), 200));

        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
            $this->fail('The step must fail');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertNull($migration->fresh()->plugin_migration_id);
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $migration->fresh()->stepStatus(WordpressMigration::STEP_REMOTE_SYNC));
    }

    public function test_a_record_without_a_migrator_token_sends_nothing(): void
    {
        $migration = $this->ready(migratorToken: null);
        $http = new RoutedSourceHttpClient;

        $this->expectExceptionObject(new RuntimeException('Cannot remote-sync: migrator token is missing'));
        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    public function test_a_record_without_a_target_sends_nothing(): void
    {
        $migration = $this->ready(label: null);
        $http = new RoutedSourceHttpClient;

        $this->expectExceptionObject(new RuntimeException('Cannot remote-sync: target_label is missing'));
        try {
            (new RemoteSyncStep(new SourceConnection(fn () => $http)))->run($migration);
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    private function ready(?string $migratorToken = 'tok-1', ?string $label = 'examplecom-ab.panelalpha.online'): WordpressMigration
    {
        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: 'secret',
            target: MigrationTarget::newProject($label, null),
        );
        if ($migratorToken !== null) {
            $migration->storeMigratorToken($migratorToken);
        }
        $migration->storeSourceToken(self::SOURCE_TOKEN);
        $migration->storeResolvedSource('https://example.com', null, SourceSite::REST_WP_JSON);

        return $migration->fresh();
    }
}
