<?php

namespace Tests\Integration\Console\Users;

use App\Console\Commands\Users\Attach;
use App\Models\User;
use App\System;
use App\System\Privileged;
use App\System\Project;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `project:attach`: the shell it opens, in which service, as whom and where, and what it refuses before. */
class AttachCommandTest extends TestCase
{
    use InMemoryDatabase;

    public string $projectDir = '';

    /** @var list<list<string>> */
    public array $attached = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->projectDir = sys_get_temp_dir().'/pa-attach-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir);
        file_put_contents($this->projectDir.'/docker-compose.yml', "services: {}\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/docker-compose.yml');
        @rmdir($this->projectDir);
        parent::tearDown();
    }

    private function attach(array $input): int
    {
        $test = $this;
        $command = new class($test) extends Attach
        {
            public function __construct(private readonly AttachCommandTest $test)
            {
                parent::__construct();
            }

            protected function project(User $user): Project
            {
                $test = $this->test;

                return new class(new RecordingSystem(), $user, $test) extends Project
                {
                    public function __construct(System $system, User $user, private readonly AttachCommandTest $test)
                    {
                        parent::__construct($system, $user);
                    }

                    public function projectDirPath(): string
                    {
                        return $this->test->projectDir;
                    }
                };
            }

            protected function runOnTerminal(array $cmd): int
            {
                $this->test->attached[] = $cmd;

                return 7;
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    private function compose(): string
    {
        return $this->projectDir.'/docker-compose.yml';
    }

    public function test_a_container_project_opens_bash_in_its_dind_service_as_its_own_account(): void
    {
        $this->makeUser('alice', ['template' => 'dind', 'UID' => 1001, 'GID' => 1002]);
        $home = User::findByUsername('alice')->getHomeDir();

        $this->assertSame(7, $this->attach(['project' => 'alice']));

        $this->assertSame(
            [Privileged::compose($this->compose())->execWithTty('dind', ['bash'], user: '1001:1002', workDir: "{$home}/project")],
            $this->attached
        );
        $this->assertStringContainsString("Attaching to dind for project alice (workdir: {$home}/project)...", $this->printed);
    }

    public function test_a_php_project_opens_bash_in_its_document_root_as_the_user_asked_for(): void
    {
        $user = $this->makeUser('bob', ['UID' => 1001, 'GID' => 1002]);
        $this->makeMainDomain($user, 'bob.example.test');

        $this->attach(['project' => 'bob', '--u' => 'root']);

        $workDir = $user->getHomeDir().'/bob.example.test/public_html';
        $this->assertSame([Privileged::compose($this->compose())->execWithTty('php', ['bash'], user: 'root', workDir: $workDir)], $this->attached);
    }

    public function test_an_account_without_ids_runs_as_the_container_default(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        $home = User::findByUsername('alice')->getHomeDir();

        $this->attach(['project' => 'alice']);

        $this->assertSame([Privileged::compose($this->compose())->execWithTty('dind', ['bash'], user: null, workDir: "{$home}/project")], $this->attached);
    }

    public function test_a_php_project_without_a_main_domain_is_refused(): void
    {
        $this->makeUser('bob');

        $this->assertSame(1, $this->attach(['project' => 'bob']));

        $this->assertStringContainsString('Project `bob` has no main domain.', $this->printed);
        $this->assertSame([], $this->attached);
    }

    public function test_a_project_without_a_compose_file_is_refused(): void
    {
        $this->makeUser('alice', ['template' => 'dind']);
        unlink($this->compose());

        $this->assertSame(1, $this->attach(['project' => 'alice']));

        $this->assertStringContainsString("Compose file not found: {$this->compose()}", $this->printed);
    }
}
