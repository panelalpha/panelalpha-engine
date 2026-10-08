<?php

namespace Tests\Unit\Deploy\Platform;

use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\ManifestException;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Deploy\Platform\StageScript;
use PHPUnit\Framework\TestCase;

/**
 * What a deploy request is allowed to say about the commands it wants, and
 * what the entrypoint does with it.
 *
 * The rule under test throughout: a stage the request names is replaced whole,
 * a stage it does not name keeps its defaults, and those two are told apart by
 * whether the key is present — not by whether the list has anything in it.
 */
class DeployPlanTest extends TestCase
{
    private function manifest(array $commands): PlatformManifest
    {
        return PlatformManifest::fromArray([
            'id' => 'demo',
            'label' => 'Demo',
            'priority' => 1,
            'runtime' => 'command',
            'port' => 8000,
            'detect' => ['file' => 'demo.json'],
            'commands' => $commands,
        ]);
    }

    /** The manifest every case below starts from: migrate on upgrade, plus a server. */
    private function laravelish(): PlatformManifest
    {
        return $this->manifest([
            ['id' => 'composer-install', 'stage' => 'build', 'role' => 'dependencies', 'run' => 'composer install'],
            ['id' => 'assets', 'stage' => 'build', 'run' => 'npm run build'],
            ['id' => 'key-generate', 'stage' => 'install', 'run' => 'app key:generate'],
            ['id' => 'migrate', 'stage' => ['install', 'upgrade'], 'run' => 'app migrate'],
            ['id' => 'optimize', 'stage' => 'start', 'optional' => true, 'run' => 'app optimize'],
            ['id' => 'serve', 'stage' => 'start', 'serve' => true, 'run' => 'app serve'],
        ]);
    }

    // -- the two states a stage can be in ---------------------------------

    public function test_a_stage_the_request_did_not_name_keeps_its_defaults(): void
    {
        $plan = DeployPlan::fromArray(['start' => [['run' => 'app serve', 'serve' => true]]]);

        $this->assertNotNull($plan);
        $this->assertFalse($plan->definesStage(PlatformStage::UPGRADE));

        $script = StageScript::render($this->laravelish(), null, [], [], [], null, $plan);
        $this->assertStringContainsString('app migrate', $script);
    }

    /**
     * The case the whole feature exists for: "do not migrate on this deploy".
     */
    public function test_a_stage_named_as_empty_runs_nothing(): void
    {
        $plan = DeployPlan::fromArray(['upgrade' => []]);

        $this->assertNotNull($plan);
        $this->assertTrue($plan->definesStage(PlatformStage::UPGRADE));
        $this->assertSame([], $plan->commandsFor(PlatformStage::UPGRADE));

        $script = StageScript::render($this->laravelish(), null, [], [], [], null, $plan);

        // The upgrade block still exists and still says what happened — an
        // account whose migration was skipped must not look like one whose
        // migration failed.
        $this->assertStringContainsString('if [ "$PA_PHASE" = "upgrade" ]; then', $script);
        $this->assertStringContainsString('replaced by the deploy request (no commands)', $script);

        // install still migrates: only the stage that was named was replaced.
        $this->assertSame(1, substr_count($script, 'app migrate'));
    }

    public function test_a_named_stage_replaces_the_manifests_commands_rather_than_joining_them(): void
    {
        $plan = DeployPlan::fromArray([
            'upgrade' => [['id' => 'migrate', 'run' => 'app migrate --pretend', 'timeout' => 900]],
        ]);

        $script = StageScript::render($this->laravelish(), null, [], [], [], null, $plan);

        $this->assertStringContainsString('app migrate --pretend', $script);
        $this->assertStringContainsString('timeout --foreground 900', $script);

        // The manifest's own upgrade command is gone, not run alongside.
        $upgrade = substr($script, (int) strpos($script, '"upgrade"'));
        $this->assertStringNotContainsString("app migrate\n", $upgrade);
    }

    public function test_a_replaced_install_stage_drops_the_projects_own_setup_script(): void
    {
        $plan = DeployPlan::fromArray(['install' => [['id' => 'seed', 'run' => 'app seed']]]);

        $script = StageScript::render(
            $this->laravelish(),
            null,
            [],
            ['project-setup' => 'npm run setup'],
            [],
            null,
            $plan
        );

        $this->assertStringContainsString('app seed', $script);
        $this->assertStringNotContainsString('npm run setup', $script);
    }

    /**
     * Waiting for the account's MySQL sidecar is engine knowledge, not the
     * caller's: a migration that runs before the database is reachable fails
     * for a reason that has nothing to do with the plan.
     */
    public function test_what_the_engine_prepends_survives_a_replaced_stage(): void
    {
        $plan = DeployPlan::fromArray(['upgrade' => [['id' => 'migrate', 'run' => 'app migrate']]]);

        $script = StageScript::render(
            $this->laravelish(),
            null,
            [],
            [],
            ['upgrade' => ['mysql-wait' => 'wait-for mysql']],
            null,
            $plan
        );

        // Scoped to the upgrade block: the manifest's own migrate still runs
        // in install, which was not replaced and has no sidecar wait.
        $upgrade = substr($script, (int) strpos($script, 'if [ "$PA_PHASE" = "upgrade" ]'));

        $this->assertStringContainsString('wait-for mysql', $upgrade);
        $this->assertLessThan(
            strpos($upgrade, 'app migrate'),
            strpos($upgrade, 'wait-for mysql'),
            'the wait has to come before the command that needs it'
        );
    }

    // -- the start stage ---------------------------------------------------

    public function test_a_replaced_start_stage_uses_its_own_serve_command(): void
    {
        $plan = DeployPlan::fromArray([
            'start' => [['id' => 'serve', 'run' => 'app serve --port 9000', 'serve' => true]],
        ]);

        $script = StageScript::render($this->laravelish(), null, [], [], [], null, $plan);

        $this->assertStringContainsString('exec app serve --port 9000', $script);
        $this->assertStringNotContainsString('app optimize', $script);
    }

    /**
     * Allowed, because it is the caller's call to make — but it must not exit
     * 0. A script that falls off its end reads as a clean shutdown and gets
     * restarted forever without ever saying why.
     */
    public function test_a_start_stage_with_no_serve_command_fails_loudly(): void
    {
        $plan = DeployPlan::fromArray(['start' => [['id' => 'warm', 'run' => 'app warm']]]);

        $script = StageScript::render($this->laravelish(), null, [], [], [], null, $plan);

        $this->assertStringContainsString('app warm', $script);
        $this->assertStringContainsString('there is nothing to run', $script);
        $this->assertStringContainsString('exit 1', $script);
        $this->assertStringNotContainsString('exec app serve', $script);
    }

    public function test_two_serve_commands_are_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('only one command may be the serve command');

        DeployPlan::fromArray([
            'start' => [
                ['id' => 'a', 'run' => 'app serve', 'serve' => true],
                ['id' => 'b', 'run' => 'app serve2', 'serve' => true],
            ],
        ]);
    }

    // -- the build stage runs from the Dockerfile, not the entrypoint ------

    public function test_a_replaced_build_stage_rewrites_the_decisions_command_strings(): void
    {
        $plan = DeployPlan::fromArray([
            'build' => [
                ['id' => 'deps', 'role' => 'dependencies', 'run' => 'composer install --no-dev'],
                ['id' => 'assets', 'run' => 'npm ci && npm run build'],
            ],
        ]);

        $decision = $plan?->applyToDecision([
            'install_command' => 'composer install',
            'build_command' => 'npm run build',
        ]);

        $this->assertSame('composer install --no-dev', $decision['install_command']);
        $this->assertSame('npm ci && npm run build', $decision['build_command']);
    }

    public function test_a_decision_is_untouched_when_the_plan_says_nothing_about_the_build(): void
    {
        $plan = DeployPlan::fromArray(['upgrade' => []]);
        $decision = ['install_command' => 'composer install', 'build_command' => 'npm run build'];

        $this->assertSame($decision, $plan?->applyToDecision($decision));
    }

    // -- validation --------------------------------------------------------

    public function test_an_empty_or_absent_plan_is_null_rather_than_an_empty_plan(): void
    {
        $this->assertNull(DeployPlan::fromArray(null));
        $this->assertNull(DeployPlan::fromArray([]));
    }

    public function test_an_unknown_stage_is_rejected(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'deploy' is not a stage");

        DeployPlan::fromArray(['deploy' => []]);
    }

    public function test_a_list_is_not_a_stage_map(): void
    {
        $this->expectException(ManifestException::class);

        DeployPlan::fromArray([['run' => 'app migrate']]);
    }

    public function test_a_stage_must_hold_a_list_of_commands(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage('stages.upgrade: must be a list of commands');

        DeployPlan::fromArray(['upgrade' => ['run' => 'app migrate']]);
    }

    public function test_a_command_needs_something_to_run(): void
    {
        $this->expectException(ManifestException::class);

        DeployPlan::fromArray(['upgrade' => [['id' => 'migrate']]]);
    }

    public function test_two_commands_may_not_share_an_id_within_a_stage(): void
    {
        $this->expectException(ManifestException::class);
        $this->expectExceptionMessage("'migrate' is already used");

        DeployPlan::fromArray(['upgrade' => [
            ['id' => 'migrate', 'run' => 'app migrate'],
            ['id' => 'migrate', 'run' => 'app migrate --again'],
        ]]);
    }

    public function test_bounds_are_enforced_on_input_that_arrived_over_http(): void
    {
        $cases = [
            'run length' => ['upgrade' => [['run' => str_repeat('x', DeployPlan::MAX_RUN_LENGTH + 1)]]],
            'timeout' => ['upgrade' => [['run' => 'app migrate', 'timeout' => DeployPlan::MAX_TIMEOUT + 1]]],
            'id charset' => ['upgrade' => [['id' => 'Migrate Now', 'run' => 'app migrate']]],
            'count' => ['upgrade' => array_fill(
                0,
                DeployPlan::MAX_COMMANDS_PER_STAGE + 1,
                ['run' => 'app noop']
            )],
        ];

        foreach ($cases as $label => $payload) {
            try {
                DeployPlan::fromArray($payload);
                $this->fail("{$label} should have been rejected");
            } catch (ManifestException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /**
     * A command cannot claim a stage other than the key it was listed under —
     * otherwise "these are my upgrade commands" would be a suggestion.
     */
    public function test_a_command_cannot_smuggle_itself_into_another_stage(): void
    {
        $plan = DeployPlan::fromArray([
            'upgrade' => [['id' => 'migrate', 'stage' => 'start', 'run' => 'app migrate']],
        ]);

        $this->assertTrue($plan?->commandsFor(PlatformStage::UPGRADE)[0]->runsIn(PlatformStage::UPGRADE));
        $this->assertFalse($plan?->definesStage(PlatformStage::START));
    }

    public function test_the_plan_reports_its_stages_in_deploy_order(): void
    {
        $plan = DeployPlan::fromArray(['start' => [], 'build' => [], 'upgrade' => []]);

        $this->assertSame(['build', 'upgrade', 'start'], $plan?->stages());
    }
}
