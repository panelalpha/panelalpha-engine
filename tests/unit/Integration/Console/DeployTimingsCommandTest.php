<?php

namespace Tests\Integration\Console;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `pae project:deploy:timings` reads where a finished deploy spent its time from its log. */
class DeployTimingsCommandTest extends TestCase
{
    private string $storage;

    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = $this->app->storagePath();
        $this->storage = sys_get_temp_dir() . '/pa-timings-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/logs', 0777, true);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        exec('rm -rf ' . escapeshellarg($this->storage));
        parent::tearDown();
    }

    private function builtDeploy(string $username): void
    {
        $logger = DeployLogger::start($username);
        $logger->info('Detected project type: PHP');
        $logger->info('Starting application (docker compose up -d)');
        $logger->dim('Image project-app Building');
        $logger->dim('#8 [app 2/3] RUN composer install --no-dev --no-interaction');
        $logger->dim('#8 DONE 8.4s');
        $logger->dim('#9 [app 3/3] COPY . .');
        $logger->dim('#9 CACHED');
        $logger->dim('Image project-app Built');
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    private function staticDeploy(string $username): void
    {
        $logger = DeployLogger::start($username);
        $logger->info('Detected project type: Static');
        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    /** @param array<string, mixed> $options */
    private function timings(string $username, array $options = []): array
    {
        $code = Artisan::call('project:deploy:timings', ['project' => $username] + $options);

        return [$code, Artisan::output()];
    }

    public function test_a_project_with_no_deploy_log_is_an_error(): void
    {
        [$code, $out] = $this->timings('neverdeployed');

        $this->assertSame(1, $code);
        $this->assertStringContainsString("No deploy log for 'neverdeployed'.", $out);
    }

    public function test_a_built_deploy_shows_phases_stages_compose_and_layers(): void
    {
        $this->builtDeploy('timed');

        [$code, $out] = $this->timings('timed');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Deploy timings for timed', $out);
        $this->assertMatchesRegularExpression('/total: \d+s/', $out);
        $this->assertMatchesRegularExpression('/\|\s*Phase\s*\|\s*Seconds\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*build\s*\|\s*8\.4\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*Stage\s*\|\s*Seconds\s*\|/', $out);
        $this->assertStringContainsString('What docker compose did:', $out);
        $this->assertMatchesRegularExpression('/\|\s*Image project-app\s*\|\s*building\s*\|\s*\d+\s*\|/', $out);
        $this->assertStringContainsString('Build: 8.4s across 2 steps, 1 of 2 cacheable layers cached (0.5 hit ratio)', $out);
        $this->assertMatchesRegularExpression('/\|\s*#8\s*\|\s*8\.4\s*\|\s*\|\s*RUN composer install --no-dev --no-interaction\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*#9\s*\|\s*—\s*\|\s*yes\s*\|\s*COPY \. \.\s*\|/', $out);
        $this->assertStringNotContainsString('Every step, in order:', $out);
    }

    public function test_the_timeline_lists_every_announced_step(): void
    {
        $this->builtDeploy('tline');

        [$code, $out] = $this->timings('tline', ['--timeline' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Every step, in order:', $out);
        $this->assertMatchesRegularExpression('/\|\s*\d+s\s*\|\s*\S+\s*\|\s*Detected project type: PHP\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*\d+s\s*\|\s*—\s*\|\s*Deploy finished successfully\s*\|/', $out);
        $this->assertMatchesRegularExpression('/\|\s*#9\s*\|\s*—\s*\|\s*yes\s*\|/', $out);
    }

    public function test_a_strategy_without_an_image_says_so(): void
    {
        $this->staticDeploy('statik');

        [$code, $out] = $this->timings('statik');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No build layers — this strategy builds no image.', $out);
        $this->assertStringNotContainsString('What docker compose did:', $out);
    }

    public function test_json_prints_the_summary(): void
    {
        $this->builtDeploy('jsontimed');

        [$code, $out] = $this->timings('jsontimed', ['--json' => true]);
        $summary = json_decode($out, true);

        $this->assertSame(0, $code);
        $this->assertSame(2, $summary['build']['step_count']);
        $this->assertSame(1, $summary['build']['cached_steps']);
        $this->assertSame(0.5, $summary['build']['cache_hit_ratio']);
        $this->assertSame(['#8', '#9'], array_column($summary['build']['steps'], 'step'));
    }

    public function test_the_old_spelling_still_answers(): void
    {
        $this->builtDeploy('aliased');

        $code = Artisan::call('deploy:timings', ['project' => 'aliased', '--json' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(2, json_decode(Artisan::output(), true)['build']['step_count']);
    }
}
