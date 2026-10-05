<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Platform\Probes\ProcfileWebProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A Procfile's worker and release lines become services beside the app,
 * started from the same image, instead of being dropped.
 */
class ProcfileProcessesTest extends TestCase
{
    public function test_every_line_but_web_is_a_process(): void
    {
        $procfile = "# Heroku\nweb: bundle exec puma -C config/puma.rb\nworker: bundle exec sidekiq\n"
            . "Release:  bundle exec rails db:migrate \nworker: ignored\nnot a line\n";

        $this->assertSame(
            ['worker' => 'bundle exec sidekiq', 'release' => 'bundle exec rails db:migrate'],
            ProcfileWebProbe::otherProcesses($procfile)
        );
    }

    public function test_no_processes_leave_the_file_byte_for_byte(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'node', 'strategy' => 'express'], 3000);

        $this->assertSame($yaml, GeneratedCompose::withProcesses($yaml, []));
    }

    public function test_a_worker_is_the_app_running_its_own_command_without_ports(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'php', 'strategy' => 'laravel', 'user' => '1000:1000'], 8000);
        $app = Yaml::parse($yaml)['services']['app'];

        $compose = Yaml::parse(GeneratedCompose::withProcesses($yaml, ['worker' => 'php artisan queue:work --tries=$TRIES']));
        $worker = $compose['services']['worker'];

        $this->assertSame($app['image'], $worker['image']);
        $this->assertSame($app['volumes'], $worker['volumes']);
        $this->assertSame($app['environment'], $worker['environment']);
        $this->assertSame('1000:1000', $worker['user']);
        $this->assertArrayNotHasKey('ports', $worker);
        $this->assertSame('unless-stopped', $worker['restart']);
        $this->assertSame(['sh', '-c'], $worker['entrypoint']);
        $this->assertSame(
            ['export PATH=/app/.venv/bin:/app/node_modules/.bin:/app/vendor/bin:$$PATH && exec php artisan queue:work --tries=$$TRIES'],
            $worker['command']
        );
        $this->assertSame('procfile-worker', $worker['labels'][GeneratedCompose::LABEL]);
        $this->assertSame($app, $compose['services']['app'], 'the app service is untouched');
    }

    public function test_release_is_a_one_shot_that_up_does_not_start(): void
    {
        $yaml = DeployCompose::railpack('project-app', 3000);

        $compose = Yaml::parse(GeneratedCompose::withProcesses($yaml, ['release' => 'npm run migrate']));

        $this->assertSame('no', $compose['services']['release']['restart']);
        $this->assertSame([GeneratedCompose::RELEASE_PROFILE], $compose['services']['release']['profiles']);
        $this->assertSame('release', GeneratedCompose::releaseService($compose));
        $this->assertNull(GeneratedCompose::releaseService(Yaml::parse($yaml)));
    }

    public function test_a_built_app_gives_each_process_its_own_cached_build(): void
    {
        $yaml = DeployCompose::dockerfile('Dockerfile', 8080, ['build_image' => 'app']);

        $worker = Yaml::parse(GeneratedCompose::withProcesses($yaml, ['worker' => 'node worker.js']))['services']['worker'];

        $this->assertSame(Yaml::parse($yaml)['services']['app']['build'], $worker['build']);
        $this->assertArrayNotHasKey('image', $worker);
    }

    public function test_a_name_the_file_already_uses_is_skipped(): void
    {
        $yaml = DeployCompose::framework(['runtime' => 'node', 'strategy' => 'express'], 3000);

        $this->assertSame($yaml, GeneratedCompose::withProcesses($yaml, ['app' => 'node other.js']));
    }

    public function test_an_app_served_by_stock_nginx_gets_no_processes(): void
    {
        $yaml = DeployCompose::staticNginx();

        $this->assertSame($yaml, GeneratedCompose::withProcesses($yaml, ['worker' => 'node worker.js']));
    }
}
