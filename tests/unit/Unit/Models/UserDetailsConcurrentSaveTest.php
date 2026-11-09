<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A deploy job saves the project it loaded when it started. What the CLI or the API wrote to its details in the
 * meantime stays: a save writes the keys it changed, onto the row as it is stored.
 */
class UserDetailsConcurrentSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useAppKey(str_repeat('k', 32));
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique();
            $table->string('domain')->unique();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_a_setting_written_while_a_job_held_the_project_survives_the_jobs_save(): void
    {
        $job = $this->project(['deployment_status' => 'running']);
        $this->elsewhere(fn (User $cli) => $cli->setDetails(['protected' => 'on']));

        $job->setDetails(['deployment_status' => 'success']);
        $job->save();

        $this->assertSame(['deployment_status' => 'success', 'protected' => 'on'], $this->stored());
        $this->assertTrue($job->isProtected());
    }

    public function test_a_key_the_saving_model_removed_is_removed_and_the_rest_stays(): void
    {
        $job = $this->project(['protected' => 'on', 'error' => 'old']);
        $this->elsewhere(fn (User $api) => $api->setDetails(['git_branch' => 'main']));

        $details = $job->getDetails();
        unset($details['protected']);
        $job->details = $details;
        $job->save();

        $this->assertSame(['error' => 'old', 'git_branch' => 'main'], $this->stored());
    }

    public function test_a_secret_encrypted_again_unchanged_does_not_undo_a_new_one(): void
    {
        $job = $this->project(['git_token' => 'old-token', 'site_git' => ['public_html' => ['branch' => 'main', 'token' => 'pat']]]);
        $this->elsewhere(fn (User $api) => $api->setDetails([
            'git_token' => 'new-token',
            'site_git' => ['public_html' => ['branch' => 'dev', 'token' => 'pat']],
        ]));

        $job->setDetails(['git_commit' => 'abc']);
        $job->save();

        $fresh = User::query()->firstOrFail();
        $this->assertSame('new-token', $fresh->getGitToken());
        $this->assertSame('dev', $fresh->getDetails()['site_git']['public_html']['branch']);
        $this->assertSame('abc', $fresh->getDetails()['git_commit']);
    }

    public function test_a_key_both_changed_is_the_later_saves(): void
    {
        $job = $this->project(['deployment_status' => 'running']);
        $this->elsewhere(fn (User $api) => $api->setDetails(['deployment_status' => 'cancelled']));

        $job->setDetails(['deployment_status' => 'failed']);
        $job->save();

        $this->assertSame(['deployment_status' => 'failed'], $this->stored());
    }

    public function test_a_plaintext_secret_is_still_encrypted_on_the_next_write(): void
    {
        DB::table('users')->insert(['username' => 'shop', 'domain' => 'shop.test', 'details' => json_encode(['git_token' => 'legacy'])]);
        $user = User::query()->firstOrFail();

        $user->setDetails(['git_commit' => 'abc']);
        $user->save();

        $raw = (string) DB::table('users')->value('details');
        $this->assertStringNotContainsString('legacy', $raw);
        $this->assertSame('legacy', User::query()->firstOrFail()->getGitToken());
    }

    public function test_a_secret_that_cannot_be_read_is_stored_empty_by_the_next_save(): void
    {
        $user = $this->project(['git_token' => 'old-token']);
        $this->useAppKey(str_repeat('x', 32));

        $user->setDetails(['git_commit' => 'abc']);
        $user->save();

        $this->assertSame(['git_token' => null, 'git_commit' => 'abc'], $this->stored());
    }

    public function test_two_writers_changing_different_site_git_paths_both_stay(): void
    {
        $job = $this->project(['site_git' => ['a' => ['branch' => 'main'], 'b' => ['branch' => 'main']]]);
        $this->elsewhere(fn (User $api) => $api->setDetails(['site_git' => ['a' => ['branch' => 'main'], 'b' => ['branch' => 'dev']]]));

        $job->setDetails(['site_git' => ['a' => ['branch' => 'feature'], 'b' => ['branch' => 'main']]]);
        $job->save();

        $this->assertSame(['a' => ['branch' => 'feature'], 'b' => ['branch' => 'dev']], $this->stored()['site_git']);
    }

    public function test_two_writers_changing_different_env_vars_both_stay_encrypted(): void
    {
        $job = $this->project(['env_vars' => ['A' => '1', 'B' => '1', 'C' => '1']]);
        $this->elsewhere(fn (User $api) => $api->setDetails(['env_vars' => ['A' => '1', 'B' => 'second', 'C' => '1', 'D' => 'added']]));

        $job->setDetails(['env_vars' => ['A' => 'first', 'B' => '1']]);
        $job->save();

        $this->assertSame(['A' => 'first', 'B' => 'second', 'D' => 'added'], User::query()->firstOrFail()->getEnvVars());
        $raw = (string) DB::table('users')->value('details');
        $this->assertStringNotContainsString('second', $raw);
        $this->assertStringNotContainsString('first', $raw);
    }

    public function test_an_env_var_both_changed_is_the_later_saves(): void
    {
        $job = $this->project(['env_vars' => ['A' => '1']]);
        $this->elsewhere(fn (User $api) => $api->setDetails(['env_vars' => ['A' => 'api']]));

        $job->setDetails(['env_vars' => ['A' => 'job']]);
        $job->save();

        $this->assertSame(['A' => 'job'], User::query()->firstOrFail()->getEnvVars());
    }

    public function test_env_vars_that_cannot_be_read_are_written_whole(): void
    {
        $this->project(['env_vars' => ['A' => '1', 'B' => '1']]);
        $this->useAppKey(str_repeat('x', 32));
        $job = User::query()->firstOrFail();

        $job->setDetails(['env_vars' => ['A' => '2']]);
        $job->save();

        $this->assertSame(['A' => '2'], User::query()->firstOrFail()->getEnvVars());
    }

    public function test_a_detail_that_is_not_a_collection_is_still_written_whole(): void
    {
        $job = $this->project(['async_status' => ['staging' => 'running', 'source' => 'api']]);
        $this->elsewhere(fn (User $api) => $api->setDetails(['async_status' => ['staging' => 'running', 'source' => 'api', 'error' => 'x']]));

        $job->setDetails(['async_status' => ['staging' => 'done', 'source' => 'api']]);
        $job->save();

        $this->assertSame(['staging' => 'done', 'source' => 'api'], $this->stored()['async_status']);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function project(array $details): User
    {
        $user = new User;
        $user->username = 'shop';
        $user->domain = 'shop.test';
        $user->details = $details;
        $user->save();

        return User::query()->firstOrFail();
    }

    /** What another process does to the same project, from its own copy. */
    private function elsewhere(callable $change): void
    {
        $other = User::query()->firstOrFail();
        $change($other);
        $other->save();
    }

    private function useAppKey(string $key): void
    {
        config(['app.key' => 'base64:'.base64_encode($key)]);
        $this->app->forgetInstance('encrypter');
        Facade::clearResolvedInstances();
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return User::query()->firstOrFail()->getDetails();
    }
}
