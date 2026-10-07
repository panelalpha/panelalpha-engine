<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployTimings;
use PHPUnit\Framework\TestCase;

/**
 * Where a deploy spent its time, and whether the cache did anything.
 *
 * The fixtures are real BuildKit output shapes from a Matomo deploy — the one
 * that spent 123.7s compiling PHP extensions because the shared base image was
 * missing, and the same deploy once it was present.
 */
class DeployTimingsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function coldBuildLog(): array
    {
        return [
            '#10 [app 1/13] FROM docker.io/library/php:8.1-cli-bookworm',
            '#10 DONE 1.0s',
            '#12 [app 3/13] RUN apt-get update && apt-get install -y --no-install-recommends git unzip',
            '#12 DONE 22.7s',
            '#13 [app 4/13] RUN install-php-extensions bcmath gd gmp imagick intl',
            '#13 DONE 123.7s',
            '#18 [app 9/13] RUN composer install --no-dev --no-interaction --no-scripts',
            '#18 DONE 8.9s',
            '#1 [internal] load build definition from Dockerfile',
            '#1 DONE 0.1s',
        ];
    }

    public function test_it_ranks_build_steps_slowest_first(): void
    {
        $steps = DeployTimings::buildSteps($this->coldBuildLog());

        $this->assertSame('#13', $steps[0]['step']);
        $this->assertSame(123.7, $steps[0]['seconds']);
        $this->assertSame('#12', $steps[1]['step']);
        $this->assertSame('#18', $steps[2]['step']);
    }

    public function test_buildkit_bookkeeping_is_not_a_layer(): void
    {
        // Loading the build definition is not something anyone can make
        // faster, and listing it pushes a real layer off the top ten.
        $steps = DeployTimings::buildSteps($this->coldBuildLog());

        $this->assertNotContains('#1', array_column($steps, 'step'));
    }

    public function test_the_image_export_is_a_layer_too(): void
    {
        // BuildKit's export step has no `[stage x/y]` descriptor, so it used
        // to be dropped and its cost charged to the app's boot time instead.
        // Writing and unpacking 1.36GB is not bookkeeping: 19.2s of Matomo's
        // 118s compose-up window was this, invisible.
        $steps = DeployTimings::buildSteps([
            '#19 exporting to image',
            '#19 exporting layers',
            '#19 exporting layers 7.9s done',
            '#19 unpacking to docker.io/library/project-app:latest 11.2s done',
            '#19 DONE 19.2s',
        ]);

        $this->assertCount(1, $steps);
        $this->assertSame('#19', $steps[0]['step']);
        $this->assertSame(19.2, $steps[0]['seconds']);
        // The first line names the step; the rest are its own sub-progress.
        $this->assertSame('exporting to image', $steps[0]['command']);
    }

    public function test_a_cached_step_costs_nothing(): void
    {
        $steps = DeployTimings::buildSteps([
            '#13 [app 4/13] RUN install-php-extensions bcmath gd gmp intl',
            '#13 CACHED',
            '#18 [app 9/13] RUN composer install --no-dev',
            '#18 DONE 8.4s',
        ]);

        $cached = array_values(array_filter($steps, static fn (array $s): bool => $s['step'] === '#13'))[0];
        $this->assertTrue($cached['cached']);
        $this->assertSame(0.0, $cached['seconds']);
    }

    public function test_a_step_reporting_done_twice_keeps_the_larger(): void
    {
        // BuildKit re-reports a step as its children complete; the first DONE
        // is not the step's cost.
        $steps = DeployTimings::buildSteps([
            '#8 [app 2/11] RUN install-php-extensions imagick',
            '#8 DONE 1.4s',
            '#8 [app 2/11] RUN install-php-extensions imagick',
            '#8 DONE 77.5s',
        ]);

        $this->assertSame(77.5, $steps[0]['seconds']);
    }

    public function test_stage_durations_come_from_the_boundaries(): void
    {
        $stages = DeployTimings::stages(['stages' => [
            ['name' => 'preparing', 'started_at' => 100, 'finished_at' => 102],
            ['name' => 'cloning', 'started_at' => 102, 'finished_at' => 109],
            ['name' => 'running', 'started_at' => 109, 'finished_at' => null],
        ]]);

        $this->assertSame([2, 7, null], array_column($stages, 'seconds'));
        // A stage still running is not timed against now: a log is read long
        // after the fact as often as during.
        $this->assertNull($stages[2]['finished_at']);
    }

    /**
     * @return list<array{ts: int, msg: string}>
     */
    private function matomoLog(): array
    {
        // Real milestones from a Matomo deploy.
        return [
            ['ts' => 1000, 'msg' => 'Deploy started (source: git, repo: https://github.com/matomo-org/matomo)'],
            ['ts' => 1000, 'msg' => 'Starting stage: preparing'],
            ['ts' => 1002, 'msg' => "Stage 'preparing' finished"],
            ['ts' => 1002, 'msg' => 'Starting stage: cloning'],
            ['ts' => 1009, 'msg' => "Stage 'cloning' finished"],
            ['ts' => 1009, 'msg' => 'Starting stage: running'],
            ['ts' => 1009, 'msg' => 'Detected project type: PHP'],
            ['ts' => 1010, 'msg' => 'Preparing shared PHP base image panelalpha/php:8.1-cli-bookworm-pab1ff14ca'],
            ['ts' => 1049, 'msg' => 'Loaded base image composer:2 from host cache'],
            ['ts' => 1050, 'msg' => 'Starting application (docker compose up -d)'],
            ['ts' => 1050, 'msg' => '#8 [app 2/11] RUN install-php-extensions imagick'],
            ['ts' => 1128, 'msg' => '#8 DONE 77.5s'],
            ['ts' => 1128, 'msg' => '#14 [app 7/11] RUN composer install --no-dev --no-interaction'],
            ['ts' => 1137, 'msg' => '#14 DONE 8.4s'],
            ['ts' => 1168, 'msg' => 'Health check: http://127.0.0.1:8000/ answered HTTP 200 (0.65s)'],
            ['ts' => 1168, 'msg' => 'Deploy finished successfully'],
        ];
    }

    public function test_it_splits_running_into_phases_an_operator_can_act_on(): void
    {
        $phases = DeployTimings::summarize(
            ['started_at' => 1000, 'finished_at' => 1168],
            $this->matomoLog()
        )['phases'];

        $byName = array_column($phases, 'seconds', 'name');

        $this->assertSame(2.0, $byName['preparing']);
        $this->assertSame(7.0, $byName['cloning']);
        $this->assertSame(0.0, $byName['detect']);
        // First base-image line to last: pulling php, the installer, composer.
        $this->assertSame(39.0, $byName['image_transfer']);
        $this->assertSame(85.9, $byName['build']);
    }

    public function test_the_build_is_not_charged_twice(): void
    {
        // The build happens inside the compose-up window. Counting it in both
        // would make the phases sum past the deploy's own total.
        $summary = DeployTimings::summarize(
            ['started_at' => 1000, 'finished_at' => 1168],
            $this->matomoLog()
        );
        $byName = array_column($summary['phases'], 'seconds', 'name');

        // window is 1168-1050 = 118s, of which 85.9s was build.
        $this->assertSame(32.1, $byName['start_to_answer']);
        $this->assertLessThanOrEqual(
            (float) $summary['total_seconds'],
            array_sum(array_column($summary['phases'], 'seconds'))
        );
    }

    public function test_registry_pulls_are_image_transfer(): void
    {
        // The lines DindImageStore::seedCommand() prints since save|load went.
        $phases = DeployTimings::summarize(
            ['started_at' => 0, 'finished_at' => 60],
            [
                ['ts' => 0, 'msg' => 'Starting stage: running'],
                ['ts' => 1, 'msg' => 'Fetching base image node:22-bookworm-slim'],
                ['ts' => 2, 'msg' => 'Pulled base image node:22-bookworm-slim from the cache registry'],
                ['ts' => 9, 'msg' => 'Loaded base image panelalpha/php:8.2-pa1 from the host through the cache registry'],
                ['ts' => 14, 'msg' => 'Pulled base image redis:alpine'],
                ['ts' => 20, 'msg' => 'Starting application (docker compose up -d)'],
            ]
        )['phases'];

        $this->assertSame(13.0, array_column($phases, 'seconds', 'name')['image_transfer']);
    }

    public function test_a_shared_image_line_closes_the_transfer(): void
    {
        // A shared base announces itself once, after a dim "Fetching" line.
        $phases = DeployTimings::summarize(
            ['started_at' => 0, 'finished_at' => 60],
            [
                ['ts' => 0, 'msg' => 'Starting stage: running'],
                ['ts' => 1, 'level' => 'dim', 'msg' => 'Fetching base image panelalpha/php:8.4-pa1'],
                ['ts' => 9, 'msg' => 'Using shared base image panelalpha/php:8.4-pa1 (from the cache registry)'],
                ['ts' => 20, 'msg' => 'Starting application (docker compose up -d)'],
            ]
        )['phases'];

        $this->assertSame(8.0, array_column($phases, 'seconds', 'name')['image_transfer']);
    }

    public function test_phases_absent_from_a_log_are_omitted_not_zeroed(): void
    {
        // A compose deploy pulls no base images and builds nothing. Reporting
        // those as 0s would read as "instant" rather than "did not happen".
        $phases = DeployTimings::summarize(
            ['started_at' => 10, 'finished_at' => 40],
            [
                ['ts' => 10, 'msg' => 'Deploy started'],
                ['ts' => 12, 'msg' => "Stage 'preparing' finished"],
                ['ts' => 12, 'msg' => 'Starting stage: cloning'],
                ['ts' => 15, 'msg' => "Stage 'cloning' finished"],
                ['ts' => 20, 'msg' => 'Starting application (docker compose up -d)'],
                ['ts' => 40, 'msg' => 'Health check: answered HTTP 200'],
            ]
        )['phases'];

        $names = array_column($phases, 'name');
        $this->assertNotContains('image_transfer', $names);
        $this->assertNotContains('build', $names);
        $this->assertContains('start_to_answer', $names);
    }

    public function test_it_says_what_compose_actually_did(): void
    {
        // A 118s compose-up sounds like orchestration and is almost entirely
        // the image build. Real timings from the Matomo deploy.
        $compose = DeployTimings::composeSteps([
            ['ts' => 1000, 'msg' => 'Image project-app Building'],
            ['ts' => 1116, 'msg' => 'Image project-app Built'],
            ['ts' => 1116, 'msg' => 'Network project_default Creating'],
            ['ts' => 1116, 'msg' => 'Network project_default Created'],
            ['ts' => 1116, 'msg' => 'Container project-app-1 Creating'],
            ['ts' => 1116, 'msg' => 'Container project-app-1 Created'],
            ['ts' => 1116, 'msg' => 'Container project-app-1 Starting'],
            ['ts' => 1117, 'msg' => 'Container project-app-1 Started'],
        ]);

        $this->assertSame('Image project-app', $compose[0]['object']);
        $this->assertSame('building', $compose[0]['action']);
        $this->assertSame(116, $compose[0]['seconds']);
        // Orchestration itself is about a second.
        $this->assertSame(1, $compose[1]['seconds']);
        $this->assertCount(4, $compose);
    }

    public function test_an_unfinished_compose_action_is_not_reported(): void
    {
        // `Starting` with no `Started` means the container never came up.
        // Inventing a duration for it would hide the failure.
        $this->assertSame([], DeployTimings::composeSteps([
            ['ts' => 10, 'msg' => 'Container app-1 Starting'],
        ]));
    }

    public function test_an_already_running_container_is_not_an_action(): void
    {
        // Compose prints `Running` for a container it did not have to touch.
        $this->assertSame([], DeployTimings::composeSteps([
            ['ts' => 10, 'msg' => 'Container listmonk_db Running'],
            ['ts' => 10, 'msg' => 'Container listmonk_app Running'],
        ]));
    }

    public function test_the_timeline_charges_each_step_until_the_next(): void
    {
        $timeline = DeployTimings::timeline([
            ['ts' => 100, 'level' => 'info', 'msg' => 'Deploy started'],
            ['ts' => 100, 'level' => 'dim', 'msg' => '#5 0.2 some build noise'],
            ['ts' => 106, 'level' => 'info', 'msg' => 'Cloning repository'],
            ['ts' => 132, 'level' => 'info', 'msg' => 'Preparing shared PHP base image panelalpha/php:8.1'],
            ['ts' => 132, 'level' => 'ok', 'msg' => 'Deploy finished successfully'],
        ]);

        $this->assertCount(4, $timeline, 'dim lines are subprocess output, not milestones');
        $this->assertSame(['Deploy started', 'Cloning repository'], array_slice(array_column($timeline, 'step'), 0, 2));
        // Each milestone is charged the gap to the next: 6s before cloning
        // starts, 26s of cloning, 0s to the finish line.
        $this->assertSame([6, 26, 0, null], array_column($timeline, 'seconds'));
        // `at` is relative to the first milestone, so two runs compare directly.
        $this->assertSame([0, 6, 32, 32], array_column($timeline, 'at'));
    }

    public function test_the_last_step_is_charged_nothing(): void
    {
        // It closes nothing. Timing it against the deploy's end would invent a
        // duration for a line that only says the deploy stopped.
        $timeline = DeployTimings::timeline([
            ['ts' => 10, 'level' => 'info', 'msg' => 'Deploy started'],
            ['ts' => 40, 'level' => 'ok', 'msg' => 'Deploy finished successfully'],
        ]);

        $this->assertNull($timeline[1]['seconds']);
    }

    public function test_every_build_layer_is_reported_not_only_the_slowest(): void
    {
        // A layer that regressed from 0.1s to 30s never appears in a top ten
        // taken from the run before it regressed.
        $entries = [];
        foreach (range(1, 14) as $n) {
            $entries[] = ['ts' => 1, 'level' => 'dim', 'msg' => "#{$n} [app {$n}/14] RUN step{$n}"];
            $entries[] = ['ts' => 1, 'level' => 'dim', 'msg' => "#{$n} DONE {$n}.0s"];
        }

        $build = DeployTimings::summarize(['started_at' => 1, 'finished_at' => 2], $entries)['build'];

        $this->assertCount(14, $build['steps']);
        $this->assertCount(10, $build['slowest']);
        $this->assertSame(14, $build['step_count']);
    }

    public function test_the_summary_reports_cache_effectiveness(): void
    {
        $summary = DeployTimings::summarize(
            ['started_at' => 1000, 'finished_at' => 1168, 'stages' => [
                ['name' => 'cloning', 'started_at' => 1000, 'finished_at' => 1007],
            ]],
            array_map(static fn (string $m): array => ['ts' => 1, 'msg' => $m], [
                '#12 [app 3/13] RUN apt-get update',
                '#12 CACHED',
                '#13 [app 4/13] RUN install-php-extensions bcmath gd',
                '#13 CACHED',
                '#8 [app 2/11] RUN install-php-extensions imagick',
                '#8 DONE 77.5s',
                '#14 [app 7/11] RUN composer install',
                '#14 DONE 8.4s',
            ])
        );

        $this->assertSame(168, $summary['total_seconds']);
        $this->assertSame(7, $summary['stages'][0]['seconds']);
        $this->assertSame(4, $summary['build']['step_count']);
        $this->assertSame(2, $summary['build']['cached_steps']);
        $this->assertSame(0.5, $summary['build']['cache_hit_ratio']);
        // Cached steps contribute nothing to the build total.
        $this->assertSame(85.9, $summary['build']['total_seconds']);
        $this->assertSame('#8', $summary['build']['slowest'][0]['step']);
    }

    public function test_a_deploy_with_no_build_reports_no_ratio(): void
    {
        // A compose or static deploy builds nothing. Reporting a 0.0 hit ratio
        // there would read as a cache failure rather than an absent build.
        $summary = DeployTimings::summarize(['started_at' => 10, 'finished_at' => 18], []);

        $this->assertSame(8, $summary['total_seconds']);
        $this->assertSame(0, $summary['build']['step_count']);
        $this->assertNull($summary['build']['cache_hit_ratio']);
    }

    public function test_a_running_deploy_has_no_total(): void
    {
        $this->assertNull(DeployTimings::summarize(['started_at' => 10])['total_seconds']);
    }
}
