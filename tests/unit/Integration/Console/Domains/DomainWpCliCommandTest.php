<?php

namespace Tests\Integration\Console\Domains;

use App\Console\Commands\Domains\WpCli;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli as WordPressWpCli;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\Support\RecordingSystem;
use Tests\TestCase;

/** `domain:wp-cli`: WP-CLI runs as the domain's project, in that domain's document root unless the call names a --path, and prints all it answered. */
class DomainWpCliCommandTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<list<string>> */
    public array $runs = [];

    private string $printed = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    public function answer(array $args): array
    {
        $this->runs[] = $args;

        return ['exit_code' => 3, 'stdout' => "  6.8.1\n", 'stderr' => "Warning: no cache\n"];
    }

    private function wpCli(array $input): int
    {
        $test = $this;
        $command = new class($test) extends WpCli
        {
            public function __construct(private readonly DomainWpCliCommandTest $test)
            {
                parent::__construct();
            }

            protected function wpCli(User $user, ?string $path = null): WordPressWpCli
            {
                $test = $this->test;

                return WordPressWpCli::for($user, $path, new class($test) extends RecordingSystem
                {
                    public function __construct(private readonly DomainWpCliCommandTest $test) {}

                    public function project(User $model): Project
                    {
                        $test = $this->test;

                        return new class($this, $model, $test) extends Project
                        {
                            public function __construct(System $system, User $model, private readonly DomainWpCliCommandTest $test)
                            {
                                parent::__construct($system, $model);
                            }

                            public function homeDirPath(): string
                            {
                                return '/home/'.$this->model()->username;
                            }

                            public function runWpCli(array $args, ?string $service = null): array
                            {
                                return $this->test->answer($args);
                            }
                        };
                    }
                });
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($input), $output);
        $this->printed = $output->fetch();

        return $code;
    }

    public function test_a_classic_project_runs_in_the_domains_document_root_and_prints_every_stream(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');

        $this->assertSame(0, $this->wpCli(['domain' => 'alice.example.test', 'arg' => ['core', 'version']]));

        $this->assertSame([['core', 'version', '--path=/home/alice/alice.example.test/public_html']], $this->runs);
        $this->assertSame("stdout:\n6.8.1\nstderr:\nWarning: no cache\nexit_code: 3\n", $this->printed);
    }

    public function test_a_path_given_in_the_call_is_kept_as_it_is(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'alice.example.test');

        $this->wpCli(['domain' => 'alice.example.test', 'arg' => ['plugin', 'list', '--path=/home/alice/blog']]);

        $this->assertSame([['plugin', 'list', '--path=/home/alice/blog']], $this->runs);
    }

    public function test_an_unknown_domain_is_refused(): void
    {
        $this->assertSame(1, $this->wpCli(['domain' => 'nowhere.example.test', 'arg' => ['core', 'version']]));

        $this->assertStringContainsString('Domain `nowhere.example.test` not found in database.', $this->printed);
        $this->assertSame([], $this->runs);
    }
}
