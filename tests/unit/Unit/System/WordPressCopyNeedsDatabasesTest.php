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
use Tests\Support\LocalRoot;
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

    public function test_staging_of_a_subdirectory_wordpress_without_databases_is_422_before_creation(): void
    {
        $source = $this->classicSource();
        $source->app_path = '/home/alice/alice.example.test/public_html/blog';
        $source->save();
        $this->app->instance(System::class, $this->hostAtWordPress('/home/alice/alice.example.test/public_html/blog'));

        $response = $this->postJson("/api/projects/{$source->username}/staging", [
            'new_username' => 'alicestg',
            'domain' => 'alicestg.example.test',
            'copy_databases' => false,
        ]);

        $response->assertStatus(422);
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

        $this->assertNotSame([], (new Projects($this->host(wordPress: true)))->wordPressCopyProblems($source, false));
        $this->assertSame([], (new Projects($this->host(wordPress: true)))->wordPressCopyProblems($source, true));
        $this->assertSame([], (new Projects($this->host(wordPress: false)))->wordPressCopyProblems($source, false));
        $this->assertSame([], (new Projects($this->host(wordPress: true)))->wordPressCopyProblems($container, false));
    }

    private function classicSource(): User
    {
        $source = $this->makeUser('alice', ['mysql_prefix' => 'alice_']);
        $this->makeMainDomain($source, 'alice.example.test');

        return $source->refresh();
    }

    /** A host whose only WordPress is in the install directory given. */
    private function hostAtWordPress(string $installDir): System
    {
        return new class([$installDir.'/wp-config.php', $installDir.'/wp-includes/version.php']) extends System
        {
            /** @var list<string> */
            public array $ran = [];

            /** @param list<string> $files */
            public function __construct(private readonly array $files) {}

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = is_array($cmd) ? $cmd : [$cmd];
                if (LocalRoot::isRequest($argv) && array_slice(LocalRoot::plain($argv), 0, 2) === ['test', '-e']) {
                    return in_array(end($argv), $this->files, true) ? FakeProcess::ok() : FakeProcess::failed();
                }
                $this->ran[] = implode(' ', $argv);

                return FakeProcess::ok();
            }
        };
    }

    /** A host that has WordPress in every document root, or none; any other command is recorded. */
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
                if (LocalRoot::isRequest($argv) && array_slice(LocalRoot::plain($argv), 0, 2) === ['test', '-e']) {
                    return $this->wordPress && preg_match('~/(wp-config|wp-includes/version)\.php$~', (string) end($argv)) === 1
                        ? FakeProcess::ok()
                        : FakeProcess::failed();
                }
                $this->ran[] = implode(' ', $argv);

                return FakeProcess::ok();
            }
        };
    }
}
