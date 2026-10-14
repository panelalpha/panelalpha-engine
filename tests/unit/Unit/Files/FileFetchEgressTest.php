<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `files/fetch` downloads from inside core, whose network reaches the metadata
 * service and internal services. A URL that resolves there, or a public URL
 * whose redirect does, is a 422 with a `host_refused` problem, not a transfer.
 */
class FileFetchEgressTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/pa-file-fetch-guard-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot . '/home/alice/project', 0777, true);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        (require base_path('database/migrations/2014_10_12_000000_create_users_table.php'))->up();

        $user = new User();
        $user->username = 'alice';
        $user->domain = 'alice.test';
        $user->password = 'secret';
        $user->setDetails(['template' => 'default', 'UID' => 1001, 'GID' => 1001]);
        $user->save();

        $this->app->instance(System::class, $this->fakeSystem());
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function refusedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private 10/8' => ['http://10.0.0.5/x'],
            'private 192.168/16' => ['http://192.168.1.1/x'],
            'ipv6 loopback' => ['http://[::1]/'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_an_internal_url_is_refused_with_a_host_refused_problem(string $url): void
    {
        $response = $this->postJson('/api/projects/alice/files/fetch', [
            'url' => $url,
            'path' => 'project',
            'filename' => 'plugin.zip',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'url');
        $response->assertJsonPath('problems.0.code', 'host_refused');
    }

    public function test_a_public_url_redirecting_to_the_loopback_is_refused_at_the_redirect(): void
    {
        $this->app->instance(System::class, $this->fakeSystem('http://127.0.0.1/'));

        $response = $this->postJson('/api/projects/alice/files/fetch', [
            'url' => 'http://93.184.216.34/plugin.zip',
            'path' => 'project',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('problems.0.field', 'url');
        $response->assertJsonPath('problems.0.code', 'host_refused');
        $response->assertJsonPath('problems.0.message', '127.0.0.1 is an address this engine does not fetch from. '
            . 'Set EGRESS_ALLOW_PRIVATE=true to allow a private address.');
    }

    private function fakeSystem(?string $redirectTo = null): System
    {
        return new class ($this->tmpRoot, $redirectTo) extends System {
            public function __construct(private string $root, private ?string $redirectTo)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root . '/engine';
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $argv = array_slice((array) $cmd, 7);
                if (in_array('curl', $argv, true)) {
                    return $this->finished(0, $this->curlOutput($argv));
                }

                $process = new Process($argv);
                $process->run();

                return $process;
            }

            private function finished(int $code, string $output): Process
            {
                return new class ($code, $output) extends Process {
                    public function __construct(private int $code, private string $output)
                    {
                        parent::__construct(['true']);
                    }

                    public function getExitCode(): ?int
                    {
                        return $this->code;
                    }

                    public function getOutput(): string
                    {
                        return $this->output;
                    }
                };
            }

            /** @param list<string> $argv */
            private function curlOutput(array $argv): string
            {
                if (in_array('%{redirect_url}', $argv, true)) {
                    return (string) $this->redirectTo;
                }

                return $this->redirectTo === null ? '' : '302';
            }
        };
    }
}
