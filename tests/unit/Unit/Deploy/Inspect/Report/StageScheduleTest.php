<?php

namespace Tests\Unit\Deploy\Inspect\Report;

use App\Lib\Deploy\Inspect\Report\StageSchedule;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;

/**
 * The whole schedule a matched platform declares, stage by stage.
 *
 * The three-command projection in the application report is what the
 * Dockerfile generators consume; this is everything, including the migrate
 * that only runs on an upgrade. What matters is that a reader sees the string
 * the build will actually run rather than the placeholder that produced it.
 */
class StageScheduleTest extends ReportTestCase
{
    private function nextProject(): void
    {
        $this->write('package.json', (string) json_encode([
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
            'dependencies' => ['next' => '14.2.0'],
        ]));
        $this->write('package-lock.json', '{}');
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, array<string, string>> stage => id => run
     */
    private function schedule(array $decision = [], ?AppConfig $appConfig = null): array
    {
        $stages = StageSchedule::of($this->context(), $decision, $appConfig);
        $byStage = [];
        foreach ($stages as $stage => $commands) {
            $byStage[$stage] = array_column($commands, 'run', 'id');
        }

        return $byStage;
    }

    /**
     * A manifest writes `{{js.install}}` because the install line depends on
     * the lockfile, not on the platform. The decision already knows what that
     * resolved to.
     */
    public function test_a_placeholder_is_shown_as_the_command_that_will_run(): void
    {
        $this->nextProject();

        $stages = $this->schedule([
            'install_command' => 'npm ci',
            'build_command' => 'npm run build',
            'start_command' => 'npm run start',
        ]);

        $this->assertSame('npm ci', $stages['build']['npm-install']);
        $this->assertSame('npm run build', $stages['build']['next-build']);
        $this->assertSame('npm run start', $stages['start']['serve']);
    }

    public function test_a_resolved_command_outranks_the_decisions_own_field(): void
    {
        $this->nextProject();

        $stages = $this->schedule([
            'install_command' => 'npm ci',
            'resolved_commands' => ['npm-install' => 'pnpm install --frozen-lockfile'],
        ]);

        $this->assertSame('pnpm install --frozen-lockfile', $stages['build']['npm-install']);
    }

    public function test_a_decision_that_resolved_nothing_leaves_the_manifest_text_alone(): void
    {
        $this->nextProject();

        $stages = $this->schedule();

        // Reported verbatim rather than as an invented command: the schedule
        // is a prediction, and a wrong one is worse than an unresolved one.
        $this->assertSame('{{js.install}}', $stages['build']['npm-install']);
    }

    public function test_an_empty_command_in_the_decision_is_not_treated_as_resolved(): void
    {
        $this->nextProject();

        $stages = $this->schedule(['install_command' => '   ']);

        $this->assertSame('{{js.install}}', $stages['build']['npm-install']);
    }

    public function test_a_project_no_manifest_claimed_has_no_schedule_to_show(): void
    {
        $this->write('main.go', 'package main');

        $this->assertSame([], StageSchedule::of($this->context(), []));
    }

    public function test_an_app_configs_commands_run_alongside_the_platforms_own(): void
    {
        $this->nextProject();
        $this->write('panelalpha.yaml', <<<YAML
        commands:
          - id: fetch-assets
            stage: build
            run: 'curl -sSf https://example.test/assets.tar.gz | tar xz'
        YAML);

        $stages = $this->schedule(['install_command' => 'npm ci'], AppConfig::load(
            new \App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource(),
            $this->tmpDir,
            null
        ));

        $this->assertArrayHasKey('fetch-assets', $stages['build']);
        $this->assertArrayHasKey('npm-install', $stages['build'], "the platform's own build is still there");
    }

    /**
     * `before` is the one thing an app config could not previously say. A licence
     * check or a config file the build is about to read has to run first, and
     * the schedule has to order them the way the generated script will.
     */
    public function test_a_before_command_is_scheduled_ahead_of_the_platforms(): void
    {
        $this->nextProject();
        $this->write('panelalpha.yaml', <<<YAML
        commands:
          - id: check-licence
            stage: build
            before: true
            run: './check-licence'
        YAML);

        $stages = $this->schedule([], AppConfig::load(
            new \App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource(),
            $this->tmpDir,
            null
        ));

        $this->assertSame('check-licence', array_key_first($stages['build']));
    }

    /**
     * The property the whole inspect endpoint rests on: the report predicts
     * the deploy. A plan the caller intends to send has to change what the
     * preview says, or the panel shows one thing and the container does
     * another.
     */
    public function test_a_deploy_plan_replaces_the_stage_in_the_report_too(): void
    {
        $this->nextProject();

        $plan = DeployPlan::fromArray([
            'upgrade' => [['id' => 'migrate', 'run' => 'app migrate --pretend']],
            'start' => [],
        ]);

        $stages = StageSchedule::of($this->context(), [], null, $plan);
        $byStage = [];
        foreach ($stages as $stage => $commands) {
            $byStage[$stage] = array_column($commands, 'run', 'id');
        }

        $this->assertSame(['migrate' => 'app migrate --pretend'], $byStage['upgrade']);

        // A stage emptied by the request is reported as present and empty, not
        // omitted: "nothing runs here" and "this platform has no such stage"
        // are different answers.
        $this->assertArrayHasKey('start', $byStage);
        $this->assertSame([], $byStage['start']);

        // And the build stage, which the request said nothing about, keeps the
        // platform's own commands.
        $this->assertArrayHasKey('npm-install', $byStage['build']);
    }

    public function test_a_command_says_which_of_the_three_speakers_it_came_from(): void
    {
        $this->nextProject();

        $plan = DeployPlan::fromArray(['upgrade' => [['id' => 'migrate', 'run' => 'app migrate']]]);
        $stages = StageSchedule::of($this->context(), [], null, $plan);

        $this->assertSame('request', $stages['upgrade'][0]['source']);
        $this->assertSame('manifest', $stages['build'][0]['source']);
    }

    public function test_each_command_is_described_with_the_fields_the_api_promises(): void
    {
        $this->nextProject();

        $commands = StageSchedule::of($this->context(), [])['start'];

        $this->assertSame(
            ['id', 'run', 'source', 'optional', 'serve', 'description'],
            array_keys($commands[0])
        );
        $this->assertTrue($commands[0]['serve'], 'the start stage ends in the command that serves');
        $this->assertSame('manifest', $commands[0]['source'], 'nothing overrode this one');
    }
}
