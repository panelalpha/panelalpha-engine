<?php

namespace Tests\Unit\Project;

use App\Lib\Project\ClassicAppType;
use App\Lib\Project\ProjectOverview;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\User;
use App\System\Privileged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** Whether a PHP hosting project holds WordPress, read in the background and returned as app.type. */
class ClassicAppTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        $this->app->forgetInstance('encrypter');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        ProjectOverview::fakeRunning([]);
    }

    protected function tearDown(): void
    {
        ProjectOverview::fakeRunning(false);
        parent::tearDown();
    }

    public function test_the_host_script_is_valid_bash_and_quotes_every_project(): void
    {
        $script = LocalRoot::script(Privileged::host()->classicAppTypes(['o_brien' => ['home' => '/home/o_brien', 'docroot' => "/home/o_brien/it's.test/public_html", 'install' => '']]));
        $path = (string) tempnam(sys_get_temp_dir(), 'apptype-');
        file_put_contents($path, $script);
        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        @unlink($path);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString("set -- 'o_brien' '/home/o_brien' '/home/o_brien/it'\\''s.test/public_html' ", $script);
    }

    /**
     * The script itself, on real directories: core files decide, the docroot beats a recorded install, a missing home is
     * skipped, and the directory holding core is reported so the engine records or clears it.
     */
    public function test_the_host_script_reads_wordpress_core_in_the_docroot_or_a_recorded_install(): void
    {
        $root = sys_get_temp_dir() . '/apptype-' . bin2hex(random_bytes(4));
        $site = static fn (string $name, string $below = ''): string => $root . '/' . $name . '/site/public_html' . $below;
        foreach ([$site('wp') . '/wp-includes', $site('wp', '/blog/wp-includes'), $site('plain'), $root . '/nodocroot',
            $site('sub', '/blog/wp-includes'), $site('stale', '/blog'), $site('docrootwins') . '/wp-includes',
            $site('docrootwins', '/blog/wp-includes')] as $dir) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($site('wp') . '/wp-includes/version.php', '<?php');
        file_put_contents($site('wp', '/blog/wp-includes/version.php'), '<?php');
        file_put_contents($site('docrootwins') . '/wp-includes/version.php', '<?php');
        file_put_contents($site('docrootwins', '/blog/wp-includes/version.php'), '<?php');
        file_put_contents($site('plain') . '/index.php', '<?php');
        file_put_contents($site('sub', '/blog/wp-includes/version.php'), '<?php');

        try {
            $script = LocalRoot::script(Privileged::host()->classicAppTypes([
                'wp' => ['home' => $root . '/wp', 'docroot' => $site('wp'), 'install' => ''],
                'plain' => ['home' => $root . '/plain', 'docroot' => $site('plain'), 'install' => ''],
                'nodocroot' => ['home' => $root . '/nodocroot', 'docroot' => $site('nodocroot'), 'install' => ''],
                'sub' => ['home' => $root . '/sub', 'docroot' => $site('sub'), 'install' => $site('sub', '/blog')],
                'stale' => ['home' => $root . '/stale', 'docroot' => $site('stale'), 'install' => $site('stale', '/blog')],
                'docrootwins' => ['home' => $root . '/docrootwins', 'docroot' => $site('docrootwins'), 'install' => $site('docrootwins', '/blog')],
                'gone' => ['home' => $root . '/gone', 'docroot' => $site('gone'), 'install' => ''],
            ]), ['homes' => $root]);
            $out = shell_exec('bash -c ' . escapeshellarg($script));
            $found = ClassicAppType::parse((string) $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        $this->assertSame([
            'wp' => ['type' => 'wordpress', 'path' => ''],
            'plain' => ['type' => 'php', 'path' => ''],
            'nodocroot' => ['type' => 'php', 'path' => ''],
            'sub' => ['type' => 'wordpress', 'path' => $site('sub', '/blog')],
            'stale' => ['type' => 'php', 'path' => ''],
            'docrootwins' => ['type' => 'wordpress', 'path' => ''],
        ], $found);
    }

    public function test_parse_keeps_only_known_types(): void
    {
        $this->assertSame(
            [
                'shop' => ['type' => 'wordpress', 'path' => ''],
                'blog' => ['type' => 'php', 'path' => '/home/blog/site/blog'],
                'legacy' => ['type' => 'wordpress', 'path' => ''],
            ],
            ClassicAppType::parse("T\tshop\twordpress\t\nT\tblog\tphp\t/home/blog/site/blog\nT\todd\tnode\t\nnoise\nT\tshort\nT\tlegacy\twordpress\n"),
        );
    }

    public function test_sweep_stores_php_hosting_projects_only_and_counts_changes(): void
    {
        $this->project('shop');
        $this->project('blog');
        $this->project('app', ['template' => 'dind']);
        $this->project('nodomain', [], withDomain: false);
        $scripts = [];
        $answer = "T\tshop\twordpress\nT\tblog\tphp\nT\tstranger\twordpress\n";
        $sweeper = new ClassicAppType(function (array $argv) use (&$scripts, &$answer): string {
            $plain = LocalRoot::plain($argv);
            $scripts[] = (string) end($plain);

            return $answer;
        });

        $this->assertSame(2, $sweeper->sweep());
        $this->assertSame(0, $sweeper->sweep(), 'nothing changed');
        $answer = "T\tshop\tphp\nT\tblog\tphp\n";
        $this->assertSame(1, $sweeper->sweep());

        $this->assertSame(['shop' => 'php', 'blog' => 'php', 'app' => null, 'nodomain' => null], $this->storedTypes());
        $this->assertStringContainsString("'shop' '/home/shop' '/home/shop/shop.example.com/public_html'", $scripts[0]);
        $this->assertStringNotContainsString("'app'", $scripts[0]);
        $this->assertStringNotContainsString("'nodomain'", $scripts[0]);
    }

    public function test_the_sweep_stores_the_install_directory_it_was_told(): void
    {
        $this->project('blog');
        $install = '/home/blog/blog.example.com/public_html/blog';
        $sweeper = new ClassicAppType(fn (array $argv): string => "T\tblog\twordpress\t{$install}\n");

        $this->assertSame(1, $sweeper->sweep());

        $this->assertSame(['blog' => 'wordpress'], $this->storedTypes());
        $this->assertSame(['blog' => $install], $this->storedPaths());
    }

    public function test_the_sweep_reads_a_recorded_install_and_clears_one_that_is_gone(): void
    {
        $this->project('blog');
        $install = '/home/blog/blog.example.com/public_html/blog';
        DB::table('users')->where('username', 'blog')->update(['app_type' => 'wordpress', 'app_path' => $install]);
        $scripts = [];
        $answer = "T\tblog\twordpress\t{$install}\n";
        $sweeper = new ClassicAppType(function (array $argv) use (&$scripts, &$answer): string {
            $plain = LocalRoot::plain($argv);
            $scripts[] = (string) end($plain);

            return $answer;
        });

        $this->assertSame(0, $sweeper->sweep(), 'nothing changed');
        $this->assertStringContainsString("'blog' '/home/blog' '/home/blog/blog.example.com/public_html' '{$install}'", $scripts[0]);

        $answer = "T\tblog\tphp\t\n";
        $this->assertSame(1, $sweeper->sweep());

        $this->assertSame(['blog' => 'php'], $this->storedTypes());
        $this->assertNull($this->storedPaths()['blog']);
    }

    public function test_recording_an_install_reads_the_type_from_that_directory(): void
    {
        $this->project('blog');
        $install = '/home/blog/blog.example.com/public_html/blog';
        $sweeper = new ClassicAppType(fn (array $argv): string => "T\tblog\twordpress\t{$install}\n");

        $sweeper->recordInstall(User::where('username', 'blog')->firstOrFail(), $install);

        $this->assertSame(['blog' => 'wordpress'], $this->storedTypes());
        $this->assertSame(['blog' => $install], $this->storedPaths());
    }

    public function test_recording_an_install_in_the_main_docroot_keeps_the_documented_rule(): void
    {
        $this->project('blog');
        $sweeper = new ClassicAppType(fn (array $argv): string => "T\tblog\twordpress\t\n");

        $sweeper->recordInstall(User::where('username', 'blog')->firstOrFail(), '/home/blog/blog.example.com/public_html');

        $this->assertSame(['blog' => 'wordpress'], $this->storedTypes());
        $this->assertSame(['blog' => null], $this->storedPaths());
    }

    public function test_an_install_in_the_main_docroot_is_recorded_as_none_whatever_its_slashes(): void
    {
        $this->project('blog', withDomain: false);
        Domain::create([
            'user_id' => User::where('username', 'blog')->firstOrFail()->id,
            'domain' => 'blog.example.com',
            'type' => 'main',
            'details' => ['document_root' => '/blog.example.com/public_html/'],
        ]);
        $sweeper = new ClassicAppType(fn (array $argv): string => '');
        $user = User::where('username', 'blog')->firstOrFail();

        $sweeper->recordInstall($user, '/home/blog/blog.example.com/public_html');
        $sweeper->recordInstall($user, '/home/blog//blog.example.com/public_html/');

        $this->assertSame(['blog' => null], $this->storedPaths());
    }

    public function test_a_recorded_install_is_stored_without_a_trailing_slash(): void
    {
        $this->project('blog');
        $sweeper = new ClassicAppType(fn (array $argv): string => '');

        $sweeper->recordInstall(User::where('username', 'blog')->firstOrFail(), '/home/blog/blog.example.com/public_html/blog/');

        $this->assertSame(['blog' => '/home/blog/blog.example.com/public_html/blog'], $this->storedPaths());
    }

    public function test_a_container_project_records_no_install_path(): void
    {
        $this->project('app', ['template' => 'dind']);
        $sweeper = new ClassicAppType(fn (array $argv): string => "T\tapp\twordpress\t/home/app/blog\n");

        $sweeper->recordInstall(User::where('username', 'app')->firstOrFail(), '/home/app/blog');

        $this->assertNull($this->storedTypes()['app']);
        $this->assertNull($this->storedPaths()['app']);
    }

    public function test_a_failed_host_run_keeps_what_was_stored(): void
    {
        $this->project('shop');
        DB::table('users')->where('username', 'shop')->update(['app_type' => 'wordpress']);
        $sweeper = new ClassicAppType(fn (array $argv): string => throw new \RuntimeException('nsenter failed'));

        $this->assertSame(0, $sweeper->sweep());
        $this->assertSame('wordpress', $this->storedTypes()['shop']);
    }

    public function test_refresh_reads_one_project(): void
    {
        $this->project('shop');
        $this->project('blog');
        $scripts = [];
        $sweeper = new ClassicAppType(function (array $argv) use (&$scripts): string {
            $plain = LocalRoot::plain($argv);
            $scripts[] = (string) end($plain);

            return "T\tshop\twordpress\n";
        });

        $sweeper->refresh(User::where('username', 'shop')->firstOrFail());

        $this->assertStringNotContainsString("'blog'", $scripts[0]);
        $this->assertSame(['shop' => 'wordpress', 'blog' => null], $this->storedTypes());
    }

    public function test_the_project_list_and_a_single_project_return_the_stored_type(): void
    {
        $this->project('shop');
        $this->project('blog');
        $this->project('fresh');
        $this->project('app', ['template' => 'dind']);
        DB::table('users')->where('username', 'shop')->update(['app_type' => 'wordpress', 'app_path' => '/home/shop/shop.example.com/public_html/blog']);
        DB::table('users')->where('username', 'blog')->update(['app_type' => 'php', 'app_path' => '/home/blog/blog.example.com/public_html/blog']);
        DB::table('users')->where('username', 'app')->update(['app_type' => 'wordpress', 'app_path' => '/home/app/blog']);
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;

        $projects = collect($this->withToken($token)->getJson('/api/projects/all')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame('wordpress', $projects['shop']['app']['type']);
        $this->assertSame('/home/shop/shop.example.com/public_html/blog', $projects['shop']['app']['path']);
        $this->assertSame('php', $projects['blog']['app']['type']);
        $this->assertNull($projects['blog']['app']['path'], 'a PHP project has no WordPress install to point at');
        $this->assertNull($projects['fresh']['app']['type'], 'not read yet');
        $this->assertNull($projects['app']['app']['type'], 'a container project is typed by its deploy, not by its files');
        $this->assertNull($projects['app']['app']['path'], 'and it has no install directory in a home');
        $this->withToken($token)->getJson('/api/projects/shop')->assertOk()->assertJsonPath('data.app.type', 'wordpress');
        $this->withToken($token)->getJson('/api/projects/shop')->assertOk()->assertJsonPath('data.app.path', '/home/shop/shop.example.com/public_html/blog');
    }

    /** @return array<string, ?string> */
    private function storedTypes(): array
    {
        return DB::table('users')->pluck('app_type', 'username')->all();
    }

    /** @return array<string, ?string> */
    private function storedPaths(): array
    {
        return DB::table('users')->pluck('app_path', 'username')->all();
    }

    /** @param array<string, mixed> $details */
    private function project(string $username, array $details = [], bool $withDomain = true): User
    {
        $user = new User();
        $user->forceFill([
            'username' => $username,
            'domain' => $username . '.example.com',
            'details' => $details,
            'status' => 'active',
        ])->save();
        if ($withDomain) {
            Domain::create(['user_id' => $user->id, 'domain' => $username . '.example.com', 'type' => 'main']);
        }

        return $user;
    }
}
