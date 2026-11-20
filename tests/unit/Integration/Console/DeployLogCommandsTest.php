<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `project:deploy:list`, `deploy:log:prune` and `project:deploy:log` over a deploy log tree of the test's own. */
class DeployLogCommandsTest extends TestCase
{
    use InMemoryDatabase;

    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir() . '/pa-deploylogs-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs', 0777, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function deploy(string $username, string $line = 'building'): DeployLogger
    {
        $logger = DeployLogger::start($username);
        $logger->info($line);
        $logger->finish(DeployLogger::STATUS_SUCCESS);

        return $logger;
    }

    /** @param array<string, mixed> $args */
    private function console(string $command, array $args = []): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    public function test_listing_with_no_logs_names_where_it_looked(): void
    {
        [$code, $out] = $this->console('project:deploy:list');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No deploy logs under ' . $this->storage . '/logs/deploy', $out);
    }

    public function test_the_project_list_shows_each_accounts_log_count_and_size(): void
    {
        $this->deploy('tiny');
        $kilo = $this->deploy('kilo');
        file_put_contents($kilo->getLogPath(), str_repeat('k', 4096), FILE_APPEND);
        $mega = $this->deploy('mega');
        file_put_contents($mega->getLogPath(), str_repeat('m', 2 * 1024 * 1024), FILE_APPEND);
        mkdir($this->storage . '/logs/deploy/bare', 0777, true);
        file_put_contents($this->storage . '/logs/deploy/bare/20260101-000000-abcd.log', "x\n");

        [$code, $out] = $this->console('project:deploy:list');

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\|\s*tiny\s*\|\s*success\s*\|[^|]*\|[^|]*\|\s*1\s*\|\s*\d+ B\s*\|\s*\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*kilo\s*\|.*\|\s*[\d.]+ KB\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*mega\s*\|.*\|\s*2(\.\d)? MB\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*bare\s*\|\s*-\s*\|\s*-\s*\|\s*-\s*\|\s*1\s*\|\s*2 B\s*\|\s*-\s*\|\s*-\s*\|/', $out);
    }

    public function test_a_project_with_no_deploys_says_so(): void
    {
        $this->makeUser('quiet', ['template' => 'dind']);

        [$code, $out] = $this->console('project:deploy:list', ['project' => 'quiet']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("No deploy logs for project 'quiet'", $out);
    }

    public function test_prune_refuses_to_keep_nothing(): void
    {
        [$code, $out] = $this->console('deploy:log:prune', ['--keep' => 0]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--keep must be >= 1', $out);
    }

    public function test_prune_refuses_an_unsafe_project_name(): void
    {
        [$code, $out] = $this->console('deploy:log:prune', ['--project' => '../etc']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Invalid username for deploy log: ../etc', $out);
    }

    public function test_prune_with_nothing_beyond_the_keep_deletes_nothing(): void
    {
        $logger = $this->deploy('single');

        [$code, $out] = $this->console('deploy:log:prune', ['--project' => 'single', '--keep' => 1]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to prune (keep=1).', $out);
        $this->assertFileExists($logger->getLogPath());
    }

    public function test_a_dry_run_prune_of_every_account_lists_and_keeps_the_old_logs(): void
    {
        $old = $this->deploy('many', 'first');
        sleep(1);
        $this->deploy('many', 'second');

        [$code, $out] = $this->console('deploy:log:prune', ['--keep' => 1, '--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[dry-run] many: 1 file(s)', $out);
        $this->assertStringContainsString('  ' . $old->getLogPath(), $out);
        $this->assertStringContainsString('Would delete 1 file(s).', $out);
        $this->assertFileExists($old->getLogPath());
    }

    public function test_show_without_any_log_is_an_error(): void
    {
        [$code, $out] = $this->console('project:deploy:log', ['project' => 'nolog']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("No deploy log found for 'nolog' (missing latest.json)", $out);
    }

    public function test_follow_gives_up_on_a_log_that_never_appears(): void
    {
        [$code, $out] = $this->console('project:deploy:log', ['project' => 'nolog', '--follow' => true, '--timeout' => 1]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("No deploy log found for 'nolog' (missing latest.json)", $out);
    }

    public function test_show_refuses_an_unsafe_deploy_id(): void
    {
        $this->deploy('idcheck');

        [$code, $out] = $this->console('project:deploy:log', ['project' => 'idcheck', '--id' => '../latest']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Invalid deploy id: ../latest', $out);
    }

    public function test_show_of_an_unknown_deploy_id_names_the_missing_file(): void
    {
        $this->deploy('idmiss');

        [$code, $out] = $this->console('project:deploy:log', ['project' => 'idmiss', '--id' => '20200101-000000-dead', '--follow' => true, '--timeout' => 1]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Log file not found: ' . $this->storage . '/logs/deploy/idmiss/20200101-000000-dead.log', $out);
    }

    public function test_raw_show_prints_the_json_lines_as_stored(): void
    {
        $this->deploy('rawshow', 'raw entry');

        [$code, $out] = $this->console('project:deploy:log', ['project' => 'rawshow', '--raw' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"msg":"raw entry"', $out);
        $this->assertStringContainsString('Status:  success', $out);
    }

    public function test_a_line_that_is_not_json_is_printed_as_it_is(): void
    {
        $logger = $this->deploy('mixed', 'json entry');
        file_put_contents($logger->getLogPath(), "plain text from a crashed writer\n", FILE_APPEND);

        [$code, $out] = $this->console('project:deploy:log', ['project' => 'mixed']);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] info\s+json entry/', $out);
        $this->assertStringContainsString("\nplain text from a crashed writer\n", $out);
    }
}
