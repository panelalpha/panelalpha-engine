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
        $script = LocalRoot::script(Privileged::host()->classicAppTypes(['o_brien' => ['home' => '/home/o_brien', 'docroot' => "/home/o_brien/it's.test/public_html"]]));
        $path = (string) tempnam(sys_get_temp_dir(), 'apptype-');
        file_put_contents($path, $script);
        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        @unlink($path);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString("set -- 'o_brien' '/home/o_brien' '/home/o_brien/it'\\''s.test/public_html' ", $script);
    }

    /** The script itself, on real directories: core files decide, a missing docroot is php, a missing home is skipped. */
    public function test_the_host_script_reads_wordpress_core_in_the_docroot(): void
    {
        $root = sys_get_temp_dir() . '/apptype-' . bin2hex(random_bytes(4));
        mkdir($root . '/wp/site/public_html/wp-includes', 0777, true);
        file_put_contents($root . '/wp/site/public_html/wp-includes/version.php', '<?php');
        mkdir($root . '/plain/site/public_html', 0777, true);
        file_put_contents($root . '/plain/site/public_html/index.php', '<?php');
        mkdir($root . '/nodocroot', 0777, true);
        mkdir($root . '/sub/site/public_html/blog/wp-includes', 0777, true);
        file_put_contents($root . '/sub/site/public_html/blog/wp-includes/version.php', '<?php');

        try {
            $script = LocalRoot::script(Privileged::host()->classicAppTypes([
                'wp' => ['home' => $root . '/wp', 'docroot' => $root . '/wp/site/public_html'],
                'plain' => ['home' => $root . '/plain', 'docroot' => $root . '/plain/site/public_html'],
                'nodocroot' => ['home' => $root . '/nodocroot', 'docroot' => $root . '/nodocroot/site/public_html'],
                'sub' => ['home' => $root . '/sub', 'docroot' => $root . '/sub/site/public_html'],
                'gone' => ['home' => $root . '/gone', 'docroot' => $root . '/gone/site/public_html'],
            ]), ['homes' => $root]);
            $out = shell_exec('bash -c ' . escapeshellarg($script));
            $types = ClassicAppType::parse((string) $out);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        $this->assertSame(['wp' => 'wordpress', 'plain' => 'php', 'nodocroot' => 'php', 'sub' => 'php'], $types);
    }

    public function test_parse_keeps_only_known_types(): void
    {
        $this->assertSame(
            ['shop' => 'wordpress', 'blog' => 'php'],
            ClassicAppType::parse("T\tshop\twordpress\nT\tblog\tphp\nT\todd\tnode\nnoise\nT\tshort\n"),
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
        DB::table('users')->where('username', 'shop')->update(['app_type' => 'wordpress']);
        DB::table('users')->where('username', 'blog')->update(['app_type' => 'php']);
        DB::table('users')->where('username', 'app')->update(['app_type' => 'wordpress']);
        $token = Admin::rootAccount()->createToken('admin', ['*'])->plainTextToken;

        $projects = collect($this->withToken($token)->getJson('/api/projects/all')->assertOk()->json('data'))->keyBy('username');

        $this->assertSame('wordpress', $projects['shop']['app']['type']);
        $this->assertSame('php', $projects['blog']['app']['type']);
        $this->assertNull($projects['fresh']['app']['type'], 'not read yet');
        $this->assertNull($projects['app']['app']['type'], 'a container project is typed by its deploy, not by its files');
        $this->withToken($token)->getJson('/api/projects/shop')->assertOk()->assertJsonPath('data.app.type', 'wordpress');
    }

    /** @return array<string, ?string> */
    private function storedTypes(): array
    {
        return DB::table('users')->pluck('app_type', 'username')->all();
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
