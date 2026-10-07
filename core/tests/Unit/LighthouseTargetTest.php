<?php

namespace Tests\Unit;

use App\Http\Middleware\Authenticate;
use App\Lib\Lighthouse\LighthouseTarget;
use App\Models\Domain;
use App\Models\Setting;
use App\System;
use App\System\ComposeProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Lighthouse endpoint made headless Chrome, which sits on the
 * engine's network, fetch any URL a caller named.
 */
class LighthouseTargetTest extends TestCase
{
    private string $root;

    /** @var object{calls: list<array<int, string>>, report: array<string, mixed>} */
    private object $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pa-lighthouse-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/data/lighthouse', 0777, true);

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        foreach ([
            '2014_10_12_000000_create_users_table.php',
            '2022_09_06_160855_create_domains_table.php',
            '2026_09_07_000000_create_tunnels_table.php',
        ] as $migration) {
            (require base_path('database/migrations/' . $migration))->up();
        }

        Setting::setRuntimeSettings(['disable-lighthouse' => null, 'default_ipv4' => '203.0.113.10']);
        Domain::create(['user_id' => 1, 'domain' => 'shop.example', 'type' => 'main', 'details' => ['aliases' => ['www.shop.example']]]);

        $this->journal = (object)['calls' => [], 'report' => []];
        $this->app->instance(System::class, $this->fakeSystem());
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        Schema::dropIfExists('tunnels');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('users');
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function internalUrls(): array
    {
        return [
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:2011/api/projects'],
            'private' => ['http://10.0.0.5/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'compose service' => ['http://core:8000/'],
            'someone else' => ['https://example.com/'],
            'userinfo' => ['http://shop.example@169.254.169.254/'],
            'backslash' => ['http://169.254.169.254\\@shop.example/'],
            'not http' => ['ftp://shop.example/'],
        ];
    }

    #[DataProvider('internalUrls')]
    public function test_a_url_off_this_engines_domains_is_refused_before_chrome_runs(string $url): void
    {
        $this->journal->report = ['finalDisplayedUrl' => $url, 'audits' => []];

        $this->postJson('/api/lighthouse/generate-report', ['url' => $url])->assertStatus(422);

        $this->assertSame([], $this->journal->calls, 'Chrome must not be started');
    }

    public function test_an_own_domain_is_pinned_and_everything_private_is_blocked(): void
    {
        $this->journal->report = ['finalDisplayedUrl' => 'https://shop.example/', 'audits' => []];

        $this->postJson('/api/lighthouse/generate-report', ['url' => 'https://shop.example/'])->assertOk();

        $rules = $this->resolverRules();
        $this->assertStringStartsWith('MAP shop.example 203.0.113.10, ', $rules);
        foreach (['MAP 169.254.* ~NOTFOUND', 'MAP 127.* ~NOTFOUND', 'MAP 10.* ~NOTFOUND', 'MAP 172.31.* ~NOTFOUND', 'MAP localhost ~NOTFOUND', 'MAP 203.0.113.10 ~NOTFOUND'] as $rule) {
            $this->assertStringContainsString($rule, $rules);
        }
    }

    public function test_without_local_resolve_the_blocks_stay_and_nothing_is_pinned(): void
    {
        $this->journal->report = ['finalDisplayedUrl' => 'https://www.shop.example/', 'audits' => []];

        $this->postJson('/api/lighthouse/generate-report', ['url' => 'https://www.shop.example/', 'no_local_resolve' => true])
            ->assertOk();

        $rules = $this->resolverRules();
        $this->assertStringNotContainsString('MAP www.shop.example', $rules);
        $this->assertStringStartsWith('MAP localhost ~NOTFOUND', $rules);
    }

    public function test_a_report_that_ended_off_this_engines_domains_is_withheld(): void
    {
        $this->journal->report = [
            'mainDocumentUrl' => 'http://core:8000/',
            'finalDisplayedUrl' => 'http://core:8000/',
            'audits' => ['final-screenshot' => ['details' => ['data' => 'data:image/jpeg;base64,SECRET']]],
        ];

        $response = $this->postJson('/api/lighthouse/generate-report', ['url' => 'https://shop.example/redirect']);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('SECRET', (string)$response->getContent());
    }

    public function test_a_hop_chrome_refused_ends_on_chrome_error_and_is_reported(): void
    {
        // What a redirect to 169.254.169.254 looks like once the rules block it
        // (as the engine's lighthouse image reports it).
        $this->journal->report = [
            'mainDocumentUrl' => 'chrome-error://chromewebdata/',
            'finalDisplayedUrl' => 'chrome-error://chromewebdata/',
            'runtimeError' => ['code' => 'CHROME_INTERSTITIAL_ERROR'],
            'audits' => [],
        ];

        $this->postJson('/api/lighthouse/generate-report', ['url' => 'https://shop.example/redirect'])->assertOk();
    }

    public function test_the_target_accepts_aliases_and_lowercases_the_host(): void
    {
        $target = new LighthouseTarget(fn (string $h): bool => $h === 'www.shop.example');

        $this->assertSame('www.shop.example', $target->hostOf('https://WWW.Shop.Example./path'));

        $this->expectException(ValidationException::class);
        $target->hostOf('https://shop.example.evil.test/');
    }

    /** A service added to the engine's compose files must be added to the block list too. */
    public function test_every_engine_service_name_is_blocked(): void
    {
        $files = glob(base_path('../docker-compose.yml*')) ?: [];
        $this->assertNotEmpty($files);

        $names = [];
        foreach ($files as $file) {
            $compose = \Symfony\Component\Yaml\Yaml::parseFile($file);
            foreach ((array)($compose['services'] ?? []) as $service => $definition) {
                $names[] = (string)$service;
                foreach (['container_name', 'hostname'] as $key) {
                    if (is_string($definition[$key] ?? null)) {
                        $names[] = $definition[$key];
                    }
                }
                $names[] = ComposeProject::container((string)$service);
            }
        }

        $this->assertContains('core', $names);
        foreach ($names as $name) {
            $this->assertTrue(LighthouseTarget::blocks($name), "{$name} is reachable from the lighthouse container");
        }
        $this->assertFalse(LighthouseTarget::blocks('shop.example'));
        $this->assertFalse(LighthouseTarget::blocks('www.google.com'));
    }

    private function resolverRules(): string
    {
        $this->assertCount(2, $this->journal->calls, 'lighthouse, then removing the report');
        $flags = '';
        foreach ($this->journal->calls[0] as $arg) {
            if (str_starts_with($arg, '--chrome-flags=')) {
                $flags = $arg;
            }
        }
        $this->assertSame(1, preg_match('/--host-resolver-rules="([^"]*)"/', $flags, $m), $flags);

        return $m[1];
    }

    private function fakeSystem(): System
    {
        return new class ($this->root, $this->journal) extends System {
            public function __construct(private string $root, private object $journal)
            {
            }

            public function engineDirPath(): string
            {
                return $this->root;
            }

            public function composeFilePath(): string
            {
                return $this->root . '/docker-compose.yml';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $cmd = (array)$cmd;
                $this->journal->calls[] = $cmd;
                // Play the lighthouse container: write the report where the
                // controller looks for it.
                $i = array_search('--output-path', $cmd, true);
                if ($i !== false) {
                    file_put_contents($this->root . '/data/lighthouse/' . basename($cmd[$i + 1]), json_encode($this->journal->report));
                }

                return '';
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \LogicException('no real process in this test');
            }
        };
    }
}
