<?php

namespace Tests\Unit\Files;

use App\Http\Middleware\Authenticate;
use App\Lib\Helpers\FileStreamWrapper;
use App\Models\User;
use App\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A download is read by a helper started through sudo. As root it read
 * anything under the home, ~/docker included, which only root may enter. It
 * runs as the account now: what the account can read downloads, nothing else.
 *
 * Real end to end, with only `sudo` shimmed to run its command as is; setpriv
 * then really drops to uid 1001. That needs root and util-linux setpriv
 * (BusyBox's has no --reuid): in composer:2, `apk add --no-cache setpriv`.
 */
class FileDownloadAsTheAccountTest extends TestCase
{
    private const UID = 1001;

    private ?string $tmpRoot = null;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        exec('setpriv --help 2>&1', $help);
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !str_contains(implode("\n", $help), '--reuid')) {
            $this->markTestSkipped('needs root and util-linux setpriv to read as another uid');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/pa-download-as-account-' . bin2hex(random_bytes(4));
        $home = $this->tmpRoot . '/home/alice';
        mkdir($home . '/priv', 0700, true);
        mkdir($home . '/docker', 0700);
        chmod($this->tmpRoot, 0755);
        chmod($this->tmpRoot . '/home', 0755);
        chmod($home, 0755);
        file_put_contents($home . '/public.txt', 'public');
        file_put_contents($home . '/priv/secret.txt', 'secret');
        file_put_contents($home . '/docker/blob', 'root only');
        chmod($home . '/priv/secret.txt', 0600);
        chown($home . '/priv', self::UID);
        chown($home . '/priv/secret.txt', self::UID);
        mkdir($this->tmpRoot . '/bin');
        file_put_contents($this->tmpRoot . '/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
        chmod($this->tmpRoot . '/bin/sudo', 0755);
        $this->setEnv('PATH', $this->tmpRoot . '/bin:' . (string) getenv('PATH'));

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
        $user->setDetails(['template' => 'default', 'UID' => self::UID, 'GID' => self::UID]);
        $user->save();

        $this->app->instance(System::class, new class ($this->tmpRoot) extends System {
            public function __construct(private string $root)
            {
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }
        });
        $this->withoutMiddleware(Authenticate::class);
        if (in_array('sudophp', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('sudophp');
        }
        FileStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot === null) {
            // Skipped before anything was set up.
            parent::tearDown();

            return;
        }
        FileStreamWrapper::confineTo(null);
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_a_file_only_the_account_can_read_downloads(): void
    {
        $this->assertSame('secret', $this->download('priv/secret.txt'));
        $this->assertSame('public', $this->download('public.txt'));
    }

    public function test_a_file_only_root_can_read_does_not(): void
    {
        $this->assertTrue(is_readable($this->tmpRoot . '/home/alice/docker/blob'), 'root can read it');

        $this->getJson('/api/projects/alice/files/download?path=docker/blob')
            ->assertNotFound()
            ->assertJson(['message' => 'Invalid path']);

        // Behind the account's own test -f: the helper itself refuses it as the account.
        FileStreamWrapper::confineTo($this->tmpRoot . '/home/alice', ['setpriv', '--reuid', (string) self::UID, '--regid', (string) self::UID, '--clear-groups']);
        $this->assertFalse(is_file('sudophp://' . $this->tmpRoot . '/home/alice/docker/blob'));
        $this->assertFalse(@fopen('sudophp://' . $this->tmpRoot . '/home/alice/docker/blob', 'r'));
        FileStreamWrapper::confineTo($this->tmpRoot . '/home/alice', []);
        $this->assertTrue(is_file('sudophp://' . $this->tmpRoot . '/home/alice/docker/blob'), 'as root the helper reads it');
    }

    private function download(string $path): string
    {
        $response = $this->get('/api/projects/alice/files/download?path=' . $path);
        $response->assertOk();
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}
