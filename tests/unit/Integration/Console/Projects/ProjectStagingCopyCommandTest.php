<?php

namespace Tests\Integration\Console\Projects;

use App\Console\Commands\Projects\ProjectStagingCommand;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Projects;
use App\System\Services\Mysql\DatabaseCopyResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `project:staging` past its refusals: the copy it starts, and the half-made staging project it removes when the copy fails. */
class ProjectStagingCopyCommandTest extends TestCase
{
    use InMemoryDatabase;

    public bool $hostingExists = false;

    public ?\Throwable $copyFailure = null;

    public ?\Throwable $destroyFailure = null;

    /** @var list<string> */
    public array $calls = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeMainDomain($this->makeUser('live', [], 'live.example.test'), 'live.example.test');
        $test = $this;
        $this->app->instance(System::class, new class($test) extends RecordingSystem
        {
            public function __construct(private readonly ProjectStagingCopyCommandTest $test) {}

            public function usernameHolders(string $username): array
            {
                return [];
            }

            public function projects(): Projects
            {
                $test = $this->test;

                return new class($this, $test) extends Projects
                {
                    public function __construct(System $system, private readonly ProjectStagingCopyCommandTest $test)
                    {
                        parent::__construct($system);
                    }

                    public function copy(string $sourceUsername, string $targetUsername, bool $copyDatabases = true, ?callable $log = null): ?DatabaseCopyResult
                    {
                        $this->test->calls[] = "copy {$sourceUsername} {$targetUsername}";
                        if ($this->test->copyFailure !== null) {
                            throw $this->test->copyFailure;
                        }

                        return null;
                    }
                };
            }
        });
    }

    private function staging(array $options = []): int
    {
        $test = $this;
        $command = new class($test) extends ProjectStagingCommand
        {
            public function __construct(private readonly ProjectStagingCopyCommandTest $test)
            {
                parent::__construct();
            }

            protected function project(User $user): Project
            {
                $test = $this->test;

                return new class(new RecordingSystem(), $user, $test) extends Project
                {
                    public function __construct(System $system, User $user, private readonly ProjectStagingCopyCommandTest $test)
                    {
                        parent::__construct($system, $user);
                    }

                    public function hostingExists(): bool
                    {
                        return $this->test->hostingExists;
                    }

                    public function destroy(): void
                    {
                        $this->test->calls[] = 'destroy '.$this->model()->username;
                        if ($this->test->destroyFailure !== null) {
                            throw $this->test->destroyFailure;
                        }
                    }
                };
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput(['project' => 'live'] + $options), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function test_the_staging_project_is_made_pending_and_the_live_one_copied_into_it(): void
    {
        $this->assertSame(0, $this->staging(['--new-username' => 'LiveStg', '--domain' => 'stg.example.test']));

        $this->assertSame(['copy live livestg'], $this->calls);
        $this->assertStringContainsString('livestg stg.example.test', $this->printed);
        $staging = User::findByUsernameOrFail('livestg');
        $this->assertSame('pending', $staging->status);
        $this->assertSame(User::findByUsernameOrFail('live')->id, $staging->staging);
    }

    public function test_a_name_whose_account_files_are_still_on_the_host_is_refused_with_how_to_free_it(): void
    {
        $this->hostingExists = true;

        $this->assertSame(1, $this->staging(['--new-username' => 'livestg', '--domain' => 'stg.example.test']));

        $this->assertStringContainsString('livestg', $this->printed);
        $this->assertStringContainsString('Free it with `pae project:orphan:delete livestg --force`, once `pae project:orphan:list` shows what holds it.', $this->printed);
        $this->assertSame([], $this->calls);
        $this->assertNull(User::findByUsername('livestg'));
    }

    public function test_a_failed_copy_removes_the_staging_project_it_made(): void
    {
        $this->copyFailure = new RuntimeException('rsync failed');

        $this->assertSame(1, $this->staging(['--new-username' => 'livestg', '--domain' => 'stg.example.test']));

        $this->assertSame(['copy live livestg', 'destroy livestg'], $this->calls);
        $this->assertStringContainsString('rsync failed', $this->printed);
    }

    public function test_a_cleanup_that_fails_too_is_logged_and_the_copy_failure_reported(): void
    {
        $this->copyFailure = new RuntimeException('rsync failed');
        $this->destroyFailure = new RuntimeException('container busy');
        Log::spy();

        $this->assertSame(1, $this->staging(['--new-username' => 'livestg', '--domain' => 'stg.example.test']));

        $this->assertStringContainsString('rsync failed', $this->printed);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'project:staging cleanup failed' && $context['dest'] === 'livestg'
        )->once();
    }
}
