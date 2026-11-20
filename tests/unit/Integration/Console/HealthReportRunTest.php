<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\Telemetry\Spool;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Lib\Deploy\Telemetry\HostFacts;
use App\Models\User;
use App\System\Project\Dind\AppHealth;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\RecordingRunner;
use Tests\TestCase;

/** `pae project:health:report` sweeping accounts whose probe a stand-in root broker answers per account. */
class HealthReportRunTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-health-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/outbox', 0777, true);
        file_put_contents($this->dir . '/install-id', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $client = $this->dir . '/pa-root';
        file_put_contents($client, self::broker($this->dir));
        chmod($client, 0755);
        LocalRoot::answerWith($client);
        config([
            'telemetry.enabled' => false,
            'telemetry.spool_dir' => $this->dir . '/outbox',
            'telemetry.pin_file' => $this->dir . '/install-id',
        ]);
        Telemetry::resetCache();
        (new ReflectionProperty(Telemetry::class, 'spool'))->setValue(null, new Spool($this->dir . '/outbox', new RecordingRunner()));
        (new ReflectionProperty(HostFacts::class, 'dockerInfo'))->setValue(null, []);
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, 'nginx');
        Http::fake();
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        Telemetry::resetCache();
        (new ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, null);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A pa-root that answers an account's probe (`bash -c`) with the file named after that account. */
    private static function broker(string $dir): string
    {
        $d = escapeshellarg($dir);

        return "#!/bin/sh\n"
            . "case \" \$* \" in *\" bash -c \"*) u=\$(printf '%s' \"\$*\" | sed -n 's/^account-container\\.exec -- \\([a-z0-9]*\\) .*/\\1/p'); "
            . "[ -f {$d}/\"\$u.err\" ] && { cat {$d}/\"\$u.err\" >&2; exit 1; }; cat {$d}/\"\$u.out\"; exit 0;; esac\n"
            . "echo 'not answered' >&2\nexit 1\n";
    }

    private function account(string $username, ?string $answer, ?string $error = null, array $details = []): void
    {
        $this->makeUser($username, ['template' => 'dind', 'deploy_strategy' => 'compose', 'deploy_port' => 8000] + $details);
        if ($answer !== null) {
            file_put_contents("{$this->dir}/{$username}.out", $answer);
        }
        if ($error !== null) {
            file_put_contents("{$this->dir}/{$username}.err", $error);
        }
    }

    private function fleet(): void
    {
        $page = base64_encode('<!doctype html><html><head><title>Alpha shop</title></head><body><h1>Alpha shop</h1><p>Fresh coffee beans roasted every morning and shipped the same day.</p></body></html>');
        $this->account('alpha', "8000\thttp\t200 0.02\t\t{$page}\t/\n");
        $this->account('bravo', null, "service \"dind\" is not running\n");
        $this->account('charlie', "8000\thttp\t500 0.03\t\t\t/\n");
    }

    /** @param array<string, mixed> $options */
    private function report(array $options): array
    {
        $code = Artisan::call('project:health:report', $options + ['--attempts' => 1, '--timeout' => 1]);

        return [$code, Artisan::output()];
    }

    public function test_without_telemetry_there_is_nowhere_to_report(): void
    {
        $this->fleet();

        [$code, $out] = $this->report([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Telemetry is disabled, so there is nowhere to report. Use --local to record verdicts anyway.', $out);
        $this->assertStringNotContainsString('Swept', $out);
    }

    public function test_a_local_sweep_lists_every_project_not_serving_itself(): void
    {
        $this->fleet();

        [$code, $out] = $this->report(['--local' => true]);

        $this->assertSame(0, $code);
        $this->assertDoesNotMatchRegularExpression('/\|\s*alpha\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*bravo\s*\|\s*stopped\s*\|\s*the project\'s container is not running/', $out);
        $this->assertMatchesRegularExpression('/\|\s*charlie\s*\|\s*error_page\s*\|\s*no-server-error \(error\)\s*\|/', $out);
        $this->assertStringContainsString('Swept 3 project(s): 2 not serving themselves, not sent (--local).', $out);
        $this->assertSame([], glob($this->dir . '/outbox/*') ?: []);
    }

    public function test_the_limit_stops_the_sweep(): void
    {
        $this->fleet();

        [, $out] = $this->report(['--local' => true, '--limit' => 1, '--json' => true]);

        $this->assertSame(['swept' => 1, 'degraded' => 0, 'reported' => 0, 'projects' => []], json_decode($out, true));
    }

    public function test_one_named_project_is_swept_alone(): void
    {
        $this->fleet();

        [, $out] = $this->report(['project' => 'bravo', '--local' => true, '--json' => true]);
        $sweep = json_decode($out, true);

        $this->assertSame(1, $sweep['swept']);
        $this->assertSame(['bravo'], array_column($sweep['projects'], 'project'));
    }

    public function test_suspended_accounts_are_left_out(): void
    {
        $this->fleet();
        User::findByUsername('bravo')->forceFill(['status' => 'suspended'])->save();

        [, $out] = $this->report(['--local' => true, '--json' => true]);
        $sweep = json_decode($out, true);

        $this->assertSame(2, $sweep['swept']);
        $this->assertSame(['charlie'], array_column($sweep['projects'], 'project'));
    }

    public function test_with_telemetry_on_each_project_not_serving_itself_is_reported(): void
    {
        config(['telemetry.enabled' => true]);
        $this->fleet();

        [$code, $out] = $this->report(['--json' => true]);
        $sweep = json_decode($out, true);
        $spooled = array_column(Telemetry::spool()->pending(25, PHP_INT_MAX), 'report');

        $this->assertSame(0, $code);
        $this->assertSame(['swept' => 3, 'degraded' => 2, 'reported' => 2], array_slice($sweep, 0, 3));
        $this->assertCount(2, $spooled);
        $this->assertSame([Telemetry::KIND_HEALTH, Telemetry::KIND_HEALTH], array_column($spooled, 'kind'));
    }

    public function test_the_verdict_is_recorded_on_the_account(): void
    {
        $this->fleet();

        $this->report(['--local' => true]);

        $this->assertTrue(User::findByUsername('alpha')->getDetails()[AppHealth::DETAIL_HEALTHY] ?? null);
        $this->assertFalse(User::findByUsername('charlie')->getDetails()[AppHealth::DETAIL_HEALTHY] ?? null);
    }

    public function test_a_project_where_nothing_answered_is_listed_as_such(): void
    {
        $this->account('delta', "8000\thttp\t000 0\tConnection refused\n");

        [, $out] = $this->report(['--local' => true, '--json' => true]);

        $this->assertSame(
            [['project' => 'delta', 'serving' => 'unknown', 'checks' => 'nothing answered']],
            json_decode($out, true)['projects'],
        );
    }
}
