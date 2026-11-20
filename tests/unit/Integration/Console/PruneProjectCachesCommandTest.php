<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** `pae deploy:cache:prune` asks the root broker to prune, spares deploys in flight and reports what went. */
class PruneProjectCachesCommandTest extends TestCase
{
    use InMemoryDatabase;

    private string $dir;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->dir = sys_get_temp_dir() . '/pa-cacheprune-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/logs', 0777, true);
        $this->originalStorage = $this->app->storagePath();
        $this->app->useStoragePath($this->dir . '/storage');
        $client = $this->dir . '/pa-root';
        file_put_contents($client, "#!/bin/sh\nprintf '[%s]' \"\$@\" > " . escapeshellarg($this->dir . '/asked') . "\ncat " . escapeshellarg($this->dir . '/report') . "\nexit \$(cat " . escapeshellarg($this->dir . '/code') . ")\n");
        chmod($client, 0755);
        LocalRoot::answerWith($client);
        $this->answer('');
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->app->useStoragePath($this->originalStorage);
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function answer(string $report, int $code = 0): void
    {
        file_put_contents($this->dir . '/report', $report);
        file_put_contents($this->dir . '/code', (string) $code);
    }

    /** @return list<string> the arguments the broker was given */
    private function asked(): array
    {
        preg_match_all('/\[([^\]]*)\]/', (string) @file_get_contents($this->dir . '/asked'), $m);

        return $m[1];
    }

    /** @param array<string, mixed> $options */
    private function prune(array $options = []): array
    {
        $code = Artisan::call('deploy:cache:prune', $options);

        return [$code, Artisan::output()];
    }

    public function test_a_duration_that_is_not_one_is_refused_before_anything_runs(): void
    {
        [$code, $out] = $this->prune(['--older-than' => 'soon']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Not a duration: soon', $out);
        $this->assertSame([], $this->asked());
    }

    public function test_the_default_window_is_a_day_and_nothing_idle_is_reported_as_such(): void
    {
        [$code, $out] = $this->prune();

        $this->assertSame(0, $code);
        $this->assertSame(['--host', 'host.pruneProjectCaches', '--', '86400', ''], $this->asked());
        $this->assertStringContainsString('No project cache has been idle longer than 24h.', $out);
    }

    public function test_freed_space_is_summed_over_what_was_removed(): void
    {
        $this->answer(implode("\n", [
            "/var/cache/panelalpha/js/alpha\t3221225472\t864000\tdeleted",
            "/var/cache/panelalpha/js/bravo\t5242880\t7200\tdeleted",
            "/var/cache/panelalpha/js/charlie\t512\t600\tfailed",
            'a line the report format does not know',
        ]) . "\n");

        [$code, $out] = $this->prune(['--older-than' => '7d']);

        $this->assertSame(0, $code);
        $this->assertSame('604800', $this->asked()[3]);
        $this->assertMatchesRegularExpression('/\|\s*alpha\s*\|\s*10d ago\s*\|\s*3G\s*\|\s*deleted\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*bravo\s*\|\s*2h ago\s*\|\s*5M\s*\|\s*deleted\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*charlie\s*\|\s*10m ago\s*\|\s*512B\s*\|\s*failed\s*\|/', $out);
        $this->assertStringContainsString('Freed 3G across 2 project(s)', $out);
        $this->assertStringContainsString('1 could not be removed', $out);
    }

    public function test_a_dry_run_says_what_would_be_freed(): void
    {
        $this->answer("/var/cache/panelalpha/js/alpha\t1048576\t90000\twould delete\n");

        [$code, $out] = $this->prune(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('dry-run', $this->asked()[4]);
        $this->assertStringContainsString('Would free 1M across 1 project(s)', $out);
        $this->assertStringNotContainsString('could not be removed', $out);
    }

    public function test_a_project_deploying_now_keeps_its_cache(): void
    {
        $this->makeUser('busy');
        $this->makeUser('idle');
        $this->makeUser('done');
        DeployLogger::start('busy')->info('building');
        $finished = DeployLogger::start('done');
        $finished->finish(DeployLogger::STATUS_SUCCESS);

        [$code, $out] = $this->prune();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Skipping busy — deploy in flight', $out);
        $this->assertSame(['86400', '', 'busy'], array_slice($this->asked(), 3));
    }

    public function test_an_account_whose_name_has_no_deploy_log_is_not_a_reason_to_stop(): void
    {
        User::make(['username' => 'not/safe', 'domain' => 'odd.example.test', 'details' => []])->save();
        User::make(['username' => '', 'domain' => 'blank.example.test', 'details' => []])->save();

        [$code, $out] = $this->prune();

        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('Skipping', $out);
        $this->assertSame(['86400', ''], array_slice($this->asked(), 3));
    }

    public function test_a_broker_failure_is_reported(): void
    {
        $this->answer("cache root is not mounted\n", 3);

        [$code, $out] = $this->prune();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Could not prune project caches: cache root is not mounted', $out);
    }
}
