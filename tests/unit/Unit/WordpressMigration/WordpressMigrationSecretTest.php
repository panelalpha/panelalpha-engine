<?php

namespace Tests\Unit\WordpressMigration;

use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStep;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class WordpressMigrationSecretTest extends WordpressMigrationTestCase
{
    public function test_json_and_step_log_omit_password_and_migrator_token(): void
    {
        Queue::fake();

        $password = 'plaintext-wp-password-' . bin2hex(random_bytes(4));
        $token = 'migrator-token-' . bin2hex(random_bytes(4));

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com/blog',
            username: 'editor',
            password: $password,
        );

        // After step 3 the destination plugin step will call this; phase 1
        // exposes the method so secrets stay off the wire.
        $migration->storeMigratorToken($token);
        $migration->refresh();

        $migration->appendStepLog(
            WordpressMigration::STEP_INSTALL_DESTINATION_PLUGIN,
            WordpressMigration::STEP_STATUS_COMPLETED,
            'destination plugin installed',
        );
        $migration->refresh();

        $modelJson = $migration->toJson();
        $this->assertStringNotContainsString($password, $modelJson);
        $this->assertStringNotContainsString($token, $modelJson);

        $statusJson = json_encode($migration->toStatusArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $statusJson);
        $this->assertStringNotContainsString($token, $statusJson);
        $this->assertArrayNotHasKey('password', $migration->toStatusArray());
        $this->assertArrayNotHasKey('migrator_token', $migration->toStatusArray());

        $stepLogJson = json_encode($migration->step_log, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $stepLogJson);
        $this->assertStringNotContainsString($token, $stepLogJson);

        // Readable in-process after encrypt/decrypt.
        $this->assertSame($password, $migration->password);
        $this->assertSame($token, $migration->migrator_token);
    }

    public function test_job_payload_holds_record_id_not_plaintext_secrets(): void
    {
        Queue::fake();

        $password = 'job-payload-password-xyz';
        $token = 'job-payload-token-xyz';

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: $password,
        );
        $migration->storeMigratorToken($token);

        WordpressMigration::query()->findOrFail($migration->id);
        MigrateWordpress::dispatch($migration->id);

        Queue::assertPushed(MigrateWordpress::class, function (MigrateWordpress $job) use ($migration, $password, $token) {
            $serialized = serialize($job);

            return $job->migrationId === $migration->id
                && !str_contains($serialized, $password)
                && !str_contains($serialized, $token);
        });
    }

    public function test_failed_status_does_not_clear_secrets(): void
    {
        Queue::fake();

        $password = 'keep-on-fail-password';
        $token = 'keep-on-fail-token';

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: $password,
        );
        $migration->storeMigratorToken($token);

        try {
            $this->advance($migration, $this->hermeticSequence([
                WordpressMigration::STEP_CREATE_PROJECT => new class implements MigrationStep {
                    public function name(): string
                    {
                        return WordpressMigration::STEP_CREATE_PROJECT;
                    }

                    public function run(WordpressMigration $migration): void
                    {
                        throw new RuntimeException('simulated early failure');
                    }
                },
            ]));
            $this->fail('Expected failure');
        } catch (RuntimeException) {
            // expected
        }

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->status);
        $this->assertSame($password, $migration->password);
        $this->assertSame($token, $migration->migrator_token);
    }

    public function test_failed_remove_plugins_step_leaves_password_readable(): void
    {
        Queue::fake();

        $password = 'keep-on-step9-fail-password';
        $token = 'keep-on-step9-fail-token';

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: $password,
        );
        $migration->storeMigratorToken($token);

        // Mark steps 1–8 completed so the job lands on remove_plugins.
        $steps = $migration->steps;
        foreach ($steps as $i => $step) {
            if ($step['name'] === WordpressMigration::STEP_REMOVE_PLUGINS) {
                break;
            }
            $steps[$i]['status'] = WordpressMigration::STEP_STATUS_COMPLETED;
        }
        $migration->steps = $steps;
        $migration->save();

        try {
            $this->advance($migration, $this->hermeticSequence([
                WordpressMigration::STEP_REMOVE_PLUGINS => new class implements MigrationStep {
                    public function name(): string
                    {
                        return WordpressMigration::STEP_REMOVE_PLUGINS;
                    }

                    public function run(WordpressMigration $migration): void
                    {
                        throw new RuntimeException('simulated remove_plugins failure');
                    }
                },
            ]));
            $this->fail('Expected failure');
        } catch (RuntimeException) {
            // expected
        }

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_FAILED, $migration->status);
        $this->assertSame($password, $migration->password);
        $this->assertSame($token, $migration->migrator_token);

        $removeStep = collect($migration->steps)->firstWhere(
            'name',
            WordpressMigration::STEP_REMOVE_PLUGINS,
        );
        $this->assertSame(WordpressMigration::STEP_STATUS_FAILED, $removeStep['status']);

        $clearStep = collect($migration->steps)->firstWhere(
            'name',
            WordpressMigration::STEP_CLEAR_SECRETS,
        );
        $this->assertSame(WordpressMigration::STEP_STATUS_PENDING, $clearStep['status']);
    }

    public function test_completing_clear_secrets_step_empties_both_columns(): void
    {
        Queue::fake();

        $password = 'cleared-after-success-password';
        $token = 'cleared-after-success-token';

        $migration = WordpressMigration::start(
            sourceUrl: 'https://example.com',
            username: 'admin',
            password: $password,
        );
        $migration->storeMigratorToken($token);

        $this->advance($migration, $this->hermeticSequence());

        $migration->refresh();
        $this->assertSame(WordpressMigration::STATUS_COMPLETED, $migration->status);
        $this->assertNull($migration->password);
        $this->assertNull($migration->migrator_token);

        $last = $migration->steps[array_key_last($migration->steps)];
        $this->assertSame(WordpressMigration::STEP_CLEAR_SECRETS, $last['name']);
        $this->assertSame(WordpressMigration::STEP_STATUS_COMPLETED, $last['status']);
    }
}
