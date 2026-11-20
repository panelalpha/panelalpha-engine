<?php

namespace Tests\Integration\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** `pae project:deploy:check` against a stand-in root broker that answers the in-container probe. */
class CheckAppCommandRunTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-check-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $client = $this->dir . '/pa-root';
        file_put_contents($client, self::broker($this->dir));
        chmod($client, 0755);
        LocalRoot::answerWith($client);
        $this->probe('');
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A pa-root that logs every request and answers the probe (`bash -c`) from files the test writes. */
    private static function broker(string $dir): string
    {
        $q = static fn (string $name): string => escapeshellarg($dir . '/' . $name);

        return "#!/bin/sh\n"
            . "printf '%s\\n' \"\$*\" >> {$q('asked')}\n"
            . "case \" \$* \" in *\" bash -c \"*) cat {$q('probe')}; cat {$q('probe-err')} >&2; exit \$(cat {$q('probe-code')});; esac\n"
            . "echo 'not answered' >&2\nexit 1\n";
    }

    private function probe(string $out, string $err = '', int $code = 0): void
    {
        file_put_contents($this->dir . '/probe', $out);
        file_put_contents($this->dir . '/probe-err', $err);
        file_put_contents($this->dir . '/probe-code', (string) $code);
    }

    private function composeProject(string $username): void
    {
        $this->makeUser($username, ['template' => 'dind', 'deploy_strategy' => 'compose', 'deploy_port' => 8000]);
    }

    /** @param array<string, mixed> $options */
    private function check(string $username, array $options = []): array
    {
        $code = Artisan::call('project:deploy:check', $options + ['project' => $username, '--attempts' => 1, '--delay' => 0, '--timeout' => 1]);

        return [$code, Artisan::output()];
    }

    private function asked(): string
    {
        return (string) @file_get_contents($this->dir . '/asked');
    }

    public function test_an_unknown_project_is_refused(): void
    {
        [$code, $out] = $this->check('nosuchapp');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("No such project: 'nosuchapp'", $out);
    }

    public function test_a_project_that_is_not_a_dind_account_is_refused(): void
    {
        $this->makeUser('phpsite', ['template' => 'wordpress']);

        [$code, $out] = $this->check('phpsite');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("'phpsite' is not a dind account; there is no deployed application to check", $out);
        $this->assertStringNotContainsString('bash -c', $this->asked());
    }

    public function test_a_project_with_no_published_port_has_nothing_to_probe(): void
    {
        $this->makeUser('noport', ['template' => 'dind']);

        [$code, $out] = $this->check('noport');

        $this->assertSame(0, $code);
        $this->assertStringContainsString("'noport' publishes no application port to probe", $out);
        $this->assertStringNotContainsString('bash -c', $this->asked());
    }

    public function test_every_port_answering_is_a_healthy_table(): void
    {
        $this->composeProject('okapp');
        $this->probe("8000\thttp\t200 0.05\t\t\t/\n");

        [$code, $out] = $this->check('okapp');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\|\s*8000\s*\|\s*http\s*\|\s*ok\s*\|\s*HTTP 200\s*\|\s*0\.05s\s*\|/', $out);
        $this->assertStringContainsString("'okapp' answered on every published port", $out);
        $this->assertStringContainsString('account-container.exec -- okapp', $this->asked());
    }

    public function test_the_attempt_and_timeout_options_reach_the_probe(): void
    {
        $this->composeProject('optapp');
        $this->probe("8000\thttp\t200 0.05\t\t\t/\n");

        $this->check('optapp', ['--attempts' => 4, '--delay' => 3]);

        $this->assertStringContainsString('[ "$attempt" -ge 4 ] && break', $this->asked());
        $this->assertStringContainsString('sleep 3', $this->asked());
    }

    public function test_a_port_that_answers_nothing_fails_the_check(): void
    {
        $this->composeProject('deadapp');
        $this->probe("8000\thttp\t000 0\tConnection refused\n");

        [$code, $out] = $this->check('deadapp');

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/\|\s*8000\s*\|\s*-\s*\|\s*fail\s*\|\s*Connection refused\s*\|\s*-\s*\|/', $out);
        $this->assertStringContainsString("'deadapp' did not answer on every published port", $out);
    }

    public function test_the_engine_placeholder_answering_is_no_application(): void
    {
        $this->composeProject('emptyapp');
        $body = base64_encode('<html><head><title>PanelAlpha — Project not configured</title></head></html>');
        $this->probe("8000\thttp\t404 0.01\t\t{$body}\t/\n");

        [$code, $out] = $this->check('emptyapp');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("'emptyapp' serves the PanelAlpha placeholder page: no application is running", $out);
    }

    public function test_a_container_that_is_not_running_is_reported_as_no_check(): void
    {
        $this->composeProject('downapp');
        $this->probe('', "service \"dind\" is not running\n", 1);

        [$code, $out] = $this->check('downapp');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Could not check 'downapp': the project's container is not running, so nothing was probed", $out);
        $this->assertStringNotContainsString('answered on every published port', $out);
    }

    public function test_json_prints_the_report_and_exits_on_its_verdict(): void
    {
        $this->composeProject('jsonapp');
        $this->probe("8000\thttp\t503 0.20\t\t\t/\n");

        [$code, $out] = $this->check('jsonapp', ['--json' => true]);
        $report = json_decode($out, true);

        $this->assertSame(1, $code);
        $this->assertFalse($report['healthy']);
        $this->assertSame(8000, $report['ports'][0]['port']);
        $this->assertSame(503, $report['ports'][0]['http_code']);
    }

    public function test_json_with_nothing_to_probe_exits_0(): void
    {
        $this->makeUser('jsonnoport', ['template' => 'dind']);

        [$code, $out] = $this->check('jsonnoport', ['--json' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(['healthy' => null, 'ports' => []], json_decode($out, true));
    }
}
