<?php

namespace Tests\Unit\System;

use App\Exceptions\ProblemException;
use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use App\System\Projects;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

class WordPressCopyNeedsDatabasesTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware([Authenticate::class]);
    }

    public function test_staging_of_a_classic_wordpress_without_databases_is_422_before_creation(): void
    {
        $source = $this->classicSource();
        $this->app->instance(System::class, $this->host(wordPress: true));

        $response = $this->postJson("/api/projects/{$source->username}/staging", [
            'new_username' => 'alicestg',
            'domain' => 'alicestg.example.test',
            'copy_databases' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'copy_databases');
        $response->assertJsonPath('problems.0.code', 'wordpress_copy_needs_databases');
        $this->assertSame(0, User::query()->where('username', 'alicestg')->count());
    }

    public function test_clone_of_a_classic_wordpress_without_databases_is_422_before_creation(): void
    {
        $source = $this->classicSource();
        $this->app->instance(System::class, $this->host(wordPress: true));

        $response = $this->postJson("/api/projects/{$source->username}/clone", [
            'new_username' => 'aliceclone',
            'domain' => 'aliceclone.example.test',
            'copy_databases' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.code', 'wordpress_copy_needs_databases');
        $this->assertSame(0, User::query()->where('username', 'aliceclone')->count());
    }

    public function test_the_copy_itself_refuses_before_creating_anything(): void
    {
        $source = $this->classicSource();
        $dest = $this->makeUser('alicestg', ['mysql_prefix' => 'alicestg_']);
        $dest->staging = $source->id;
        $dest->status = 'pending';
        $dest->save();
        $host = $this->host(wordPress: true);

        try {
            (new Projects($host))->copy($source->username, $dest->username, copyDatabases: false);
            $this->fail('The copy went ahead without databases.');
        } catch (ProblemException $e) {
            $this->assertSame('wordpress_copy_needs_databases', $e->problems[0]['code']);
        }

        $this->assertSame([], $host->ran);
    }

    public function test_only_a_classic_wordpress_copy_without_databases_is_refused(): void
    {
        $source = $this->classicSource();
        $container = $this->makeUser('bob', ['template' => 'dind']);

        $this->assertNotSame([], (new Projects($this->host(wordPress: true)))->copyDatabasesProblems($source, false));
        $this->assertSame([], (new Projects($this->host(wordPress: true)))->copyDatabasesProblems($source, true));
        $this->assertSame([], (new Projects($this->host(wordPress: false)))->copyDatabasesProblems($source, false));
        $this->assertSame([], (new Projects($this->host(wordPress: true)))->copyDatabasesProblems($container, false));
    }

    private function classicSource(): User
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $this->makeMainDomain($source, 'alice.example.test');

        return $source->refresh();
    }

    /** A host that has wp-config.php in every document root, or none; any other command is recorded. */
    private function host(bool $wordPress): System
    {
        return new class($wordPress) extends System
        {
            /** @var list<string> */
            public array $ran = [];

            public function __construct(private readonly bool $wordPress) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                if (array_slice($argv, 0, 3) === ['sudo', 'test', '-e']) {
                    return $this->wordPress && str_ends_with((string) end($argv), '/wp-config.php')
                        ? FakeProcess::ok()
                        : FakeProcess::failed();
                }
                $this->ran[] = implode(' ', $argv);

                return FakeProcess::ok();
            }
        };
    }
}
