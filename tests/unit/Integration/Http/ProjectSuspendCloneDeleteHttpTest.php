<?php

namespace Tests\Integration\Http;

use App\Exceptions\ProjectBusyException;
use App\Http\Controllers\UserController;
use App\Http\Middleware\Authenticate;
use App\Lib\Project\ProjectCopier;
use App\Lib\Project\ProjectDeleter;
use App\Models\User;
use App\System;
use App\System\Services\Mysql;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingMysql;
use Tests\TestCase;

/** PUT .../suspend, POST .../clone and DELETE /projects/{project} as the API answers them, with the host stood in. */
class ProjectSuspendCloneDeleteHttpTest extends TestCase
{
    use InMemoryDatabase;

    private RecordingMysql $mysql;

    public bool $reloaded = true;

    public ?\Throwable $mysqlFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->withoutMiddleware(Authenticate::class);
        $this->mysql = new RecordingMysql();
        $test = $this;
        $this->app->instance(System::class, new class($test, $this->mysql) extends System
        {
            public function __construct(private readonly ProjectSuspendCloneDeleteHttpTest $test, private readonly RecordingMysql $recording) {}

            public function mysql(): Mysql
            {
                if ($this->test->mysqlFailure !== null) {
                    throw $this->test->mysqlFailure;
                }

                return $this->recording;
            }

            public function rebuildDomains(): bool
            {
                return $this->test->reloaded;
            }
        });
        $this->makeUser('shop');
    }

    public function test_suspending_cuts_off_an_open_phpmyadmin_session_and_rebuilds_the_vhosts(): void
    {
        $this->mysql->existingUsers = ['sso_shop'];

        $this->putJson('/api/projects/shop/suspend')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('reload_pending', false);

        $this->assertSame('suspended', User::findByUsernameOrFail('shop')->status);
        $calls = $this->mysql->recorded();
        $this->assertCount(1, $calls);
        $this->assertSame(['changeUserPassword', 'sso_shop'], array_slice($calls[0], 0, 2));
        $this->assertSame(27, strlen($calls[0][2]));
    }

    public function test_a_project_that_never_signed_in_to_phpmyadmin_has_no_password_to_rotate(): void
    {
        $this->reloaded = false;

        $this->putJson('/api/projects/shop/suspend')->assertOk()->assertJsonPath('reload_pending', true);

        $this->assertSame([], $this->mysql->recorded());
    }

    public function test_a_sites_db_that_cannot_be_reached_does_not_stop_the_suspend(): void
    {
        $this->mysqlFailure = new RuntimeException('Connection refused');
        Log::spy();

        $this->putJson('/api/projects/shop/suspend')->assertOk()->assertJsonPath('data.status', 'suspended');

        Log::shouldHaveReceived('warning')->with('Could not rotate the phpMyAdmin SSO password of shop: Connection refused')->once();
    }

    public function test_a_clone_answers_the_copy_and_any_warnings_it_left(): void
    {
        $copy = $this->makeUser('shop2');
        $this->app->instance(ProjectCopier::class, new class($copy) extends ProjectCopier
        {
            public array $asked = [];

            public function __construct(private readonly User $copy) {}

            public function cloneNow(User $source, ?string $name, ?string $domain, bool $copyDatabases): array
            {
                $this->asked = [$source->username, $name, $domain, $copyDatabases];

                return [$this->copy, $name === 'shop2' ? ['The cron jobs were not copied.'] : []];
            }
        });

        $this->postJson('/api/projects/shop/clone', ['new_username' => 'shop2', 'copy_databases' => false])
            ->assertCreated()
            ->assertJsonPath('data.username', 'shop2')
            ->assertJsonPath('warnings', ['The cron jobs were not copied.']);
        $this->assertSame(['shop', 'shop2', null, false], app(ProjectCopier::class)->asked);

        $this->postJson('/api/projects/shop/clone')
            ->assertCreated()
            ->assertJsonMissingPath('warnings');
        $this->assertSame(['shop', null, null, true], app(ProjectCopier::class)->asked);
    }

    private function deleterThrows(\Throwable $failure): void
    {
        $this->app->instance(UserController::class, new class($failure) extends UserController
        {
            public function __construct(private readonly \Throwable $failure) {}

            protected function projectDeleter(): ProjectDeleter
            {
                $failure = $this->failure;

                return new class($failure) extends ProjectDeleter
                {
                    public function __construct(private readonly \Throwable $failure) {}

                    public function delete(User $user): void
                    {
                        throw $this->failure;
                    }
                };
            }
        });
    }

    public function test_a_refused_delete_is_answered_as_a_refusal_and_not_logged(): void
    {
        $this->deleterThrows(ProjectBusyException::lockHeld('shop'));
        Log::spy();

        $this->deleteJson('/api/projects/shop')->assertStatus(409);

        Log::shouldNotHaveReceived('warning');
        $this->assertNotNull(User::findByUsername('shop'));
    }

    public function test_a_failed_delete_is_logged_and_answered_as_an_error(): void
    {
        $this->deleterThrows(new RuntimeException('container would not stop'));
        Log::spy();

        $this->deleteJson('/api/projects/shop')->assertStatus(500);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => $message === "Could not delete user 'shop': container would not stop"
        )->once();
    }

    public function test_a_delete_answers_the_project_it_removed(): void
    {
        $this->app->instance(UserController::class, new class extends UserController
        {
            protected function projectDeleter(): ProjectDeleter
            {
                return new class extends ProjectDeleter
                {
                    public function __construct() {}

                    public function delete(User $user): void
                    {
                        $user->delete();
                    }
                };
            }
        });

        $this->deleteJson('/api/projects/shop')->assertOk()->assertJsonPath('data.username', 'shop');

        $this->assertNull(User::findByUsername('shop'));
    }
}
